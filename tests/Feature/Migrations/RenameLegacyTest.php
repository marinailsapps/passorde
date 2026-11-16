<?php

use App\Domain\Clients\ClientExtraField;
use App\Domain\Orders\OrderChanges;
use App\Models\Article;
use App\Models\Client;
use App\Models\User;
use App\Support\Profile\Profile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| php artisan passorde:rename-legacy, for the fictional installation of tests/Fixtures/profiles/renamed: tables,
| columns and their constraints, indexes and sequences, and the field names inside the stored JSON (client
| corrections, order snapshots) go from the profile's older names to the current ones; --undo goes back. Names only;
| a second run changes nothing. A database under the older names is made here with --undo.
*/

beforeEach(fn () => renamedInstallation());

it('renames the columns, the table and the stored JSON keys, and undoes it all', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();
    Artisan::call('passorde:rename-legacy', ['--undo' => true]);

    expect(Schema::hasColumns('clients', ['member_code']))->toBeTrue()->and(Schema::hasTable('family_thresholds'))->toBeTrue();

    DB::table('clients')->where('id', $client->id)->update(['member_code' => 'K-1']);
    DB::table('family_thresholds')->insert(['family' => 'POMMIER', 'threshold' => 4, 'created_at' => now(), 'updated_at' => now()]);
    $diff = '{"phone":{"from":"06","to":"07"},"member_code":{"from":null,"to":"FR-\u00e9"}}';
    $change = DB::table('client_changes')->insertGetId(['client_id' => $client->id, 'source' => 'order', 'status' => 'pending', 'diff' => $diff, 'created_at' => now(), 'updated_at' => now()]);
    $meta = ['before' => ['total_pieces' => '2.00', 'client_changes' => ['town' => ['from' => 'A', 'to' => 'B'], 'member_code' => ['from' => null, 'to' => '1']]],
        'after' => ['total_pieces' => '3.00', 'client_changes' => null]];
    $log = DB::table('activity_log')->insertGetId(['user_id' => $user->id, 'action' => 'updated', 'meta_json' => json_encode($meta), 'created_at' => now()]);

    Artisan::call('passorde:rename-legacy');

    expect(Schema::hasColumns('clients', ['extra_field']))->toBeTrue()
        ->and(Schema::hasColumns('article_attributes', ['category', 'variant']))->toBeTrue()
        ->and(Schema::hasColumns('article_requests', ['category', 'variant']))->toBeTrue()
        ->and(Schema::hasColumns('orders', ['total_quantity']))->toBeTrue()
        ->and(DB::table('category_thresholds')->where('category', 'POMMIER')->value('threshold'))->toBe(4)
        ->and($client->fresh()->extra_field)->toBe('K-1')
        ->and(DB::table('client_changes')->where('id', $change)->value('diff'))->toBe('{"phone":{"from":"06","to":"07"},"extra_field":{"from":null,"to":"FR-\u00e9"}}');

    $renamed = json_decode((string) DB::table('activity_log')->where('id', $log)->value('meta_json'), true);
    expect($renamed['before'])->toHaveKey('total_quantity', '2.00')->not->toHaveKey('total_pieces')
        ->and($renamed['before']['client_changes'])->toHaveKey('extra_field')->not->toHaveKey('member_code')
        // The history reads the optional field last, as it did under its old (longest) name.
        ->and(OrderChanges::describe(['client' => ['id' => 1], 'paytype' => null, 'comment' => null, 'lines' => [], 'client_changes' => []],
            ['client' => ['id' => 1], 'paytype' => null, 'comment' => null, 'lines' => [], 'client_changes' => $renamed['before']['client_changes']])[1])
        ->toContain(mb_strtolower(ClientExtraField::label()));

    Artisan::call('passorde:rename-legacy', ['--undo' => true]);
    expect(DB::table('client_changes')->where('id', $change)->value('diff'))->toBe($diff)
        ->and(json_decode((string) DB::table('activity_log')->where('id', $log)->value('meta_json'), true))->toEqual($meta)
        ->and(Schema::hasColumns('article_attributes', ['family', 'kind']))->toBeTrue();

    Artisan::call('passorde:rename-legacy');
});

