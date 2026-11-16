<?php

namespace App\Console\Commands;

use App\Http\Api\ApiAliases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Gives a database the current table and column names where the installation's profile says it still has older
 * ones (aliases.schema), together with their sequence, constraint and index names, and renames the same field names
 * inside the stored JSON (client corrections and the order snapshots of the activity log: aliases.client and
 * aliases.summary). --undo goes back to the older names. Only names change; running it twice changes nothing.
 * A profile without aliases has nothing to rename. Run it with the app stopped (docs/RUNBOOK.md "Older names").
 */
class RenameLegacyCommand extends Command
{
    protected $signature = 'passorde:rename-legacy
        {--undo : Back to the older names}
        {--dry-run : Say what would change, change nothing}';

    protected $description = 'Rename tables, columns and stored JSON keys from the profile\'s older names (aliases.schema) to the current ones';

    /** Ends a dry run's transaction: everything is rolled back, only the list is printed. */
    private const DRY_RUN = 'passorde:rename-legacy dry run';

    /** @var list<string> */
    private array $done = [];

    public function handle(): int
    {
        $this->done = [];
        $schema = (array) config('passorde.aliases.schema', []);
        /** @var array<string, string> $tables current => old */
        $tables = array_filter((array) ($schema['tables'] ?? []), 'is_string');
        /** @var array<string, array<string, string>> $columns current table => [current column => old column] */
        $columns = array_map(fn ($map) => array_filter((array) $map, 'is_string'), (array) ($schema['columns'] ?? []));
        $undo = (bool) $this->option('undo');

        if ($tables === [] && array_filter($columns) === [] && ApiAliases::client() === [] && ApiAliases::summary() === []) {
            $this->info('The profile names no older names (aliases): nothing to rename.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        try {
            DB::transaction(function () use ($tables, $columns, $undo, $dry) {
                if ($undo) {
                    $this->renameJson(array_flip(ApiAliases::client()), array_flip(ApiAliases::summary()));
                    foreach ($columns as $table => $map) {
                        $this->renameColumns((string) $table, $map, true);
                    }
                    foreach ($tables as $current => $old) {
                        $this->renameTable((string) $current, $old);
                    }
                } else {
                    foreach ($tables as $current => $old) {
                        $this->renameTable($old, (string) $current);
                    }
                    foreach ($columns as $table => $map) {
                        $this->renameColumns((string) $table, $map, false);
                    }
                    $this->renameJson(ApiAliases::client(), ApiAliases::summary());
                }
                if ($dry) {
                    throw new RuntimeException(self::DRY_RUN);
                }
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== self::DRY_RUN) {
                throw $e;
            }
        }

        foreach ($this->done as $line) {
            $this->line($line);
        }
        $this->info(($dry ? 'Would rename: ' : 'Renamed: ').count($this->done).' name(s).');

        return self::SUCCESS;
    }

    private function renameTable(string $from, string $to): void
    {
        if (! Schema::hasTable($from) || Schema::hasTable($to)) {
            return;
        }

        $this->statement(sprintf('ALTER TABLE %s RENAME TO %s', $from, $to), "table {$from} → {$to}");
        if ($this->exists("SELECT 1 FROM pg_class WHERE relkind = 'S' AND relname = ?", "{$from}_id_seq")) {
            $this->statement(sprintf('ALTER SEQUENCE %s_id_seq RENAME TO %s_id_seq', $from, $to), "sequence {$from}_id_seq → {$to}_id_seq");
        }
        $this->renameObjects($to, fn (string $name) => str_starts_with($name, "{$from}_") ? $to.substr($name, strlen($from)) : $name);
    }

    /**
     * @param  array<string, string>  $map  current column => old column
     */
    private function renameColumns(string $currentTable, array $map, bool $undo): void
    {
        $table = Schema::hasTable($currentTable) ? $currentTable : (string) (config("passorde.aliases.schema.tables.{$currentTable}") ?? $currentTable);
        foreach ($map as $current => $old) {
            [$from, $to] = $undo ? [$current, $old] : [$old, $current];
            if (! Schema::hasColumn($table, $from) || Schema::hasColumn($table, $to)) {
                continue;
            }
            $this->statement(sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $table, $from, $to), "column {$table}.{$from} → {$to}");
            $this->renameObjects($table, function (string $name) use ($table, $from, $to) {
                if (! str_starts_with($name, "{$table}_")) {
                    return $name;
                }

                return $table.(string) preg_replace('/(?<=_)'.preg_quote($from, '/').'(?=_|$)/', $to, substr($name, strlen($table)));
            });
        }
    }

    /**
     * The constraints of a table, then its other indexes (a constraint's own index follows its rename).
     *
     * @param  callable(string): string  $rename
     */
    private function renameObjects(string $table, callable $rename): void
    {
        $constraints = DB::select("SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND contype IN ('p', 'u', 'f', 'c', 'x') ORDER BY conname", [$table]);
        foreach ($constraints as $row) {
            $to = $rename($row->conname);
            if ($to !== $row->conname) {
                $this->statement(sprintf('ALTER TABLE %s RENAME CONSTRAINT %s TO %s', $table, $row->conname, $to), "constraint {$row->conname} → {$to}");
            }
        }

        $indexes = DB::select('SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname NOT IN (SELECT conname FROM pg_constraint) ORDER BY indexname', [$table]);
        foreach ($indexes as $row) {
            $to = $rename($row->indexname);
            if ($to !== $row->indexname) {
                $this->statement(sprintf('ALTER INDEX %s RENAME TO %s', $row->indexname, $to), "index {$row->indexname} → {$to}");
            }
        }
    }

    /**
     * The field names inside the stored JSON: the keys of client_changes.diff and of the order snapshots'
     * client_changes (client map), and the snapshots' own keys (summary map). Key order is kept; the text is
     * rewritten as the models' array casts write it (json_encode, no flags).
     *
     * @param  array<string, string>  $client  key to rename => new key
     * @param  array<string, string>  $summary  key to rename => new key
     */
    private function renameJson(array $client, array $summary): void
    {
        foreach ($client as $to => $from) {
            DB::table('client_changes')->whereRaw('jsonb_exists(diff::jsonb, ?)', [$from])->eachById(function (object $row) use ($from, $to) {
                DB::table('client_changes')->where('id', $row->id)->update(['diff' => json_encode(self::renameKey((array) json_decode($row->diff, true), $from, $to))]);
                $this->done[] = "client_changes {$row->id}: {$from} → {$to}";
            });
        }

        $keys = [...array_values($client), ...array_values($summary)];
        if ($keys === []) {
            return;
        }
        $pattern = '"('.implode('|', array_map(fn (string $key) => preg_quote($key, '/'), $keys)).')"';
        DB::table('activity_log')->whereRaw('meta_json::text ~ ?', [$pattern])->eachById(function (object $row) use ($client, $summary) {
            $meta = json_decode((string) $row->meta_json, true);
            $before = $meta;
            foreach (['before', 'after'] as $side) {
                if (! is_array($meta[$side] ?? null)) {
                    continue;
                }
                foreach ($summary as $to => $from) {
                    $meta[$side] = self::renameKey($meta[$side], $from, $to);
                }
                if (is_array($meta[$side]['client_changes'] ?? null)) {
                    foreach ($client as $to => $from) {
                        $meta[$side]['client_changes'] = self::renameKey($meta[$side]['client_changes'], $from, $to);
                    }
                }
            }
            if ($meta !== $before) {
                DB::table('activity_log')->where('id', $row->id)->update(['meta_json' => json_encode($meta)]);
                $this->done[] = "activity_log {$row->id}: field names";
            }
        });
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function renameKey(array $values, string $from, string $to): array
    {
        if (! array_key_exists($from, $values) || array_key_exists($to, $values)) {
            return $values;
        }

        $renamed = [];
        foreach ($values as $key => $value) {
            $renamed[$key === $from ? $to : $key] = $value;
        }

        return $renamed;
    }

    private function exists(string $sql, string $value): bool
    {
        return DB::selectOne($sql, [$value]) !== null;
    }

    private function statement(string $sql, string $what): void
    {
        DB::statement($sql);
        $this->done[] = $what;
    }
}
