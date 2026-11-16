<?php

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = mb_strtoupper(fake()->unique()->word());

        return [
            'evoliz_article_id' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'reference' => $name.' ART',
            'designation' => $name.' ARTICLE / LOT : '.fake()->numerify('####'),
            'unit_price_ht' => fake()->randomElement([30, 32, 52, 53]),
            'vat_rate' => 5.5,
            'sale_classification_id' => 500030,
            'is_stocked' => true,
            'stock_qty' => fake()->numberBetween(-5, 30),
            'is_active' => true,
            'synced_at' => now(),
        ];
    }

    /**
     * File mode: typed or imported, no Evoliz id.
     */
    public function imported(): static
    {
        return $this->state(fn () => ['evoliz_article_id' => null, 'sale_classification_id' => null, 'synced_at' => null]);
    }
}