it('checks the profile against a database still under the older names, as deploy.sh does before the rename', function () {
    Artisan::call('passorde:rename-legacy', ['--undo' => true]);
    $article = Article::factory()->create();
    DB::table('article_attributes')->insert(['article_id' => $article->id, 'family' => 'NOT LISTED', 'kind' => 'nu', 'needs_review' => false, 'source' => 'auto', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('family_thresholds')->insert(['family' => 'POMMIER', 'threshold' => 4, 'created_at' => now(), 'updated_at' => now()]);

    $exit = Artisan::call('passorde:check-profile', ['--path' => base_path('tests/Fixtures/profiles/pinned')]);
    $output = Artisan::output();

    Artisan::call('passorde:rename-legacy');

    // It ran (no "column does not exist"), and still compares the stored values, under their old names.
    expect($output)->not->toContain('does not exist')
        ->toContain('1 row(s) of article_attributes.family hold "NOT LISTED", which catalog.categories does not list.')
        ->and($exit)->toBe(1);

    DB::table('article_attributes')->where('article_id', $article->id)->update(['category' => 'POMMIER']);
    expect(Artisan::call('passorde:check-profile', ['--path' => base_path('tests/Fixtures/profiles/pinned')]))->toBe(0);
});

it('renames the JSON keys of every row, past one chunk', function () {
    $client = Client::factory()->create();
    Artisan::call('passorde:rename-legacy', ['--undo' => true]);
    $rows = 2500; // the chunk is 1000: an offset paging over rows that stop matching skipped some
    $now = now()->toDateTimeString();
    foreach (array_chunk(range(1, $rows), 500) as $chunk) {
        DB::table('client_changes')->insert(array_map(fn (int $i) => ['client_id' => $client->id, 'source' => 'order', 'status' => 'applied',
            'diff' => json_encode(['member_code' => ['from' => null, 'to' => "FR-{$i}"]]), 'created_at' => $now, 'updated_at' => $now], $chunk));
        DB::table('activity_log')->insert(array_map(fn (int $i) => ['action' => 'updated', 'created_at' => $now,
            'meta_json' => json_encode(['before' => ['total_pieces' => "{$i}.00", 'client_changes' => ['member_code' => ['from' => null, 'to' => "FR-{$i}"]]], 'after' => ['total_pieces' => '1.00']])], $chunk));
    }

    Artisan::call('passorde:rename-legacy');

    expect(DB::table('client_changes')->whereRaw("jsonb_exists(diff::jsonb, 'member_code')")->count())->toBe(0)
        ->and(DB::table('client_changes')->whereRaw("jsonb_exists(diff::jsonb, 'extra_field')")->count())->toBe($rows)
        ->and(DB::table('activity_log')->whereRaw("meta_json::text ~ '\"(member_code|total_pieces)\"'")->count())->toBe(0)
        ->and(DB::table('activity_log')->whereRaw("jsonb_exists(meta_json->'before', 'total_quantity')")->count())->toBe($rows);

    Artisan::call('passorde:rename-legacy', ['--undo' => true]);
    expect(DB::table('client_changes')->whereRaw("jsonb_exists(diff::jsonb, 'member_code')")->count())->toBe($rows)
        ->and(DB::table('activity_log')->whereRaw("meta_json::text ~ '\"(extra_field|total_quantity)\"'")->count())->toBe(0);
    Artisan::call('passorde:rename-legacy');
});

/**
 * Names of every table, column, constraint, index and sequence of the schema, as one sorted list.
 *
 * @return list<string>
 */
function schemaNames(): array
{
    $rows = DB::select(<<<'SQL'
        SELECT 'column ' || table_name || '.' || column_name AS name FROM information_schema.columns WHERE table_schema = current_schema()
        UNION ALL SELECT 'constraint ' || conrelid::regclass::text || '.' || conname FROM pg_constraint WHERE connamespace = current_schema()::regnamespace
        UNION ALL SELECT 'index ' || tablename || '.' || indexname FROM pg_indexes WHERE schemaname = current_schema()
        UNION ALL SELECT 'sequence ' || relname FROM pg_class WHERE relkind = 'S' AND relnamespace = current_schema()::regnamespace
        SQL);
    $names = array_map(fn (object $row) => (string) $row->name, $rows);
    sort($names);

    return $names;
}

it('brings a database built under the older names, independently of the command, to the current schema', function () {
    $current = schemaNames();

    // The database as an installation under the fictional older names would have created it (Laravel's own naming).
    foreach ([
        'ALTER TABLE category_thresholds RENAME TO family_thresholds',
        'ALTER SEQUENCE category_thresholds_id_seq RENAME TO family_thresholds_id_seq',
        'ALTER TABLE family_thresholds RENAME CONSTRAINT category_thresholds_pkey TO family_thresholds_pkey',
        'ALTER TABLE family_thresholds RENAME CONSTRAINT category_thresholds_category_unique TO family_thresholds_family_unique',
        'ALTER TABLE family_thresholds RENAME CONSTRAINT category_thresholds_updated_by_foreign TO family_thresholds_updated_by_foreign',
        'ALTER TABLE family_thresholds RENAME COLUMN category TO family',
        'ALTER TABLE clients RENAME COLUMN extra_field TO member_code',
        'ALTER TABLE article_attributes RENAME COLUMN category TO family',
        'ALTER TABLE article_attributes RENAME COLUMN variant TO kind',
        'ALTER INDEX article_attributes_category_variant_index RENAME TO article_attributes_family_kind_index',
        'ALTER TABLE article_requests RENAME COLUMN category TO family',
        'ALTER TABLE article_requests RENAME COLUMN variant TO kind',
        'ALTER TABLE orders RENAME COLUMN total_quantity TO total_pieces',
    ] as $statement) {
        DB::statement($statement);
    }
    expect(schemaNames())->not->toBe($current)
        ->and(Schema::hasColumn('clients', 'member_code'))->toBeTrue();

    Artisan::call('passorde:rename-legacy');

    expect(schemaNames())->toBe($current);

    // A second run finds nothing left to rename.
    Artisan::call('passorde:rename-legacy');
    expect(Artisan::output())->toContain('Renamed: 0 name(s).')->and(schemaNames())->toBe($current);
});

it('renames nothing for an installation without older names, and changes nothing in a dry run', function () {
    $current = schemaNames();
    config()->set('passorde.aliases', Profile::load(null)['aliases']);
    Artisan::call('passorde:rename-legacy');
    expect(Artisan::output())->toContain('nothing to rename')->and(schemaNames())->toBe($current);

    renamedInstallation();
    Artisan::call('passorde:rename-legacy', ['--undo' => true, '--dry-run' => true]);
    expect(Artisan::output())->toContain('Would rename: ')->and(schemaNames())->toBe($current);
});
