<?php

namespace App\Integrations\Sync\Sinks;

use App\Domain\Catalog\ArticleAttributes;
use App\Models\Article;
use App\Models\ArticleAttribute;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Passorde\Records\ArticleRecord;
use Passorde\Sinks\Contracts\ArticleSink as ArticleSinkContract;

/**
 * The Evoliz catalogue with stock, into articles. Articles missing from Evoliz
 * are kept and flagged inactive. An article the app has never linked adopts the
 * unlinked row with its reference (Adoption).
 */
final class ArticleSink implements ArticleSinkContract
{
    public function __construct(private readonly ArticleAttributes $attributes) {}

    /**
     * @param  list<ArticleRecord>  $records
     */
    public function upsert(array $records, CarbonImmutable $syncedAt): int
    {
        $ids = array_map(fn (ArticleRecord $record): int => $record->id, $records);
        $existing = Article::query()->whereIn('evoliz_article_id', $ids)->get()->keyBy('evoliz_article_id');
        // Records the app has never linked may take over a row created without Evoliz (Adoption).
        $adoptable = Adoption::articles(collect($records)
            ->reject(fn (ArticleRecord $record) => $existing->has($record->id))
            ->mapWithKeys(fn (ArticleRecord $record) => [$record->id => EvolizText::plain($record->raw['reference'] ?? null)])
            ->all());
        // Articles with report attributes (category, variant); the others get the classifier's guess.
        $classified = ArticleAttribute::query()->pluck('article_id')->flip();
        $changed = 0;

        foreach ($records as $record) {
            /** @var Article $article */
            // firstOrNew: an article added from an order during this run is not in $existing.
            $article = $existing->get($record->id) ?? Article::query()->firstOrNew(['evoliz_article_id' => $record->id]);

            if (! $article->exists && isset($adoptable[$record->id])) {
                // Re-read under lock: a push may have linked that row meanwhile (Adoption::claim), then this record
                // gets its own row.
                $candidate = $adoptable[$record->id];
                $new = $article;
                $changed += DB::transaction(fn () => (int) $this->write($record, Adoption::claim($candidate, $record->id) ?? $new, $syncedAt, $classified));

                continue;
            }

            $changed += (int) $this->write($record, $article, $syncedAt, $classified);
        }

        // Everything seen is confirmed as of now, without touching updated_at.
        Article::query()->whereIn('evoliz_article_id', $ids)->toBase()->update(['synced_at' => $syncedAt]);

        return $changed;
    }

    /**
     * The record's values on its row, then its report attributes; true when that changed the row.
     *
     * @param  Collection<int, int>  $classified  Article ids that have report attributes
     */
    private function write(ArticleRecord $record, Article $article, CarbonImmutable $syncedAt, Collection $classified): bool
    {
        $changed = false;
        $article->fill(self::map($record->raw));

        $textChanged = ! $article->exists || $article->isDirty(['reference', 'designation', 'designation_raw', 'sale_classification_code']);

        // Only real changes move updated_at, which drives the phones' delta sync.
        if (! $article->exists || $article->isDirty()) {
            $changed = true;
            $article->synced_at = Carbon::instance($syncedAt);
            $article->save();
        }

        // New article, none yet, or an automatic guess whose text changed (file and manual values are kept).
        if ($textChanged || ! $classified->has($article->id)) {
            $this->attributes->classify($article);
        }

        return $changed;
    }

    public function deactivateMissing(array $seenIds, CarbonImmutable $syncedAt): int
    {
        return Article::query()
            ->whereNotIn('evoliz_article_id', $seenIds)
            ->where('is_active', true)
            // Created after this run started reading: not gone from Evoliz.
            ->where('created_at', '<=', $syncedAt)
            ->update(['is_active' => false, 'updated_at' => $syncedAt]);
    }

    /**
     * @param  array<array-key, mixed>  $row  One article from GET /articles
     * @return array<string, mixed>
     */
    public static function map(array $row): array
    {
        $stock = $row['stocked_quantity'] ?? null;
        $classification = data_get($row, 'sale_classification.id');

        return [
            'reference' => EvolizText::plain($row['reference'] ?? null) ?? '',
            'designation' => EvolizText::cleanDesignation($row['designation_clean'] ?? $row['designation'] ?? ''),
            // Kept verbatim for free lines sent back to Evoliz.
            'designation_raw' => is_string($row['designation'] ?? null) && $row['designation'] !== '' ? $row['designation'] : null,
            'unit_price_ht' => round((float) ($row['unit_price_vat_exclude'] ?? 0), 2),
            'vat_rate' => round((float) ($row['vat'] ?? 0), 2),
            'sale_classification_id' => is_numeric($classification) ? (int) $classification : null,
            // Evoliz "sale_classification.code" (the account's codes, e.g. 55, 20; read by the classifier) and "supplier.name" (often empty).
            'sale_classification_code' => EvolizText::text(data_get($row, 'sale_classification.code')),
            'supplier_name' => EvolizText::plain(data_get($row, 'supplier.name')),
            'is_stocked' => (bool) ($row['stock_management'] ?? false),
            'stock_qty' => is_numeric($stock) ? round((float) $stock, 2) : null,
            'is_active' => (bool) ($row['enabled'] ?? true),
        ];
    }
}
