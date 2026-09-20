<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Import\PayrollIngest;
use App\Services\Import\PayrollSheet;
use App\Support\Flash;
use App\Support\Token;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * `/app/import` — the payroll spreadsheet import.
 *
 * Capability `data_import`. Ingests into the UPLOADER's own organization, so
 * there is no org picker and nothing to get wrong about the destination.
 *
 * ## Preview then commit, over the same code path
 *
 * The preview runs the real ingest with `commit = false` and reports what it
 * would do. A separate dry-run implementation would be a second thing to keep
 * in step with the real one, and the first time they diverged somebody would
 * commit an import that did not match what they were shown.
 *
 * The uploaded file is kept between the two steps under a random token and
 * re-parsed on commit, rather than the parsed rows being carried in the
 * session — a month of payroll does not belong in a cookie, and re-parsing
 * guarantees the commit sees exactly the bytes the preview did.
 *
 * ## The file lives on the private disk
 *
 * A payroll export is PII. It goes to the same private disk screenshots use,
 * never under the document root, and is deleted as soon as the commit is done.
 *
 * @see docs/migration/payroll.md §5
 */
class ImportController extends Controller
{
    /** Where a pending upload waits between preview and commit. */
    private const DIRECTORY = 'imports';

    /** Big enough for a year of payroll, small enough to refuse a mistake. */
    private const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly PayrollSheet $sheet,
        private readonly PayrollIngest $ingest,
    ) {}

    public function show()
    {
        return view('dashboard.import', [
            'title'   => 'Import data',
            'active'  => 'import',
            'preview' => null,
            'specs'   => config('imports'),
        ]);
    }

    public function update(Request $request)
    {
        $this->collectExpired();

        return match ((string) $request->input('action', '')) {
            'preview' => $this->preview($request),
            'commit'  => $this->commit($request),
            default   => redirect('/app/import'),
        };
    }

    private function preview(Request $request)
    {
        $file = $request->file('file');

        if (! $file || ! $file->isValid()) {
            Flash::error('No file was uploaded.');

            return redirect('/app/import');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            Flash::error('File is too large (max 20 MB).');

            return redirect('/app/import');
        }

        if (! preg_match('/\.xlsx$/i', (string) $file->getClientOriginalName())) {
            Flash::error('Please upload an .xlsx file.');

            return redirect('/app/import');
        }

        $token = Token::hex(16);
        $path = $this->pathFor($token);

        Storage::disk('private')->put($path, file_get_contents($file->getRealPath()));

        $parsed = $this->sheet->parse(Storage::disk('private')->path($path));

        if ($parsed['error']) {
            Storage::disk('private')->delete($path);
            Flash::error($parsed['error']);

            return redirect('/app/import');
        }

        return view('dashboard.import', [
            'title'   => 'Import data',
            'active'  => 'import',
            'specs'   => config('imports'),
            'preview' => [
                'token'   => $token,
                'sheet'   => $parsed['sheet'],
                'total'   => count($parsed['rows']),
                'skipped' => $parsed['skipped'],
                'summary' => $this->ingest->ingest(
                    (int) $request->user()->effectiveOrgId(),
                    $parsed['rows'],
                    commit: false
                ),
                'sample'  => array_slice($parsed['rows'], 0, 20),
            ],
        ]);
    }

    private function commit(Request $request)
    {
        // The token names a file. Strip it to the charset Token::hex produces
        // so nothing that looks like a path separator can survive.
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->input('token', ''));
        $path = $token !== '' ? $this->pathFor($token) : '';

        if ($path === '' || ! Storage::disk('private')->exists($path)) {
            Flash::error('The uploaded file expired — please upload it again.');

            return redirect('/app/import');
        }

        $parsed = $this->sheet->parse(Storage::disk('private')->path($path));

        if ($parsed['error']) {
            Storage::disk('private')->delete($path);
            Flash::error($parsed['error']);

            return redirect('/app/import');
        }

        $summary = $this->ingest->ingest(
            (int) $request->user()->effectiveOrgId(),
            $parsed['rows'],
            commit: true
        );

        Storage::disk('private')->delete($path);

        if (! empty($summary['fatal'])) {
            Flash::error('Import failed: ' . implode('; ', $summary['errors']));

            return redirect('/app/import');
        }

        $message = sprintf(
            'Import complete — clients: %d new, %d existing; employees: %d new, %d updated; time entries: %d added, %d updated.',
            $summary['clients_new'], $summary['clients_existing'],
            $summary['emps_new'], $summary['emps_updated'],
            $summary['sessions_new'], $summary['sessions_updated']
        );

        if ($summary['errors']) {
            $message .= ' (' . count($summary['errors']) . ' row error(s) skipped.)';
        }

        Flash::success($message);

        return redirect('/app/import');
    }

    /**
     * `/app/import/template/{key}` — a blank workbook with the right headers.
     *
     * Generated from the same registry the parser matches against, so the
     * template can never offer a column the importer does not accept.
     */
    public function template(string $key)
    {
        $spec = config('imports.' . $key);

        abort_if($spec === null, 404);

        $headers = array_column($spec['columns'], 'name');
        $examples = array_column($spec['columns'], 'example');

        return response()->streamDownload(function () use ($headers, $examples) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $headers);
            fputcsv($handle, $examples);
            fclose($handle);
        }, "deskpulse-{$key}-template.csv", ['Content-Type' => 'text/csv']);
    }

    private function pathFor(string $token): string
    {
        return self::DIRECTORY . '/' . $token . '.xlsx';
    }

    /** Drop uploads nobody came back to commit. */
    private function collectExpired(): void
    {
        $disk = Storage::disk('private');

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if ($disk->lastModified($file) < time() - 3600) {
                $disk->delete($file);
            }
        }
    }
}
