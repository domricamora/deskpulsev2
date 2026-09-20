<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WiseAccount;
use App\Services\Payroll\WisePayouts;
use App\Services\Reporting\SessionStats;
use App\Support\Flash;
use App\Support\Token;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * `/app/wise` — where each employee's money is sent.
 *
 * Capability `wise_manage`. Details can be typed in one at a time, or lifted
 * from a Wise export with the same preview-then-commit flow the payroll import
 * uses.
 *
 * The import **never creates people**. A payout sheet says where to send
 * money; it is not a roster, and inventing an employee from a payments file is
 * how somebody who does not work here ends up getting paid. Unmatched rows are
 * listed for a human to look at.
 *
 * @see docs/migration/payroll.md §3
 */
class WiseController extends Controller
{
    private const DIRECTORY = 'imports';

    private const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly WisePayouts $payouts,
        private readonly SessionStats $stats,
    ) {}

    public function show(Request $request, ?array $preview = null)
    {
        $organizationId = (int) $request->user()->effectiveOrgId();

        $people = array_values(array_filter(
            $this->stats->usersById($organizationId),
            fn (User $m) => $m->role !== UserRole::ClientViewer
        ));

        usort($people, fn (User $a, User $b) => strcasecmp($a->name, $b->name));

        return view('dashboard.wise', [
            'title'    => 'Wise payouts',
            'active'   => 'wise',
            'people'   => $people,
            'accounts' => WiseAccount::query()->where('org_id', $organizationId)->get()->keyBy('user_id'),
            'specs'    => config('imports'),
            'preview'  => $preview,
        ]);
    }

    public function update(Request $request)
    {
        $organizationId = (int) $request->user()->effectiveOrgId();

        return match ((string) $request->input('action', '')) {
            'save'    => $this->save($request, $organizationId),
            'delete'  => $this->delete($request, $organizationId),
            'preview' => $this->preview($request, $organizationId),
            'commit'  => $this->commit($request, $organizationId),
            default   => redirect('/app/wise'),
        };
    }

    private function save(Request $request, int $organizationId)
    {
        $target = User::query()
            ->whereKey((int) $request->input('user_id', 0))
            ->where('org_id', $organizationId)
            ->first();

        if (! $target) {
            return redirect('/app/wise');
        }

        $holder = substr(trim((string) $request->input('account_holder', '')), 0, 160) ?: $target->name;
        $email = strtolower(trim((string) $request->input('email', '')));
        $recipientId = substr(trim((string) $request->input('recipient_id', '')), 0, 64);

        WiseAccount::query()->updateOrCreate(
            ['org_id' => $organizationId, 'user_id' => $target->id],
            [
                'recipient_id'    => $recipientId ?: null,
                'account_holder'  => $holder,
                'email'           => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'account_summary' => substr(trim((string) $request->input('account_summary', '')), 0, 160) ?: null,
                'source_currency' => strtoupper(substr((string) $request->input('source_currency', 'USD'), 0, 8)),
                'target_currency' => strtoupper(substr((string) $request->input('target_currency', 'USD'), 0, 8)),
                'recipient_type'  => $request->input('recipient_type') === 'BUSINESS' ? 'BUSINESS' : 'PERSON',
                'source_label'    => substr(trim((string) $request->input('source_label', 'source')), 0, 40) ?: 'source',
                'reference'       => substr(trim((string) $request->input('reference', '')), 0, 80) ?: null,
                'active'          => $request->boolean('active') ? 1 : 0,
                'note'            => substr((string) $request->input('note', ''), 0, 2000) ?: null,
                'updated_at'      => gmdate('Y-m-d H:i:s'),
            ]
        );

        // Mirror onto the person, without clearing an id already set.
        $target->forceFill(array_filter([
            'wise_id'   => $recipientId ?: null,
            'wise_name' => $holder,
        ]))->save();

        Flash::success("Wise payout details saved for {$target->name}.");

        return redirect('/app/wise');
    }

    private function delete(Request $request, int $organizationId)
    {
        WiseAccount::query()
            ->whereKey((int) $request->input('wise_id', 0))
            ->where('org_id', $organizationId)
            ->delete();

        Flash::success('Wise payout details removed.');

        return redirect('/app/wise');
    }

    private function preview(Request $request, int $organizationId)
    {
        $file = $request->file('file');

        if (! $file || ! $file->isValid()) {
            Flash::error('Choose a file to upload.');

            return redirect('/app/wise');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            Flash::error('That file is larger than 20 MB.');

            return redirect('/app/wise');
        }

        if (! preg_match('/\.(xlsx|csv|tsv)$/i', (string) $file->getClientOriginalName(), $matches)) {
            Flash::error('Upload an .xlsx or .csv file.');

            return redirect('/app/wise');
        }

        $token = Token::hex(16);
        $path = self::DIRECTORY . '/wise-' . $token . '.' . strtolower($matches[1]);

        Storage::disk('private')->put($path, file_get_contents($file->getRealPath()));

        $parsed = $this->payouts->parse(Storage::disk('private')->path($path));

        if ($parsed['error']) {
            Storage::disk('private')->delete($path);
            Flash::error($parsed['error']);

            return redirect('/app/wise');
        }

        return $this->show($request, [
            'token'   => $token,
            'ext'     => strtolower($matches[1]),
            'sheet'   => $parsed['sheet'],
            'total'   => count($parsed['rows']),
            'skipped' => $parsed['skipped'],
            'summary' => $this->payouts->ingest($organizationId, $parsed['rows'], commit: false),
        ]);
    }

    private function commit(Request $request, int $organizationId)
    {
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->input('token', ''));
        $extension = preg_replace('/[^a-z]/', '', strtolower((string) $request->input('ext', 'xlsx')));
        $path = $token !== '' ? self::DIRECTORY . '/wise-' . $token . '.' . $extension : '';

        if ($path === '' || ! Storage::disk('private')->exists($path)) {
            Flash::error('The uploaded file expired — please upload it again.');

            return redirect('/app/wise');
        }

        $parsed = $this->payouts->parse(Storage::disk('private')->path($path));
        $summary = $this->payouts->ingest($organizationId, $parsed['rows'], commit: true);

        Storage::disk('private')->delete($path);

        if ($summary['fatal']) {
            Flash::error('Import failed: ' . $summary['fatal']);

            return redirect('/app/wise');
        }

        Flash::success(sprintf(
            'Payout details imported — %d matched (%d new, %d updated), %d unmatched.',
            $summary['matched'], $summary['created'], $summary['updated'], $summary['unmatched']
        ));

        return redirect('/app/wise');
    }
}
