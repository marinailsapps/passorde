# Orders API

Same-origin JSON API used by the Vue frontend. The offline outbox replays exactly these requests, so changes to this contract must stay backward compatible (add fields, never rename or remove).

Routes: `routes/api.php`, mounted under `/api` with the `web` middleware group (session cookie, CSRF) and `auth`.

## Conventions

- **Authentication**: Laravel session cookie. No tokens.
- **CSRF**: every non-GET request sends `X-XSRF-TOKEN` with the URL-decoded value of the `XSRF-TOKEN` cookie (see `resources/js/lib/api.ts`).
- **Headers**: `Accept: application/json`, `Content-Type: application/json`.
- **Money and quantities** are strings with two decimals (`"53.00"`). Requests may send numbers.
- **Dates** are ISO 8601 in UTC (`2026-09-30T21:49:03+00:00`). Filters take `YYYY-MM-DD` in Europe/Paris time.
- **Ids**: orders are addressed by their `uuid`, which the phone generates (v4). Clients and articles use the app's numeric `id` (not the Evoliz id).
- **Messages are French.**

| Status | Meaning | Body |
| --- | --- | --- |
| 200 / 201 | OK / created | see each endpoint |
| 204 | Deleted | empty |
| 401 | Not logged in, or the account was deactivated while signed in(before, that request got a redirect to the login page) | `{"message": "Unauthenticated."}` or `{"message": "Ce compte a été désactivé."}` |
| 403 | Someone else's order | `{"message": "Cette commande appartient à un autre utilisateur."}` |
| 404 | Unknown uuid or id | `{"message": "..."}` |
| 409 | Order no longer editable | `{"message": "Cette commande ne peut plus être modifiée : son statut est « Envoyée »."}` or `{"message": "Cette commande a été supprimée."}` |
| 419 | CSRF token expired | reload the page to get a fresh cookie |
| 422 | Validation | `{"message": "...", "errors": {"lines.0.article_id": ["Cet article n'est plus au catalogue."]}}` |
| 429 | More than 120 requests in a minute (per user, whole `/api`) | `{"message": "Too Many Attempts."}`, `Retry-After` header |

**Outbox rule**: 2xx means done. 401 and 419 mean re-authenticate, then retry; a response that was redirected to `/login` counts as 401. When the session is gone for good the phone wipes its data or lists its unsent orders on the login page (`sessionRejected` in `resources/js/offline/runtime.ts`, RUNBOOK "Lost or stolen phone"). 403, 409 and 422 are final; show the message and keep the draft. 408, 429, 5xx and network errors mean retry later. The sender (`resources/js/offline/outbox.ts`) replays `PUT /api/orders/{uuid}` and `DELETE /api/orders/{uuid}` exactly as documented here, one entry at a time in creation order.

## Orders

### `PUT /api/orders/{uuid}` — create or update

Idempotent. The first call creates the order and allocates its number. Later calls with the same uuid replace the client, lines, paytype and comment. Replaying an identical body changes nothing and logs nothing.

Rules:

