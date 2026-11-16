<?php

namespace App\Domain\Catalog;

use App\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * File mode: moves an article's stock under a row lock, in integer cents, so
 * that two moves at the same time both count. Saving moves updated_at, so the
 * phones catch up.
 */
final class LocalStock
{
    /**
     * @return array{before: string, after: string}|null Null: the article has no stock management, nothing moved
     */
    public static function apply(int $articleId, string|float $delta): ?array
    {
        return DB::transaction(function () use ($articleId, $delta) {
            $article = Article::query()->lockForUpdate()->findOrFail($articleId);

            if (! $article->is_stocked) {
                return null;
            }

            $before = self::cents($article->stock_qty ?? '0');
            $after = $before + self::cents($delta);

            $article->stock_qty = self::format($after);
            $article->save();

            return ['before' => self::format($before), 'after' => self::format($after)];
        });
    }

    private static function cents(string|float $quantity): int
    {
        return (int) round((float) $quantity * 100);
    }

    private static function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
