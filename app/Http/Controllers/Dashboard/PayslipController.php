<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Payslip;
use App\Services\Mail\Outbox;
use App\Services\Payroll\PayRun;
use App\Services\Payroll\Payslips;
use App\Services\Payroll\WisePayouts;
use App\Services\Reporting\SessionStats;
use App\Support\Flash;
use App\Support\Format;
use App\Support\Period;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payslips — a person's own, the PDF, and the admin's bulk run.
 *
 * Three routes with three different gates, and the middle one is the
 * interesting case:
 *
 * - `/app/payslip` — **staff only**. A client portal login is a customer, not
 *   an employee, and has no payslip.
 * - `/app/payslip.pdf` — **yours, or anybody's with `payroll`**. Checked in
 *   the handler because "your own" is not a capability.
 * - `/app/payslips` — **`payroll`**. Generate and email a whole period.
 *
 * ## Why a member can fetch their own
 *
 * `PayRun` scopes to `Visibility::userIds()`, and a member with no management
 * scope is not in their own visible set for the purposes of a team roster. So
 * a self-request that comes back empty is retried scoped to just them — the
 * legacy does the same, and without it nobody without staff could ever see
 * their own pay.
 *
 * ## Emailing is idempotent
 *
 * `payslips.emailed_at` records the send. Running the period again does not
 * re-send unless the box is ticked, because the failure mode — everybody gets
 * their payslip four times — is both alarming and hard to take back.
 *
 * @see docs/migration/payroll.md §4
 */
class PayslipController extends Controller
{
    public function __construct(
        private readonly Period $period,
        private readonly PayRun $payRun,
        private readonly Payslips $payslips,
        private readonly Outbox $outbox,
        private readonly SessionStats $stats,
    ) {}

    /** `/app/payslip` — the signed-in person's own. */
    public function mine(Request $request)
    {
        $user = $request->user();

        abort_if($user->role === UserRole::ClientViewer, Response::HTTP_FORBIDDEN, 'Not allowed.');

        $context = $this->period->context(
            $request,
            (int) $user->effectiveOrgId(),
            'pay',
            ['day', 'week', 'pay', 'month']
        );

        // A member's own daily breakdown, bucketed on CREDITABLE seconds so
        // overtime HR has not signed off does not appear as pay. Pending and
        // approved overtime are tracked separately, to say what is held back.
        $sessions = $this->stats->forUsers([(int) $user->id], $context['start'], $context['end']);
        $hourly = $this->stats->hourlyRate($user);

        $buckets = [];
        $pendingOvertime = 0;
        $approvedOvertime = 0;

        foreach ($sessions as $session) {
            $day = Period::utcToLocalDate($session->getRawOriginal('started_at'), $context['tz']);
            $buckets[$day] = ($buckets[$day] ?? 0) + $session->creditableActiveSeconds();

            match ($session->overtime_status ?? 'none') {
                'pending'  => $pendingOvertime += (int) $session->overtime_s,
                'approved' => $approvedOvertime += (int) $session->overtime_s,
                default    => null,
            };
        }

        ksort($buckets);

        $rows = [];

        foreach ($buckets as $day => $seconds) {
            if ($seconds <= 0) {
                continue;                       // only days actually credited
            }

            $hours = round($seconds / 3600, 2);

            $rows[] = ['date' => $day, 'hours' => $hours, 'pay' => $hours * $hourly, 'seconds' => $seconds];
        }

        return view('dashboard.payslip', [
            'title'            => 'Payslip',
            'active'           => 'payslip',
            'period'           => $context,
            'periods'          => Period::options(['day', 'week', 'pay', 'month']),
            'me'               => $user,
            'rows'             => $rows,
            'hourly'           => $hourly,
            'totalPay'         => array_sum(array_column($rows, 'pay')),
            'totalActive'      => array_sum(array_column($rows, 'seconds')),
            'pendingOvertime'  => $pendingOvertime,
            'approvedOvertime' => $approvedOvertime,
            'currency'         => $user->currency ?: 'USD',
        ]);
    }

