<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\ArticleImport;
use App\Domain\Clients\ClientImport;
use App\Domain\Import\CsvReader;
use App\Domain\Import\CsvRejected;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File mode: the catalogue and the clients from a CSV file. The browser holds
 * the file and sends it twice, first as a dry run, then to apply it, and the
 * answer is plain JSON, so the personal data in it is never stored on the server.
 */
class ImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Import/Index', [
            'columns' => ['articles' => ArticleImport::columns(), 'clients' => ClientImport::columns()],
            'maxMegabytes' => CsvReader::MAX_BYTES / 1024 / 1024,
        ]);
    }

    public function store(Request $request, ArticleImport $articles, ClientImport $clients, string $kind): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.(CsvReader::MAX_BYTES / 1024)],
            'dry_run' => ['required', 'boolean'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $contents = (string) file_get_contents($validated['file']->getRealPath());
        $dryRun = (bool) $validated['dry_run'];

        try {
            $report = $kind === 'articles'
                ? $articles->run($contents, $user, $dryRun)
                : $clients->run($contents, $user, $dryRun);
        } catch (CsvRejected $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return response()->json($report->toArray());
    }

    /**
     * A template with one fictional row, as French Excel opens it directly.
     */
    public function template(string $kind): StreamedResponse
    {
        [$columns, $row] = $kind === 'articles'
            ? [ArticleImport::columns(), ArticleImport::templateRow()]
            : [ClientImport::columns(), ClientImport::templateRow()];

        return response()->streamDownload(function () use ($columns, $row) {
            $output = fopen('php://output', 'w');
            assert($output !== false);
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $columns, ';', '"', '');
            fputcsv($output, $row, ';', '"', '');
            fclose($output);
        }, "modele-{$kind}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
