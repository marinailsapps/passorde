<?php

namespace App\Integrations\Sync\Push;

use App\Domain\Catalog\NewArticles;
use App\Models\ArticleRequest;
use App\Models\Client;
use App\Models\Order;
use App\Models\OrderLine;
use Closure;
use Passorde\Drafts\ArticleDraft;
use Passorde\Drafts\ClientDraft;
use Passorde\Drafts\ClientPatch;
use Passorde\Drafts\ContactDraft;
use Passorde\Drafts\DeliveryDraft;
use Passorde\Drafts\DeliveryLineDraft;
use Passorde\Records\ContactRecord;

/**
 * What the app sends to Evoliz, as Passorde drafts: the installation's choices (the
 * payment term, the comment, the free-line suffix, Paris dates, the category's
 * classification). The request bodies are pinned field by field by
 * tests/Feature/Push/OrderPushTest.php, Clients/ClientChangeTest.php,
 * Clients/ClientWithoutOrderTest.php and Catalog/NewArticleTest.php.
 */
final class EvolizDrafts
{
    /**
     * POST /clients (vat_number "NC" and type "Professionnel" are Passorde's defaults).
     * The stored evoliz_code goes first: an imported client, or an earlier push.
     * An empty street, postcode or town is left out.
     */
    public static function client(Client $client): ClientDraft
    {
        $given = fn (?string $value): ?string => $value === null || trim($value) === '' ? null : $value;

        return new ClientDraft(
            name: $client->company_name,
            addressLine: $given($client->address_line),
            postcode: $given($client->postcode),
            town: $given($client->town),
            phone: $client->phone,
            code: $client->evoliz_code,
        );
    }

    /**
     * The contact of a client created by the app: lastname is required, the
     * favourite needs an email. Null when there is nothing worth a contact.
     */
    public static function contact(Client $client): ?ContactDraft
    {
        if ($client->contact_name === null && $client->email === null) {
            return null;
        }

        return new ContactDraft(lastName: $client->contact_name ?? $client->company_name, email: $client->email);
    }

    /**
     * PATCH /clients/{id} for a client correction: only the corrected fields. A
     * new address line replaces all three Evoliz lines, an empty one clears
     * them (addr, addr2, addr3 null); null clears a phone.
     * Null when the correction has no client field.
     *
     * @param  array<string, string|null>  $values  field => new value, from ClientChange::newValues()
     */
    public static function clientPatch(array $values): ?ClientPatch
    {
        $given = fn (string $field): ?string => array_key_exists($field, $values) ? (string) $values[$field] : null;

        if (! array_intersect_key($values, array_flip(['address_line', 'postcode', 'town', 'phone', 'mobile']))) {
            return null;
        }

        $street = $given('address_line');

        return new ClientPatch(
            addressLine: $street,
            // An empty text clears: the other two Evoliz lines go with a new street.
            addressLine2: $street !== null ? '' : null,
            addressLine3: $street !== null ? '' : null,
            postcode: $given('postcode'),
            town: $given('town'),
            phone: $given('phone'),
            mobile: $given('mobile'),
        );
    }

    /**
     * The contact half of a client correction: the whole name in lastname (the
     * app shows "firstname lastname" as one), the email. Null when the
     * correction has no contact field.
     *
     * Passorde picks the contact (the one with the new email, else the primary
     * one) or creates it. An email-only correction sends the email alone; when
     * the client has no contact yet, contactToCreate() gives the draft that
     * creates one. A rename alone reads the primary contact first ($primary),
     * so that a contact created for it still gets the client's email.
     *
     * @param  array<string, string|null>  $values
     * @param  Closure(): ?ContactRecord  $primary
     */
    public static function contactPatch(array $values, Client $client, Closure $primary): ?ContactDraft
    {
        $renames = array_key_exists('contact_name', $values);
        $emails = array_key_exists('email', $values);

        return match (true) {
            $renames && $emails => new ContactDraft(lastName: (string) $values['contact_name'], firstName: '', email: (string) $values['email']),
            $emails => new ContactDraft(email: (string) $values['email']),
            ! $renames => null,
            default => ($contact = $primary()) === null
                ? new ContactDraft(lastName: $values['contact_name'] ?? $client->contact_name ?? $client->company_name, email: $client->email)
                : new ContactDraft(lastName: (string) $values['contact_name'], firstName: '', contactId: $contact->id),
        };
    }