    /** `/app/payslip.pdf` — stream it, to its owner or to payroll. */
    public function pdf(Request $request)
    {
        $user = $request->user();

        abort_if($user->role === UserRole::ClientViewer, Response::HTTP_FORBIDDEN, 'Not allowed.');

        $wanted = (int) $request->query('user_id', $user->id);

        // Somebody else's payslip is payroll's business, nobody else's.
        abort_if(
            $wanted !== (int) $user->id && ! $user->hasCapability(Capability::Payroll),
            Response::HTTP_FORBIDDEN,
            'Not allowed.'
        );

        $context = $this->period->context(
            $request,
            (int) $user->effectiveOrgId(),
            'pay',
            ['day', 'week', 'pay', 'month']
        );

        $row = $this->rowFor($request, $context, $wanted);

        abort_if($row === null, 404, 'No payslip data for that period.');

        $organization = Organization::query()->whereKey($user->effectiveOrgId())->firstOrFail();

        [$path] = $this->payslips->generate($row, $context, $organization, $request->boolean('refresh'));

        $name = 'payslip-' . preg_replace('/[^A-Za-z0-9]+/', '-', $row['name'])
            . '-' . $context['start_date'] . '.pdf';

        return Storage::disk('private')->response($path, $name, [
            'Content-Type'           => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** `/app/payslips` — the admin view of a whole period. */
    public function index(Request $request)
    {
        $context = $this->period->context(
            $request,
            (int) $request->user()->effectiveOrgId(),
            'pay',
            ['day', 'week', 'pay', 'month']
        );

        $run = $this->payRun->compute($request->user(), $context);

        return view('dashboard.payslips', [
            'title'    => 'Payslips',
            'active'   => 'payslips',
            'period'   => $context,
            'periods'  => Period::options(['day', 'week', 'pay', 'month']),
            'run'      => $run,
            'existing' => Payslip::query()
                ->where('period_start', $context['start_date'])
                ->where('period_end', $context['end_date'])
                ->get()
                ->keyBy('user_id'),
        ]);
    }

    /** Generate, and optionally email, the period's payslips. */
    public function generate(Request $request)
    {
        $user = $request->user();
        $organization = Organization::query()->whereKey($user->effectiveOrgId())->firstOrFail();

        // Re-resolve from the POSTed filter so the action matches the screen
        // the button was on, not whatever the default period happens to be.
        $context = $this->period->context(
            $request->merge([
                'period' => $request->input('period', 'pay'),
                'date'   => $request->input('date', ''),
                'from'   => $request->input('from', ''),
                'to'     => $request->input('to', ''),
            ]),
            (int) $user->effectiveOrgId(),
            'pay',
            ['day', 'week', 'pay', 'month']
        );

        $run = $this->payRun->compute($user, $context);
        $only = (int) $request->input('user_id', 0);
        $shouldEmail = $request->input('action') === 'generate_email';
        $resend = $request->boolean('resend');

        $made = 0;
        $sent = 0;
        $skipped = 0;
        $notes = [];

        foreach ($run['rows'] as $row) {
            if ($only && (int) $row['user_id'] !== $only) {
                continue;
            }

            // Nothing earned and nothing worked is not a payslip.
            if ($row['gross'] <= 0 && $row['hours'] <= 0) {
                $skipped++;

                continue;
            }

            [$path, $payslip] = $this->payslips->generate($row, $context, $organization, force: true);
            $made++;

            if (! $shouldEmail) {
                continue;
            }

            $address = $row['user']->email;

            if (! $address || ! filter_var($address, FILTER_VALIDATE_EMAIL)
                || WisePayouts::isSyntheticEmail($address)) {
                $notes[] = $row['name'] . ' — no usable email address';

                continue;
            }

            if ($payslip->emailed_at && ! $resend) {
                $notes[] = $row['name'] . ' — already emailed, skipped';

                continue;
            }

            $queued = $this->outbox->queue([
                'org_id'      => $organization->id,
                'user_id'     => (int) $row['user_id'],
                'to_email'    => $address,
                'to_name'     => $row['name'],
                'subject'     => 'Your payslip for ' . $context['label'],
                'text'        => $this->body($row, $context, $organization),
                'attachments' => [[
                    'path' => Storage::disk('private')->path($path),
                    'name' => 'payslip-' . $context['start_date'] . '.pdf',
                    'mime' => 'application/pdf',
                ]],
                'kind'          => 'payslip',
                'created_by_id' => (int) $user->id,
            ]);

            if ($queued) {
                $payslip->forceFill([
                    'emailed_at' => gmdate('Y-m-d H:i:s'),
                    'email_id'   => $queued->id,
                ])->save();

                $sent++;
            }
        }

        Flash::success(sprintf(
            '%d payslip(s) generated%s%s.',
            $made,
            $shouldEmail ? ", {$sent} emailed" : '',
            $skipped ? ", {$skipped} skipped (nothing to pay)" : ''
        ) . ($notes ? ' ' . implode('; ', array_slice($notes, 0, 6)) : ''));

        return redirect('/app/payslips?' . Period::queryString($context));
    }

    /**
     * One pay-run row for a person, falling back to a self-only run.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function rowFor(Request $request, array $context, int $userId): ?array
    {
        $run = $this->payRun->compute($request->user(), $context, $userId);

        return $run['rows'][0] ?? null;
    }

    /** @param  array<string, mixed>  $row */
    private function body(array $row, array $context, Organization $organization): string
    {
        $firstName = trim(explode(' ', trim((string) $row['name']))[0] ?? '');

        return "Hi {$firstName},\n\nYour payslip for {$context['label']} is attached.\n\n"
            . 'Net pay: ' . Format::money($row['net'], $row['currency']) . "\n"
            . 'Hours logged: ' . number_format($row['hours'], 2) . " h\n\n"
            . '— ' . ($organization->name ?: 'DeskPulse');
    }
}