- Allowed only while the order is `pending` and the caller is its author or an admin (the order stays its author's, and its next delta reaches the author's phone). Otherwise the response is 403 (another user's order) or 409 (not pending, or deleted).
- The server takes `designation` and `vat_rate` from the catalogue, and `unit_price_ht` from the request. A free line (`is_free: true`) is always saved at 0 €.
- The server recomputes line totals and order totals and stores them.
- Lines are saved in `position` order and renumbered 0…n.

Body with an existing client:

```json
{
  "client_id": 4,
  "lines": [
    { "article_id": 6, "quantity": 10, "unit_price_ht": 53, "is_free": false, "position": 0 },
    { "article_id": 6, "quantity": 1, "unit_price_ht": 0, "is_free": true, "position": 1 },
    { "article_id": 299, "quantity": 2, "unit_price_ht": 32, "is_free": false, "position": 2 }
  ],
  "paytype_id": 2,
  "comment": "Livrer avant vendredi",
  "duplicated_from": null
}
```

Body with a new client (use `client` instead of `client_id`; never send both):

```json
{
  "client": {
    "uuid": "5d0c1f7e-2b8a-4c1e-9f3d-7a6b5c4d3e2f",
    "company_name": "SARL DU VAL",
    "contact_name": "Elouan Clairval",
    "address_line": "2 place Exemple",
    "postcode": "25200",
    "town": "LAMBERTOU",
    "phone": "06 00 00 00 00",
    "email": null,
    "extra_field": "NP-58321"
  },
  "lines": [ ... ],
  "paytype_id": 2,
  "comment": null
}
```

| Field | Rules |
| --- | --- |
| `client_id` | Required unless `client` is sent. The client must be active. |
| `client.uuid` | Required, generated on the phone. A replay with the same uuid reuses the same client. Its author can still correct the fields until the client is pushed to Evoliz. |
| `client.company_name` | Required, max 120. |
| `client.postcode` | Required, 5 digits. |
| `client.town` | Required, max 120. |
| `client.contact_name`, `address_line`, `phone`, `email`, `extra_field` | Optional. |
| `lines` | 1 to 100 items. |
| `lines.*.article_id` | Required; must be an active catalogue article. The error names the article: `L'article ASTRA MT POM n'est plus au catalogue : retirez la ligne.` |
| `lines.*.quantity` | Required, > 0, 2 decimals max. |
| `lines.*.unit_price_ht` | Required, ≥ 0, 2 decimals max. Ignored (0) for free lines. |
| `lines.*.is_free` | Optional, default false. A free line needs a paid line of the same article in the same order. |
| `lines.*.note` | Optional: the seller's comment on this line, max 255 characters, or null. Answers carry it back as `note`. On approval it is added under the line's designation on the BL, so the client sees it. A PUT without it clears it, like any other part of the order. |
| `lines.*.position` | Optional; sort key. |
| (order total) | At most 9 999 999,99 € TTC, else 422 on `lines`: `Le total de la commande dépasse 9 999 999,99 € : vérifiez les quantités et les prix.` |
| `paytype_id` | Optional: an enabled paytype (`GET /api/paytypes`), or `null` / absent for "Non précisé" (the usual case); the BL is then created without a paytype. |
| `comment` | Optional, max 2000. |
| `duplicated_from` | Optional uuid of the source order ("Dupliquer"); only logged. |
| `client_changes` | Optional, only with `client_id`: corrections to that client's details, see below. |

A new client is created with `source = app`, `created_by` set to the caller, and no Evoliz id; it is pushed to Evoliz at the first approval, or at once when saved on its own with `PUT /api/clients/{uuid}`. If the order switches from a typed client to another client, the typed client is deleted, provided nothing else refers to it.

**Client corrections (`client_changes`).** The sales person corrects an existing client from the order ("Modifier les coordonnées"). Send only the fields that changed, with their new value; `null` or `""` removes a phone, the address line or the optional client field:

```json
{
  "client_id": 4,
  "client_changes": { "address_line": "2 route du Lac", "postcode": "25200", "town": "LAMBERTOU", "mobile": "07 11 22 33 44" },
  "lines": [ ... ]
}
```

| Field | Rules |
| --- | --- |
| `contact_name` | Max 120. Can be replaced, not removed when the client has one (422 `Ce champ peut être remplacé, pas effacé.`). |
| `address_line` | Max 255. Replaces all three Evoliz address lines. |
| `postcode` | 5 digits; cannot be removed. |
| `town` | Max 120; cannot be removed. |
| `phone` | A French number as typed (`09 70 12 34 56`, `+33 9 70 …`). |
| `mobile` | A French mobile (06 / 07); the order SMS goes to it. |
| `email` | A valid email, lower-cased. Can be replaced, not removed when the client has one. |
| `extra_field` | Max 50; app only (Evoliz has no such field). The installation's optional client field: its label, CSV column and whether the client search reads it come from the profile (`client.extra_field`, `App\Domain\Clients\ClientExtraField`); the field keeps this name in every request and answer whatever the label. Pages get the shared Inertia prop `clientExtraField` (`{label, searchable}`, or null when the profile has none: the field is then not shown, imported, exported or searched; stored values stay in the answers), kept in the phone's offline session (a session stored before it keeps the field shown and searched, under the neutral label "Champ client", until the next online page). |

The server keeps only the fields that differ from the client, as one correction per order: the next PUT of the order replaces it, and a PUT without `client_changes` (or with nothing different) drops it. The client itself does not change yet: the correction waits for the operator, who may leave it out when approving (`apply_client_change: false`). On approval the push sends it to Evoliz before the BL (`PATCH /clients/{id}`, and `PATCH /contacts-clients/{id}` for contact and email; docs/EVOLIZ.md "Client update"), then the client takes Evoliz's values and appears in the client delta. Deleting or rejecting the order cancels it. If Evoliz refuses it, the BL is still created and `evoliz_warning` says so; the admin can send it again from the client page.

The response is 201 on creation and 200 on update:

```json
{
  "order": {
    "uuid": "0b7e4a0e-5f7c-4d8e-9a55-3c1f2d9e8a10",
    "number": "C-2026-0003",
    "status": "pending",
    "created_at": "2026-09-30T21:49:03+00:00",
    "updated_at": "2026-09-30T21:49:03+00:00",
    "author": { "id": 2, "name": "Solène Exemple" },
    "client": {
      "id": 4,
      "uuid": null,
      "company_name": "EXEMPLE AMBROISE",
      "contact_name": "Exemple Ambroise",
      "address_line": "1 RUE EXEMPLE",
      "postcode": "33380",
      "town": "BOISJOLI",
      "phone": "06 00 00 00 00",
      "mobile": "06 00 00 00 00",
      "email": "client@example.test",
      "extra_field": "LV-2093",
      "linked": true
    },
    "client_change": null,
    "paytype": { "id": 2, "label": "Virement" },
    "comment": "Livrer avant vendredi",
    "lines": [
      {
        "position": 0,
        "article_id": 6,
        "reference": "GALA GD POM",
        "designation": "GALA GODET POMMIER / LOT : 0001",
        "quantity": "10.00",
        "unit_price_ht": "53.00",
        "vat_rate": "5.50",
        "is_free": false,
        "total_ht": "530.00",
        "stock": { "is_stocked": true, "stock_qty": "7.00", "reserved_qty": "11.00" },
        "catalog_unit_price_ht": "53.00"
      },
      {
        "position": 1,
        "article_id": 6,
        "reference": "GALA GD POM",
        "designation": "GALA GODET POMMIER / LOT : 0001",
        "quantity": "1.00",
        "unit_price_ht": "0.00",
        "vat_rate": "5.50",
        "is_free": true,
        "total_ht": "0.00",
        "stock": { "is_stocked": true, "stock_qty": "7.00", "reserved_qty": "11.00" }
      },
      {
        "position": 2,
        "article_id": 299,
        "reference": "REINETTE POM",
        "designation": "REINETTE POMMIER / LOT : 0002",
        "quantity": "2.00",
        "unit_price_ht": "32.00",
        "vat_rate": "5.50",
        "is_free": false,
        "total_ht": "64.00",
        "stock": { "is_stocked": true, "stock_qty": "-12.00", "reserved_qty": "2.00" }
      }
    ],
    "totals": {
      "quantity": "13.00",
      "ht": "594.00",
      "vat": "32.67",
      "ttc": "626.67",
      "vat_by_rate": [{ "rate": "5.50", "base_ht": "594.00", "vat": "32.67" }]
    },
    "rejected_reason": null,
    "evoliz_delivery_number": null,
    "can_edit": true
  },
  "possible_duplicate": null
}
```

- `catalog_unit_price_ht` is the article's current catalogue price (null when the article is gone), so a copied negotiated price can be compared with it.
- `stock` gives the article's current Evoliz stock and its reservations, which include this order's own lines. It is `null` if the article no longer exists.
- `possible_duplicate` is only set when the order uses a client created in the app (`linked: false`). It holds an existing active client with the same postcode and a company name with pg_trgm similarity above 0.6, in the same shape as `order.client`. The client can then send the same PUT again with `client_id` set to that client.

**Totals** (`app/Domain/Orders/OrderTotals.php`, mirrored in `resources/js/lib/orderTotals.ts`):

- Line HT = quantity × unit price, rounded to the cent.
- VAT is computed once per rate, on the sum of that rate's HT, and rounded half up.
- TTC = HT + VAT. `quantity` = sum of all quantities, free ones included.
- For example 3 × 83.00 + 1 × 94.00 at 5.5 % gives 343.00 HT, 18.87 VAT (18.865 rounded half up) and 361.87 TTC.

**Numbers**: `C-YYYY-NNNN`, per calendar year in Europe/Paris time. They are allocated from `order_counters` inside the create transaction, so they are unique under concurrency. A deleted order keeps its number.

**Status fields** (all orders):

| Field | Meaning |
| --- | --- |
| `status` | Real status: `pending`, `approved`, `rejected`, `sent`, `failed` |
| `display_status` | What this viewer should show. For the author a failed push stays `approved` (still on its way to Evoliz); admins see the real status. |
| `status_label` | French label for `display_status`: "En attente", "Validée · envoi en cours", "Échec de l'envoi" (admins), "Envoyée · BL-20261000123", "Refusée" |
| `approved_at`, `approved_by` | When and by whom (admin name) |
| `sent_at`, `evoliz_delivery_number` | Set once the BL exists |
| `rejected_reason` | Shown to the author when `rejected` |
| `evoliz_error` | Admin only (null otherwise): the last push error, for example `HTTP 400 — items.0.articleid : …` |
| `evoliz_error_summary` | Admin only: the common push errors in plain French (postcode, address or phone refused, a required client field missing, Evoliz unreachable or too slow, rate limit); null when the error is none of these, then `evoliz_error` is all there is |
| `evoliz_warning` | Admin only: totals that differ between the app and the BL, a contact or a client correction Evoliz refused (the BL exists; one warning per line) |
| `activity` | Admin only: `[{"action": "approved", "label": "Validée", "user": "Chef", "created_at": "…", "detail": null}]`. Actions: created, updated, deleted, duplicated, approved, rejected, retried, sent, failed, totals_mismatch, contact_failed, sms_resent, client_change_applied, client_change_failed, client_change_rejected. `detail` of an `updated` entry says what changed, one change per line ("GALA GD POM : quantité 10 → 12"); the whole order before and after each edit is kept in `activity_log.meta_json` (`before`, `after`). |
| `client.mobile` | Evoliz's mobile field (`phone` shows it too when Evoliz has no other phone). Also in the client delta. |
| `articles_without_numbers` | Admin only, while `pending` (otherwise `[]`): references of articles added from an order whose designation does not match the profile's `catalog.new_article.complete_pattern` yet (the operator completes it in Evoliz). The catalogue syncs every 15 minutes, so it may lag behind Evoliz. |
| `client_change` | The client correction saved with the order, or null: `{"status": "pending", "changes": {"town": {"from": "BOISJOLI", "to": "LAMBERTOU"}}, "error": null, "decided_by": "Chef", "applied_at": null}`. `status`: `pending` (waits for approval), `applied`, `rejected` (left out by the operator), `failed` (Evoliz refused it, `error` says why), `cancelled` (order deleted or rejected). `changes` holds only the corrected fields. |
| `can_delete` | The caller may delete it (`DELETE /api/orders/{uuid}`): its author while `pending` or `rejected`, any admin while `rejected`. Also in summaries. |
| `can_approve`, `can_reject`, `can_retry` | Admin actions available now |
| `stock_conflicts` | Admin only: articles this order would take below zero, see [Stock conflicts](#stock-conflicts) |
| `sms` | Admin only, and null while `SMS_ENABLED` is false: `{"recipient": "06 12 34 56 78", "status": "sent", "phone": "06 12 34 56 78", "error": null, "sent_at": "…", "attempted_at": "…", "can_resend": true}`. `recipient` is the client's French mobile the SMS goes to (null: the client has none, no SMS). `status` is the last attempt's: null (none yet), `pending`, `sent`, `failed` (`error` says why) or `skipped` (no mobile when the order was sent). |

Summaries (`GET /api/orders`, admin list) also carry `display_status`, `status_label`, `author: {id, name}` and `can_approve`. In `GET /api/orders`, `status=approved` also returns failed pushes, which the author sees as approved.

### `DELETE /api/orders/{uuid}`

Soft-deletes a `pending` or `rejected` order of the caller and returns 204 (rejected ones never reached Evoliz). Deleting an order that is already deleted returns 204 again, so the call is safe to replay. Admins may delete any `rejected` order; other users' orders return 403; approved, sent and failed orders return 409.

### `GET /api/orders`

The caller's own orders, newest first, 20 per page. Deleted orders are excluded.

| Query | Meaning |
| --- | --- |
| `status` | `pending`, `approved`, `rejected`, `sent`, `failed` |
| `from`, `to` | `YYYY-MM-DD` (Europe/Paris), inclusive, on the creation date |
| `q` | Client search (same rules as `/api/clients/search`) |
| `page` | 1-based |

```json
{
  "data": [
    {
      "uuid": "0b7e4a0e-5f7c-4d8e-9a55-3c1f2d9e8a10",
      "number": "C-2026-0003",
      "status": "pending",
      "created_at": "2026-09-30T21:49:03+00:00",
      "client": { "id": 4, "company_name": "EXEMPLE AMBROISE", "town": "BOISJOLI" },
      "total_quantity": "13.00",
      "total_ttc": "626.67",
      "evoliz_delivery_number": null,
      "can_edit": true
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```

### `GET /api/orders/{uuid}`

Returns `{"order": { ... }}` with the same shape as the PUT response. Visible to the author and to admins (`can_edit` is true for admins too while the order is `pending`). Returns 403 for anyone else and 404 for deleted orders.

## Admin: approval

All under `/api/admin`, admin role only (403 otherwise). Approving queues the push to Evoliz; nothing here calls Evoliz itself. A transition from the wrong status returns 409 with a French message (for example "Seules les commandes en attente peuvent être validées.").

| Endpoint | Body | Effect |
| --- | --- | --- |
| `GET /api/admin/orders` | query: `status` (`pending` by default, `all`, or `conflict` for "Stock insuffisant"), `user_id`, `from`, `to`, `q`, `page` | Every user's orders, 25 per page, plus `counts` per status (other filters applied) for the chips; each row carries `stock_conflicts` |
| `POST /api/admin/orders/{uuid}/approve` | optional `{"apply_client_change": false}` to approve without the order's client correction | pending → approved and queues `PushOrderJob` → returns `{"order": …}` |
| `POST /api/admin/orders/{uuid}/reject` | `{"reason": "…"}` (required, max 500) | pending → rejected → `{"order": …}` |
| `POST /api/admin/orders/{uuid}/retry` | — | failed → approved and queues the push again → `{"order": …}` |
| `POST /api/admin/orders/{uuid}/sms` | — | "Renvoyer": queues the client's SMS again (sent orders, `SMS_ENABLED` only; 409 otherwise) → `{"order": …}` |
| `POST /api/admin/orders/approve` | `{"uuids": ["…", "…"]}` (1–100) | Approves each pending order (one job each) → `{"approved": ["C-2026-0004"], "skipped": [{"number": "C-2026-0002", "reason": "…"}]}` |

```json
{
  "data": [ { "uuid": "…", "number": "C-2026-0004", "status": "pending", "display_status": "pending", "status_label": "En attente", "author": { "id": 2, "name": "Solène Exemple" }, "created_at": "…", "client": { "id": 7, "company_name": "SARL FICTIF", "town": "VILLEFICTIVE" }, "total_quantity": "6.00", "total_ttc": "257.42", "evoliz_delivery_number": null, "can_edit": true, "can_approve": true } ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 1 },
  "counts": { "pending": 1, "approved": 0, "rejected": 1, "sent": 3, "failed": 0, "all": 5, "conflict": 0 }
}
```

### Stock conflicts

`stock_conflicts` lists the articles an order would take below zero. It is on every row of `GET /api/admin/orders` (empty array when none) and on the order detail for admins (`null` for other users). It is informational: approving stays possible.

How it is computed (`app/Domain/Orders/StockConflictFinder.php`): the orders that hold stock (`pending`, `approved`, `failed`; not deleted) are sorted by `created_at`. For each article with stock management, their quantities (free lines included) are added up in that order. The first order whose running total exceeds the article's Evoliz `stock_qty`, and every later order on that article, is in conflict with `shortfall = running total − stock`. Sent orders are not counted (their BL already moved the Evoliz stock).

```json
"stock_conflicts": [
  {
    "article_id": 6,
    "reference": "GALA GD POM",
    "requested": "3.00",
    "available_before": "1.00",
    "shortfall": "2.00",
    "stock_qty": "7.00",
    "reserved_before": "6.00",
    "earlier_orders": ["C-2026-0007", "C-2026-0008"]
  }
]
```

| Field | Meaning |
| --- | --- |
| `requested` | This order's quantity of the article, free lines included |
| `stock_qty` | The article's Evoliz stock at the last sync |
| `reserved_before` | Quantity held by the earlier orders listed in `earlier_orders` |
| `available_before` | `stock_qty − reserved_before` (may be negative) |
| `shortfall` | Running total including this order − `stock_qty` |
| `earlier_orders` | Numbers of the earlier orders on this article, oldest first |

`counts.conflict` is the number of orders in conflict (other filters applied); `status=conflict` lists them.

## Evoliz states

Evoliz runs only while it is switched on (`EVOLIZ_ENABLED`), its three keys are set and the Passorde licence is usable (`App\Support\EvolizState`). In every other state (switched off, a key missing, licence expired past its grace, invalid, missing, for another product, or not covering Evoliz) the API behaves exactly as in file mode below: `syncProvider` is `file`. Additive fields only:

| Topic | Rule |
| --- | --- |
| Order detail | Adds `can_send_to_evoliz` (admins, Evoliz mode): approved while Evoliz was unavailable, never exported (an export later unmarked counts as exported). `status_label` reads "Validée hors Evoliz" for such an order in Evoliz mode |
| Order detail | Adds `push_pending` (admins; false otherwise): an approved order whose push to Evoliz is under way, i.e. Evoliz running, not approved without it (`standalone_at`, until "Envoyer à Evoliz") and never exported. The order page shows "Envoi vers Evoliz en cours…" only then; a phone copy older than the field shows nothing. |
| `POST /api/admin/orders/{uuid}/send-to-evoliz` | Admin, Evoliz mode (404 otherwise). Queues the push of that order → `{"order": …}`; 409 when it is not approved, already being sent, or was ever exported (its BL may have been made by hand from that file: such an order is never sent to Evoliz). It takes `{"confirm_download": true}` (optional boolean): an order that was in a downloaded export file and never marked exported answers 409 with a message naming the download date unless it is given |
| Order detail `export_downloaded_at` | Admins: ISO date of the last time the order was in a downloaded export file (activity `export_downloaded`), else null. Downloading `/admin/orders/export.csv` or `.xlsx` records it on each order of the file once the whole file is written (no stock move, no status change; a file that fails while it is written records nothing). Such an order is never pushed by itself; "Envoyer à Evoliz" asks for the confirmation above |
| A push queued before Evoliz stopped | The order is settled as if approved standalone (exportable, `can_send_to_evoliz` once Evoliz is back) instead of failing. It fails instead, for "Réessayer" once Evoliz is back, when Evoliz may already hold its BL: one is stored, or a creation was sent (`passorde_requests`, `delivery.create`) and did not end in a clear refusal (a 4xx other than 408, 409, 429) |
| `POST /api/admin/orders/{uuid}/retry` while Evoliz does not run | A failed order becomes approved and is settled as on approval (exportable, SMS queued), with no push; the button reads "Valider hors Evoliz". 409 with the reason when Evoliz may already hold its BL (rule above); `can_retry` is false for such an order. `{"checked_evoliz": true}` settles it anyway when no BL id is stored (`can_settle_checked`), after the admin confirmed Evoliz has no BL for its number ("J’ai vérifié : valider hors Evoliz"); logged, and never sent to Evoliz afterwards (`send-to-evoliz` answers 409) |

## File mode

With Evoliz off (`EVOLIZ_ENABLED=false`, or any state above where Evoliz does not run) the app runs with no Evoliz. Same endpoints, same shapes, additive fields only. Pages receive the shared Inertia prop `syncProvider` (`evoliz` or `file`). In Evoliz mode none of this changes anything: `exported_at` is set while Evoliz does not run, or with Evoliz back by an admin for an order approved without it; such an order is never sent. An order exported during a lapse keeps it once Evoliz is back (never sent); when its hand-made BL arrives in the mirror linked to it, the client history lists the BL alone, never both.

| Topic | In file mode |
| --- | --- |
| Lifecycle | `pending → approved`, or `rejected`. `sent` and `failed` never occur. A later export sets `exported_at` and no status changes |
| `exported_at` | New field of the order detail and summaries, in both modes: ISO date or null. Rows mirrored before this date lack it |
| `status_label` | `approved` reads "Validée", or "Exportée" once `exported_at` is set; `display_status` stays `approved` |
| `POST /api/admin/orders/{uuid}/approve` and bulk approve | Queue no push. In the same transaction: the client correction is applied (`client_change.status` becomes `applied`) unless `apply_client_change` is false (`rejected`), and a new article's lines take the catalogue's designation if theirs still lacks the numbers. The client's SMS is queued (`SMS_ENABLED`) |
| `POST /api/admin/orders/{uuid}/retry` | No failed order occurs in a pure file-mode installation, so `can_retry` stays false there; after Evoliz stopped, see "Evoliz states" (settles a failed order) |
| `POST /api/admin/orders/{uuid}/sms`, `sms.can_resend` | Work on `approved` orders instead of `sent` ones. The text carries no BL number |
| `client.linked` | Means "settled entry of the directory": imported, or typed with an order past pending. Its author can correct a typed client until then; its `updated_at` moves when it becomes settled |
| `PUT /api/clients/{uuid}` | Saves the client and queues nothing. Same answer as in Evoliz mode, with `linked` false until the client is settled |
| `PUT /api/article-requests/{uuid}` | Creates the article in the request and queues nothing: the first answer is already `status: created` with the `article`, so the form never polls and `pending` and `failed` never occur. A reference already in the catalogue (any case) is the same article: it keeps its price and stock, and comes back if it was inactive. The operator completes the designation on the catalogue page; `articles_without_numbers` warns until then, and approval gives the order's lines the catalogue's designation |
| `GET /api/admin/orders` | `status` also takes `to_export` (approved, not exported) and `exported`; `status=approved` keeps its meaning and lists both. `counts` gains `to_export` and `exported`. In Evoliz mode these two values answer 422 and the two counts are absent |
| `POST /api/admin/orders/mark-exported` | Admin. `{"uuids": ["…"]}` (1–150): the orders of the file just taken, not "everything approved". Each order in its own transaction: `exported_at`, `exported_by`, the local stock falls by the order's quantities, free lines included (articles with no stock management stay as they are), activity entry, `updated_at` moves so the author's phone gets the new label. Answers `{"exported": ["C-2026-0004"], "skipped": [{"number": "C-2026-0002", "reason": "…"}]}`: an order that is not approved, or already exported, is skipped, so a replay changes nothing. Also with Evoliz running, for an order approved without it (`standalone_at`; the local stock then does not move: Evoliz's stock is the reference and its sync overwrites the local one); any other order is skipped with the reason, and so is one Evoliz may already hold (the rule of "Evoliz states") unless it was settled on the admin's word (`checked_evoliz`). Such an order stays never sent |
| `POST /api/admin/orders/{uuid}/unmark-exported` | Admin. Reverses the above: the order is "to export" again, the quantities go back to stock. Answers `{"order": …}`; 409 when it is not exported, or (Evoliz running) when it was not approved without Evoliz. |
| Order detail | Adds `exported_by` (name or null) and `can_unmark_exported` (admins, file mode; also an order approved without Evoliz), `can_mark_exported` (admins, Evoliz running: approved without it, not exported, not one Evoliz may hold) and `can_settle_checked` (admins, standalone: a failed order whose BL creation got no clear answer and no BL id) |
| Reservation and stock conflicts | An exported order no longer reserves stock and is left out of `stock_conflicts` and of `reserved_qty` |
| `GET /api/clients/{id}/history`, `recent-orders` | An exported order has `status: delivered`, dated by its export (Europe/Paris) in `date`. Same rule on the phone (`salesHistory.ts`). Older mirrored rows without `exported_at` read as not exported until their next delta |
| Reports | "Ventes" sums the delivered orders (exported, or sent to Evoliz before it stopped) by export or sending date (each order is one "delivery": `number` is the order number, `invoiced` is false); this also applies with Evoliz switched on but not running (keys, licence), where `source` is `file`. Gifts in orders are the free lines of exported orders, by export date. `synced_at` and `syncedAt` are null. Offline the Ventes page answers a notice (`Offline/Unavailable`, `reason: sales`) instead of zeros: the mirror's delivery endpoints stay empty in file mode; also in Evoliz mode for a phone session older than the `catalog` prop (it does not know which variants count) |
| `evoliz_*` fields | Stay in the answers, always null |

Not under `/api`, like the other downloads: `GET /admin/orders/export.csv` and `GET /admin/orders/export.xlsx`, admin, file mode (403 otherwise). Query: `uuids[]` (at most 150, `App\Domain\Orders\OrderExport::MAX_ORDERS`, the admin page's `exportLimit` prop) or the list's filters `status` (`to_export` by default, or `exported`), `user_id`, `from`, `to`, `q` (the 150 oldest that match). Approved orders only, 404 when there are none. Taking the file changes nothing. Columns and format: docs/RUNBOOK.md "Standalone mode: export".

## Old names (installation aliases)

An installation that renames API fields after its phones are installed states the older names in its profile (`aliases`, config/passorde.php; `App\Http\Api\ApiAliases`), so that those phones keep working. Every map is empty in a new installation: the API then answers with the names of this document only.

- **Requests:** a body may carry an old name instead of the current one (a phone's unsent outbox entry, an old cached page); the current name wins when both are sent, and an old name sent as `null` counts as the current one sent as `null`. `PUT /api/orders/{uuid}` stays idempotent: the same order sent with the old names and then with the current ones gives the same order, and the replay changes nothing.
- **Answers:** every object that carries a renamed field carries the old name right after the current one, with the same value (the keys of `client_change.changes` included). A stock tab's old `key` is answered as `legacy_key` beside `key`.
- **Not aliased:** the shared and page Inertia props (the page and its bundle always come together), and validation errors, which are keyed by the current name.

| Map (`aliases.…`) | Current name => old name | Where |
| --- | --- | --- |
| `client` | client fields | every client object (`client` of an order, `/api/clients`, `/api/clients/search`, `possible_duplicate`, `PUT /api/clients/{uuid}`), the keys of an order's `client_change.changes`; requests: `client.*`, `client_changes.*`, `PUT /api/clients/{uuid}` |
| `article` | `category`, `variant` | `/api/articles` rows, `/api/reports/stock` stock rows; requests: `PUT /api/article-requests/{uuid}`, the admin attribute form and filter |
| `totals` | `totals.quantity` | every order object (`order`, the order mirror) |
| `summary` | `total_quantity` | the summaries of `GET /api/orders` (and the admin list) |
| `stock_tabs` | tab `key` (a value) | `/api/reports/stock` tabs, as `legacy_key` |
| `routes.api`, `routes.web` | old path => current path | the same answer at both addresses; a web `GET` redirects |
| `groupings` | old => current | a sales report link with an old `group` opens the current grouping |

For example the fictional `tests/Fixtures/profiles/renamed` answers `member_code` beside `extra_field`, `family` beside `category`, and `GET /api/families` like `GET /api/categories`.

The phone reads the rows it stored under the old names under the current ones (`resources/js/offline/legacy.ts`, Dexie reading hooks, no schema change; the maps `client`, `article`, `totals` and `groupings` are fixed in the assets at build time) and leaves its outbox entries as they are. The database's own older names are renamed once with `php artisan passorde:rename-legacy` (`aliases.schema`; docs/RUNBOOK.md "Older names").

## Installation catalogue and wording

The product categories, their groups and the variants come from the installation profile (`catalog.*`, `App\Domain\Catalog\Catalog`); the fields keep their names everywhere: an article's category travels as `category`, its variant as `variant`, and a stored value the profile no longer lists is still returned as it is. Two shared Inertia props, both kept in the phone's offline session:

- `wording`: `{words, texts}`. `words` holds `unit`, `units`, `free`, `category`, `categories` and `item`, the words of the `:placeholders` in `lang/fr.json` (`:Units` capitalised, `:UNITS` upper case); `texts` holds whole French sentences by English key, laid over `lang/fr.json` (`App\Support\Profile\Wording`, `resources/js/lib/i18n.ts`). A session stored before it uses the neutral words bundled in the app (`resources/js/lib/wordingDefaults.json`, the defaults of `config/passorde.php`) until the next online page: never a raw placeholder.
- `catalog`: `{new_article, has_categories, variants}` (`has_categories`: false when the profile defines none; the sales report then offers no grouping by category and its workbook no such sheet; absent in older sessions, read as true). `new_article` says whether the order form has the "new article" button (`catalog.new_article`); `variants` lists `{value, label, name, counts, own_tab}` (`own_tab`: its articles have their own stock tab; the phone's offline Ventes reads `counts` and `own_tab` from the session) in the profile's order (`label` short, `name` for forms, `counts`: a new article may have it, an article of it without a category is "à vérifier", and only its quantities count in Ventes; `own_tab`: in Ventes its lines group apart, as `~material`). A session stored before it keeps the button (disabled offline anyway).

The admin attribute page's `categories` entries carry `label`; each stock report tab gained `variant` (true for the tab of a variant that has its own, false otherwise).

## Clients

### `PUT /api/clients/{uuid}` — save a client without an order

"Enregistrer sans commande" in step 1 of the order form. The body is the fields of `client` in `PUT /api/orders/{uuid}`, with the same rules, without `uuid`:

```json
{ "company_name": "SARL DU VAL", "contact_name": "Elouan Clairval", "address_line": "2 place Exemple", "postcode": "25200", "town": "LAMBERTOU", "phone": "06 00 00 00 00", "email": null, "extra_field": "NP-58321" }
```

Answers 201 (created) or 200 (the uuid exists) with `{ "client": { ... } }`, the same object as `GET /api/clients/search`. Saved with `source = app` and `created_by` set to the caller, then queued for Evoliz (`linked` becomes true when it is there). Idempotent: a replay reuses the same client, and its author can correct the fields until it is pushed; other users' calls change nothing. Usable straight away as `client_id` of an order. Online only: the outbox carries orders only.

## Offline mirror

The phone keeps a copy of the client directory, the catalogue and the user's own orders in IndexedDB (`resources/js/offline`). These three endpoints feed it. All take the same query and return the same envelope.

| Query | Meaning |
| --- | --- |
| `updated_since` | ISO 8601. Only rows whose `updated_at` is later. Omit it (or send `1970-01-01T00:00:00Z`) for the first load. |
| `until` | Echo of `meta.until` from the first page; send it with every `cursor`. |
| `cursor` | `meta.next_cursor` of the previous page. |

```json
{
  "data": [ ... ],
  "meta": { "per_page": 200, "next_cursor": "eyJpZCI6MjAwLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9", "until": "2026-10-01T10:05:00+00:00" }
}
```

- Rows are sorted by `id` and paged 200 at a time with a keyset cursor. The first page fixes `until` (the server time, to the second); a row that changes while the client is paging falls after `until` and comes with the next delta instead of shifting the pages.
- When `next_cursor` is `null` the delta is complete. The next delta uses `updated_since = until − 60 s`; the minute of overlap covers rows saved in the same second, and re-applying a row is harmless.
- Deactivated clients and articles (`is_active: false`) and deleted orders (`deleted_at` set) are included so the mirror can hide them. Clients can also be removed outright (an unused client typed in the app, when its order switches to another client); the phone does a full reload once a day to drop those.
- The client and article syncs only move `updated_at` when a field really changed, so a delta after a catalogue sync is small.

### `GET /api/clients`

Every client, active or not, in the shape of `/api/clients/search` plus `is_active` and `updated_at`.

```json
{ "data": [ { "id": 4, "uuid": null, "company_name": "EXEMPLE AMBROISE", "contact_name": "Exemple Ambroise", "address_line": "1 RUE EXEMPLE", "postcode": "33380", "town": "BOISJOLI", "phone": "06 00 00 00 00", "email": "client@example.test", "extra_field": "LV-2093", "linked": true, "is_active": true, "updated_at": "2026-10-01T10:00:00+00:00" } ], "meta": { ... } }
```

### `GET /api/articles`

Every article in the shape of `/api/articles/search` plus `is_active` and `updated_at`, and `category` and `variant` (report attributes, null when unclassified; the sales report groups by them offline). Changing an article's attributes moves its `updated_at`. `stock_qty` and `reserved_qty` are the values at the time of the request; the phone labels them with the age of its data.

Reservations change with orders, not with the article row, so every page also carries `reservations`: the current `reserved_qty` of every article that has one (an object keyed by article id; articles missing from it have none).

```json
{
  "data": [ { "id": 6, "reference": "GALA GD POM", "designation": "…", "unit_price_ht": "53.00", "vat_rate": "5.50", "is_stocked": true, "stock_qty": "7.00", "reserved_qty": "11.00", "is_active": true, "updated_at": "2026-10-01T10:00:00+00:00" } ],
  "meta": { ... },
  "reservations": { "6": "11.00", "299": "2.00" }
}
```

### `GET /api/orders?updated_since=`

With `updated_since`, `GET /api/orders` switches to the mirror mode: the caller's own orders as full order objects (the shape of `GET /api/orders/{uuid}`), deleted ones included with `deleted_at` (otherwise `null`). Without `updated_since` it keeps the 20-per-page summaries described above.

```json
{ "data": [ { "uuid": "…", "number": "C-2026-0003", "status": "pending", "lines": [ ... ], "totals": { ... }, "can_edit": true, "deleted_at": null } ], "meta": { ... } }
```

## New articles from the order form

An article missing from the catalogue, created in Evoliz straight away while the form waits (in file mode at once). Any signed-in user, online only.

`PUT /api/article-requests/{uuid}`, with a client-generated uuid:

```json
{ "name": "GALA", "category": "POMMIER", "variant": "godet", "unit_price_ht": 52.5 }
```

`category` is a category from `GET /api/categories` (`{"data": [{"value": "POMMIER", "label": "Pommier"}]}`, the profile's `catalog.categories` in their order, label from the profile); `variant` is a variant that counts (`catalog.variants`). Reference, designation and VAT follow the profile's `catalog.new_article`; the stock starts at 0. Without `catalog.new_article` the order form has no button and this answers 404. Answers 201, or 200 when the uuid is known; 403 when it is someone else's; 422 on invalid fields. It only queues the work. Sending the same uuid again ("Réessayer") sends it again, with any corrected fields, unless Evoliz already has it.

`GET /api/article-requests/{uuid}`, polled by the form. Both answer:

```json
{ "request": { "uuid": "…", "status": "created", "error": null, "article": { "id": 912, "reference": "ASTRA STAR MT POM", "...": "the same shape as /api/articles/search" } } }
```

`status`: `pending`, `created` (`article` is set, and an order may use it) or `failed` (`error` says why). The operator adds the numbers in Evoliz; the catalogue sync brings them. Until then the admin's order detail lists the article's reference in `articles_without_numbers`.

## Look-ups for the order form

### `GET /api/clients/search?q=`

Active clients only; clients disabled in Evoliz are excluded. The query needs at least 2 characters; shorter queries return `{"data": []}`. At most 100 characters (422 otherwise) and only its first 6 words are used. The search covers the words of the company name, contact, town, postcode and the optional client field when the profile searches it (start of a word; inside a word for the company name), plus phone digits (a number typed with spaces counts as one). One typo is forgiven in words of 4+ letters (wrong, missing or extra letter, two letters swapped; first letter right). Returns up to 20 clients, best match first: the rules are in `app/Domain/Search/FuzzyMatch.php`, and the phone ranks its offline results identically.

```json
{ "data": [ { "id": 4, "uuid": null, "company_name": "EXEMPLE AMBROISE", "contact_name": "Exemple Ambroise", "address_line": "1 RUE EXEMPLE", "postcode": "33380", "town": "BOISJOLI", "phone": "06 00 00 00 00", "email": "client@example.test", "extra_field": "LV-2093", "linked": true } ] }
```

### `GET /api/clients/possible-duplicate?company_name=&postcode=`

The same duplicate check as the PUT response, done before the client is created. Both parameters are required.

```json
{ "possible_duplicate": null }
```

### `GET /api/clients/{id}/recent-orders`

The last 3 entries of the client's [sales history](#sales-history) (Evoliz BLs included), with their lines, for "Copier une commande précédente". Each line has an extra `available` flag, false when the article is no longer active or unknown (a free-form Evoliz line); the UI skips those lines. Lines of a BL carry the BL's price, with `catalog_unit_price_ht` for the catalogue-price hint.

`delivery_number` (the BL number, or null) and `history_status` (`pending`, `delivered`, `invoiced`, `rejected`, as in the history). For an entry that is an app order, `uuid`, `number`, `status` and `created_at` are the order's; for a BL typed in Evoliz, `uuid` is `"bl:{id}"`, `number` the BL number, `status` `"sent"` and `created_at` the BL date (midnight, Europe/Paris). `author` is the history's seller.

```json
{
  "data": [
    {
      "uuid": "0b7e4a0e-5f7c-4d8e-9a55-3c1f2d9e8a10",
      "number": "C-2026-0003",
      "status": "sent",
      "created_at": "2026-09-30T21:49:03+00:00",
      "author": "Ysolde Dupont",
      "total_ttc": "626.67",
      "lines": [ { "position": 0, "article_id": 6, "reference": "GALA GD POM", "designation": "…", "quantity": "10.00", "unit_price_ht": "53.00", "vat_rate": "5.50", "is_free": false, "total_ht": "530.00", "stock": { "is_stocked": true, "stock_qty": "7.00", "reserved_qty": "11.00" }, "available": true } ]
    }
  ]
}
```

### `GET /api/clients/{id}/history`

The client's [sales history](#sales-history), newest first, every entry: `{"data": [entry, …]}`.

### `GET /api/articles/search?q=`

Active articles only. The query needs at least 2 characters (at most 100, 422 otherwise) and matches the words of the reference and the designation (start of a word or inside it, so codes and lot numbers in a designation are found), with one typo forgiven in words of 4+ letters as for clients. Returns up to 20 articles, best match first (reference before designation). `reserved_qty` is the sum of line quantities, free lines included, on `pending`, `approved` and `failed` orders (the SQL view `article_reservations`).

```json
{ "data": [ { "id": 6, "reference": "GALA GD POM", "designation": "GALA GODET POMMIER / LOT : 0001", "unit_price_ht": "53.00", "vat_rate": "5.50", "is_stocked": true, "stock_qty": "7.00", "reserved_qty": "11.00" } ] }
```

### `GET /api/paytypes`

The paytypes enabled by the admin, ordered by label.

```json
{ "data": [ { "id": 2, "label": "Virement" } ] }
```

## Reports

Read-only, for every role. Online only: the offline mirror does not carry them.

### `GET /api/reports/stock?from=&to=`

The stock report (its title comes from the profile, `reports.stock_title`), the data of the page `/reports/stock` (and of its Excel download `/reports/stock.xlsx?from=&to=`). `from` and `to` are `Y-m-d` dates in Europe/Paris, both optional (default: 1 January of the current year to today; a reversed period is swapped; another format is a 422). The period applies to broken stock and gifts only: the stock and the reservations are always today's.

```json
{
  "period": { "from": "2026-01-01", "to": "2026-10-15" },
  "synced_at": "2026-10-15T08:00:12+00:00",
  "tabs": [
    {
      "key": "fruitiers",
      "label": "FRUITIERS",
      "kind": "stock",
      "variant": false,
      "rows": [
        {
          "article_id": 812,
          "reference": "ALPHA GD POM",
          "designation": "ALPHA POMMIER GODET / LOT : 0003",
          "category": "POMMIER",
          "variant": "godet",
          "type_label": "GODET",
          "stock": "50.00",
          "reserved": "-12.00",
          "broken": "-3.00",
          "gifts": "-1.50",
          "at_term": "33.50",
          "threshold": 40,
          "to_order": "6.50",
          "supplier": "Fournisseur Exemple",
          "active": true
        }
      ],
      "totals": { "stock": "50.00", "reserved": "-12.00", "broken": "-3.00", "gifts": "-1.50", "at_term": "33.50", "to_order": "6.50" }
    },
    {
      "key": "broken",
      "label": "CASSE",
      "kind": "broken",
      "rows": [ { "date": "2026-03-01", "reference": "ALPHA GD POM", "designation": "…", "quantity": "3.00", "note": "rayon 2", "user": "Admin", "evoliz_status": "sent" } ],
      "totals": { "quantity": "3.00" }
    },
    {
      "key": "gifts",
      "label": "CADEAUX CLIENT",
      "kind": "gifts",
      "rows": [
        { "date": "2026-06-04", "source": "order", "order_number": "C-2026-0012", "order_uuid": "0b7e…", "client": "SARL DU PRE", "reference": "ALPHA GD POM", "designation": "…", "quantity": "3.00", "note": null }
      ],
      "totals": { "quantity": "3.00" }
    }
  ]
}
```

- Tabs, in this order: the profile's `catalog.category_groups` by `key` (kind `stock`; `variant` true for the tab a variant owns); `a_classer` (stock; articles with no category or a category marked "à vérifier"). With no category in the profile: the variants' own tabs, then `articles` (kind `stock`, label `reports.stock_tabs.all`, every article) instead of the category tabs and `a_classer`; `broken` (kind `broken`), `gifts` and `material_gifts` (kind `gifts`). Every tab is always present, possibly with no rows, except `material_gifts`, only there when a variant has its own tab. Articles of a variant that neither counts nor has its own tab (shipping costs…) and inactive articles are left out.
- Stock rows: `stock` is the Evoliz stock (`null` for an article Evoliz does not stock); `reserved` is the quantity in app orders pending, approved or failed; `broken` and `gifts` are the journal entries of the period (`/admin/stock-adjustments`). These three are negative. `at_term = stock + reserved + broken + gifts` (`null` without stock). `threshold` is the article's own, else its category's, else `null`; `to_order = max(0, threshold − at_term)`, `null` without threshold or stock. Quantities are strings with two decimals.
- `active`: stock above 0, or on an order not rejected or a journal entry in the last 12 months, or a threshold set on the article. The category's threshold applies to active articles only: an inactive one has `threshold` and `to_order` `null` unless it has its own threshold (which makes it active).
- Gift rows: free lines of orders sent in the period (`source: order`, dated by sending in Paris time) and gifts recorded outside an order (`source: adjustment`, `order_number`/`order_uuid` null), newest first; the gifts of a variant with its own tab go to `material_gifts`. The fixed tabs' labels come from the profile (`reports.stock_tabs`).
- `evoliz_status` on broken-stock and outside-order gift rows: where the entry stands with the Evoliz stock, `pending`, `sent` (subtracted from Evoliz's stock), `failed`, `disabled` (test mode) or `skipped` (no stock management); `null` for gifts on orders (the BL moves the stock) and for entries older than this field. Since entries are written to Evoliz, the stock rows' `broken` and `gifts` count only entries Evoliz does not have yet (not `sent`), plus, positively, deleted entries whose quantity Evoliz has not got back yet; `at_term` uses the same amounts.
- `synced_at`: end of the last successful article sync (`null` if none), i.e. the age of the stock figures.

## Sales history

One timeline per client from the Evoliz delivery notes the app mirrors (`evoliz:sync-deliveries`, docs/RUNBOOK.md "Delivery mirror"), which are the truth once a BL exists, plus the app orders not in Evoliz yet. Built by `App\Domain\Sales\SalesHistory` on the server and by `resources/js/lib/salesHistory.ts` on the phone (from the mirror below and the phone's own orders).

```json
{
  "key": "bl:812",
  "date": "2026-09-02",
  "delivery_number": "BL-20269001100",
  "order_number": "C-2026-0012",
  "order_uuid": "0b7e4a0e-5f7c-4d8e-9a55-3c1f2d9e8a10",
  "status": "invoiced",
  "seller": "Ysolde Dupont",
  "comment": "Livrer lundi",
  "lines": [ { "article_id": 6, "reference": "VELOURS TIGE POM", "designation": "…", "quantity": "10.00", "unit_price_ht": "32.00", "vat_rate": "5.50", "is_free": false, "total_ht": "320.00" } ],
  "totals": { "ht": "320.00", "vat": "17.60", "ttc": "337.60" }
}
```

| Field | Meaning |
| --- | --- |
| `key` | `bl:{delivery id}` for a BL, `order:{uuid}` for an app order without a mirrored BL |
| `date` | BL date (`documentdate`); for an app order its creation date, or its sending date once sent, or its export date once exported (file mode) (Europe/Paris) |
| `status` | `pending` (app order pending, approved or failed: "en attente", never counted as a sale), `delivered` (BL not invoiced, or an app order sent whose BL the mirror has not caught yet, or exported in file mode), `invoiced` (BL status 20 `invoice` or 24 `close`), `rejected` (app order) |
| `seller` | Author of the app order the BL came from; else the app user mapped to the BL's Evoliz user (Admin → Vendeurs Evoliz); else `"Evoliz"` |
| `comment` | The app order's comment, else the BL's |
| `lines[].vat_rate` | null on a free-form Evoliz line (no VAT); `article_id` null when the article is not in the catalogue |

### `GET /api/deliveries` and `GET /api/delivery-lines`

Delta listings for the phone's mirror of the whole BL history (Dexie tables `deliveries` and `delivery_lines`), with the query and envelope of the [offline mirror](#offline-mirror) but **1000 rows per page**.

`GET /api/deliveries`: every mirrored BL, soft-deleted ones included with `deleted_at` (a BL that disappeared from Evoliz; the phone drops it and its lines).

```json
{ "data": [ { "id": 812, "document_number": "BL-20269001100", "client_id": 7, "client_name": "SARL FICTIF", "order_uuid": "…", "order_number": "C-2026-0012", "date": "2026-09-02", "invoiced": true, "seller_id": 2, "seller": "Ysolde Dupont", "comment": "Livrer lundi", "total_ht": "320.00", "total_vat": "17.60", "total_ttc": "337.60", "deleted_at": null, "updated_at": "…" } ], "meta": { "per_page": 1000, "next_cursor": null, "until": "…" } }
```

`GET /api/delivery-lines`: every BL line, `{"id", "delivery_id", "position", "article_id", "reference", "designation", "quantity", "unit_price_ht", "vat_rate", "is_free", "total_ht", "updated_at"}`. A BL's lines are only ever rewritten all together, with one `updated_at` and new ids, so a delta that carries one line of a BL carries all of them: the phone replaces the lines of every BL it sees in a delta. Mapping an Evoliz user to a seller moves `updated_at` on that user's BLs.

