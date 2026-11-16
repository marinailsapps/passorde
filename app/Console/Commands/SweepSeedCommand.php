<?php

namespace App\Console\Commands;

use App\Domain\Orders\OrderService;
use App\Enums\ClientSource;
use App\Enums\EvolizStockStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Integrations\Sync\Push\Subject;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\Client;
use App\Models\Order;
use App\Models\Paytype;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dev only: the data tools/viewport-sweep photographs. Two users (an admin and
 * a sales user, passwords generated once and kept in storage/app/sweep, never
 * committed), one fictitious client with a long name, an order in every status
 * and two journal entries, all tagged "[sweep]". No Evoliz call. --clean
 * removes everything it created; running it again first cleans.
 */
class SweepSeedCommand extends Command
{
    public const MARKER = '[sweep]';

    public const LOGINS = ['admin' => 'sweep-admin', 'sales' => 'sweep-vendeur'];

    protected $signature = 'sweep:seed {--clean : Remove what the sweep seed created}';

    protected $description = 'Dev only: users and orders for tools/viewport-sweep';

    public function handle(OrderService $orders): int
    {
        if (! app()->environment('local')) {
            $this->error('sweep:seed only runs with APP_ENV=local.');

            return self::FAILURE;
        }

        $removed = $this->clean();
        if ($this->option('clean')) {
            $this->info("Removed {$removed} orders and the sweep users.");

            return self::SUCCESS;
        }

        $credentials = $this->credentials();
        $admin = $this->user(self::LOGINS['admin'], 'Admin Balayage', Role::Admin, $credentials['admin']);
        $sales = $this->user(self::LOGINS['sales'], 'Ysolde-Christine Balayage-Dupont', Role::User, $credentials['sales']);

        $client = Client::query()->create([
            'company_name' => 'SARL DE LA GRANDE PRAIRIE DU HAUT-EXEMPLE ET DES ENVIRONS',
            'contact_name' => 'Isaure Exemple-Specimen',
            'address_line' => '1234 route départementale des Exemples, lieu-dit La Longue Combe',
            'postcode' => '06999', 'town' => 'SAINT-EXEMPLE-SUR-LONGUE-RIVIÈRE',
            'phone' => '06 00 00 00 09', 'email' => 'balayage@example.test', 'extra_field' => 'B2-77',
            'source' => ClientSource::App, 'is_active' => true, 'created_by' => $sales->id,
        ]);

        $articles = Article::query()->where('is_active', true)->where('unit_price_ht', '>', 0)->orderByDesc('stock_qty')->limit(3)->get();
        if ($articles->count() < 3) {
            throw new RuntimeException('Not enough priced articles: run php artisan evoliz:sync-articles first.');
        }
        $paytype = Paytype::query()->where('is_enabled', true)->orderBy('id')->first()
            ?? throw new RuntimeException('No enabled payment method: run php artisan db:seed first.');

        $make = fn (User $author, array $lines, string $comment): Order => $orders->save($author, (string) Str::uuid(), [
            'client_id' => $client->id,
            'lines' => array_map(fn (array $line, int $position) => [
                'article_id' => $line[0]->id, 'quantity' => $line[1], 'unit_price_ht' => ($line[2] ?? false) ? 0 : $line[0]->unit_price_ht,
                'is_free' => $line[2] ?? false, 'position' => $position,
            ], $lines, array_keys($lines)),
            'paytype_id' => $paytype->id,
            'comment' => $comment.' '.self::MARKER,
        ])['order'];

        $pending = $make($sales, [[$articles[0], 12], [$articles[0], 1, true], [$articles[1], 3]], 'Livrer avant vendredi, appeler la veille au 06 00 00 00 09 pour confirmer l’heure.');
        $make($admin, [[$articles[2], 2]], 'Commande prise par téléphone.');
        $sent = $make($sales, [[$articles[1], 5]], 'Envoyée.');
        $sent->forceFill([
            'status' => OrderStatus::Sent, 'approved_by' => $admin->id, 'approved_at' => now(), 'sent_at' => now(),
            'evoliz_delivery_id' => 99999998, 'evoliz_delivery_number' => 'BL-20269009998',
        ])->save();
        $failed = $make($sales, [[$articles[2], 6], [$articles[2], 1, true]], 'Échec.');
        $failed->forceFill([
            'status' => OrderStatus::Failed, 'approved_by' => $admin->id, 'approved_at' => now(),
            'evoliz_error' => 'HTTP 400 — vat_number : The vat number field is required. ; address.postcode : The postcode must be 5 characters.',
        ])->save();
        $make($sales, [[$articles[0], 1]], 'Refusée.')->forceFill([
            'status' => OrderStatus::Rejected, 'rejected_reason' => 'Doublon de la commande précédente, déjà livrée la semaine dernière',
        ])->save();

        foreach ([['broken', EvolizStockStatus::Disabled, 'Envoi Evoliz désactivé (mode test)'], ['gift', EvolizStockStatus::Failed, 'HTTP 500 — Evoliz is unavailable, please try again later']] as [$kind, $status, $error]) {
            StockAdjustment::query()->create([
                'article_id' => $articles[0]->id, 'kind' => $kind, 'quantity' => 2, 'happened_on' => now('Europe/Paris')->toDateString(),
                'client_id' => $kind === 'gift' ? $client->id : null, 'note' => 'Rang 2, plants abîmés au rempotage '.self::MARKER,
                'user_id' => $admin->id, 'evoliz_status' => $status, 'evoliz_error' => $error,
            ]);
        }

        File::put(dirname($this->credentialsPath()).'/state.json', (string) json_encode([
            'pending_order' => $pending->uuid, 'sent_order' => $sent->uuid, 'failed_order' => $failed->uuid,
            'client_id' => $client->id, 'sales_user_id' => $sales->id,
        ], JSON_PRETTY_PRINT));

        $this->info('Seeded 5 orders and 2 journal entries. Logins: '.implode(', ', self::LOGINS).' (passwords in '.$this->credentialsPath().').');

        return self::SUCCESS;
    }

    private function clean(): int
    {
        return DB::transaction(function () {
            $orders = Order::withTrashed()->where('comment', 'like', '%'.self::MARKER.'%')->get();
            ActivityLog::query()->whereIn('order_id', $orders->modelKeys())->delete();
            $orders->each->forceDelete();

            StockAdjustment::withTrashed()->where('note', 'like', '%'.self::MARKER.'%')->get()->each(function (StockAdjustment $entry) {
                DB::table('passorde_requests')->where('subject_type', Subject::STOCK_ADJUSTMENT)->where('subject_id', $entry->id)->delete();
                $entry->forceDelete();
            });

            $users = User::query()->whereIn('login', self::LOGINS)->pluck('id');
            Client::query()->where('email', 'balayage@example.test')->delete();
            DB::table('sessions')->whereIn('user_id', $users)->delete();
            User::query()->whereIn('id', $users)->delete();

            return $orders->count();
        });
    }

    private function user(string $login, string $name, Role $role, string $password): User
    {
        return User::query()->create(['login' => $login, 'name' => $name, 'role' => $role, 'is_active' => true, 'password' => $password]);
    }

    /**
     * @return array{admin: string, sales: string}
     */
    private function credentials(): array
    {
        $path = $this->credentialsPath();
        if (! File::exists($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, (string) json_encode(['admin' => Str::password(20, symbols: false), 'sales' => Str::password(20, symbols: false)], JSON_PRETTY_PRINT));
        }

        /** @var array{admin: string, sales: string} */
        return json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function credentialsPath(): string
    {
        return storage_path('app/sweep/credentials.json');
    }
}
