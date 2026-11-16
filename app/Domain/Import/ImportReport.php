<?php

namespace App\Domain\Import;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What an import did, line by line, and the run itself: a dry run is the real
 * import rolled back, so the preview cannot differ from what applying does.
 * The activity log gets the counts only: the file holds personal data.
 */
final class ImportReport
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const REJECTED = 'error';

    /** @var array<string, int> */
    private array $counts = [self::CREATED => 0, self::UPDATED => 0, self::UNCHANGED => 0, self::REJECTED => 0];

    private int $warnings = 0;

    /** @var list<array{line: int, key: string, action: string, fields: list<string>, messages: list<string>}> */
    private array $lines = [];

    /**
     * @param  list<string>  $fileWarnings
     */
    private function __construct(private readonly array $fileWarnings) {}

    /**
     * Keys are compared after trimming, case-insensitively.
     */
    public static function key(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * Runs $row for each row with a usable key; a duplicate key is an error, the first row wins.
     *
     * @param  'articles'|'clients'  $kind
     * @param  Closure(int, string, array<string, string>, self): void  $row  May throw RowRejected
     */
    public static function run(string $kind, string $keyColumn, CsvFile $csv, User $user, bool $dryRun, Closure $row): self
    {
        $report = new self($csv->warnings);
        $seen = [];

        DB::beginTransaction();

        try {
            foreach ($csv->rows as ['line' => $line, 'cells' => $cells]) {
                $key = $cells[$keyColumn] ?? '';

                try {
                    if ($key === '') {
                        throw new RowRejected(__('Column ":column" is empty.', ['column' => $keyColumn]));
                    }

                    if (mb_strlen($key) > 255) {
                        throw new RowRejected(__('Column ":column" is too long.', ['column' => $keyColumn]));
                    }

                    if (isset($seen[self::key($key)])) {
                        throw new RowRejected(__('Already in the file at line :line: the first row wins.', ['line' => $seen[self::key($key)]]));
                    }

                    $seen[self::key($key)] = $line;
                    $row($line, $key, $cells, $report);
                } catch (RowRejected $e) {
                    $report->add($line, $key, self::REJECTED, [], [$e->getMessage()]);
                }
            }

            if (! $dryRun) {
                ActivityLog::record(ActivityAction::Imported, $user, null, ['kind' => $kind, ...$report->counts, 'warnings' => $report->warnings]);
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $report;
    }

    /**
     * @param  list<string>  $fields  Template columns that change
     * @param  list<string>  $messages  Warnings, or the reason of a rejected row
     */
    public function add(int $line, string $key, string $action, array $fields = [], array $messages = []): void
    {
        $this->counts[$action]++;

        if ($action !== self::REJECTED) {
            $this->warnings += count($messages);
        }

        $this->lines[] = ['line' => $line, 'key' => $key, 'action' => $action, 'fields' => $fields, 'messages' => $messages];
    }

    /**
     * The preview: counts, and every line with something to say.
     *
     * @return array{created: int, updated: int, unchanged: int, errors: int, warnings: int, file_warnings: list<string>, lines: list<array{line: int, key: string, action: string, fields: list<string>, messages: list<string>}>}
     */
    public function toArray(): array
    {
        return [
            'created' => $this->counts[self::CREATED],
            'updated' => $this->counts[self::UPDATED],
            'unchanged' => $this->counts[self::UNCHANGED],
            'errors' => $this->counts[self::REJECTED],
            'warnings' => $this->warnings + count($this->fileWarnings),
            'file_warnings' => $this->fileWarnings,
            'lines' => array_values(array_filter($this->lines, fn (array $line) => $line['action'] !== self::UNCHANGED || $line['messages'] !== [])),
        ];
    }
}
