<?php

namespace Database\Factories;

use App\Enums\ClientSource;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = mb_strtoupper(fake()->lastName().' '.fake()->firstName());

        return [
            'evoliz_client_id' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'evoliz_code' => mb_substr(preg_replace('/[^A-Z]/', '', $name) ?? '', 0, 10),
            'company_name' => $name,
            'contact_name' => null,
            'address_line' => fake()->streetAddress(),
            'postcode' => fake()->postcode(),
            'town' => mb_strtoupper(fake()->city()),
            'phone' => fake()->phoneNumber(),
            'email' => null,
            'extra_field' => null,
            'source' => ClientSource::Evoliz,
            'is_active' => true,
            'synced_at' => now(),
        ];
    }

    /**
     * From a CSV import (file mode): a code of its own, no Evoliz id.
     */
    public function imported(): static
    {
        return $this->state(fn () => [
            'evoliz_client_id' => null,
            'evoliz_code' => 'C'.fake()->unique()->numerify('#####'),
            'source' => ClientSource::Import,
            'synced_at' => null,
        ]);
    }

    public function fromApp(): static
    {
        return $this->state(fn () => [
            'evoliz_client_id' => null,
            'evoliz_code' => null,
            'source' => ClientSource::App,
            'synced_at' => null,
        ]);
    }
}