    /**
     * An email-only correction for a client without a contact in Evoliz: the
     * contact is created with the client's current name.
     */
    public static function contactToCreate(ContactDraft $draft, Client $client): ContactDraft
    {
        return new ContactDraft(lastName: $client->contact_name ?? $client->company_name, email: $draft->email);
    }

    /**
     * POST /articles for an article added from an order: stocked, starting at 0.
     * The price excludes VAT.
     */
    public static function article(ArticleRequest $request): ArticleDraft
    {
        return new ArticleDraft(
            reference: $request->reference,
            designation: $request->designation,
            unitPriceHt: (float) $request->unit_price_ht,
            vatRate: NewArticles::vatRate(),
            classificationId: NewArticles::classificationFor($request->category),
            stockManaged: true,
            stockQty: 0,
        );
    }

    /**
     * POST /deliveries. Every item references the Evoliz article (VAT and
     * classification come from it) and always carries the sales person's
     * unit price. Free lines add a designation: the article's full current
     * designation exactly as Evoliz stores it (HTML entities included, so it
     * reads like the operator's lines) followed by free_lines.evoliz_suffix of
     * the profile (config/passorde.php). A line
     * comment is added under the designation, the way the operator writes it
     * in Evoliz; the override replaces the whole text, so the article's text
     * always goes with it. Other paid lines never send a designation, and no
     * line sends a VAT rate. The payment term, recovery_indemnity (the
     * company's default is not applied to API BLs, the Evoliz connector notes in Passorde Connect) and the
     * comment come from the profile's evoliz section.
     */
    public static function delivery(Order $order, int $evolizClientId): DeliveryDraft
    {
        $order->loadMissing(['user', 'paytype', 'lines.article']);

        $comment = __((string) (config('passorde.evoliz.delivery_comment') ?? 'Order :number entered by :author'), ['number' => $order->number, 'author' => $order->user->name]);
        if ($order->comment !== null && trim($order->comment) !== '') {
            $comment .= "\n".trim($order->comment);
        }

        return new DeliveryDraft(
            clientId: $evolizClientId,
            externalNumber: $order->number,
            documentDate: now('Europe/Paris')->toDateString(),
            paytermId: self::paytermId(),
            lines: $order->lines->map(fn (OrderLine $line) => self::line($line))->values()->all(),
            paytypeId: $order->paytype?->evoliz_paytype_id,
            recoveryIndemnity: (bool) config('passorde.evoliz.recovery_indemnity'),
            comment: $comment,
        );
    }

    /**
     * The payment term of every BL: EVOLIZ_PAYTERM_ID (mocks), else the profile's evoliz.payterm_id.
     * Evoliz requires one, so without it the push fails before anything is sent.
     */
    public static function paytermId(): int
    {
        $term = config('server.evoliz.paytermid') ?? config('passorde.evoliz.payterm_id');

        if (! is_numeric($term) || (int) $term < 1) {
            throw new PushFailed(PushMessages::noPayterm());
        }

        return (int) $term;
    }

    public static function line(OrderLine $line): DeliveryLineDraft
    {
        $article = $line->article;

        if ($article === null || $article->evoliz_article_id === null) {
            throw new PushFailed(__('Line :position has no catalogue article.', ['position' => $line->position + 1]));
        }

        $designation = $article->designation_raw ?? $article->designation;
        $override = $line->is_free ? $designation.config('passorde.free_lines.evoliz_suffix') : null;

        if ($line->note !== null) {
            // With the new line, like Evoliz's own form: designation_clean only drops the tag.
            $override = DeliveryLineDraft::withComment($override ?? $designation, $line->note);
        }

        return new DeliveryLineDraft(
            articleId: $article->evoliz_article_id,
            quantity: (float) $line->quantity,
            unitPriceHt: $line->is_free ? 0 : (float) $line->unit_price_ht,
            designationOverride: $override,
        );
    }
}
