<?php
/**
 * Payroll domain: employment attributes, manual pay adjustments, paid time off,
 * Wise payout details and the salary run.
 *
 * Money rules used throughout:
 *  - Worked pay uses creditable_active_s() (reports.php), so overtime that HR has
 *    not approved never reaches a payslip.
 *  - A monthly salary is prorated across the pay period by calendar days, so the
 *    two halves of a semi-monthly month (15/31 + 16/31) add back up to exactly one
 *    month's salary.
 *  - Paid leave is only *added* for hourly staff. A monthly salary already covers
 *    the day off, so adding it again would pay twice.
 */

// ─────────────────────────── Vocabulary ───────────────────────────

function employment_types(): array
{
    return ['full_time' => 'Full time', 'part_time' => 'Part time', 'contractor' => 'Contractor'];
}

function employment_label(?string $k): string
{
    return employment_types()[$k ?? 'full_time'] ?? 'Full time';
}

/** Adjustment kinds → [label, sign]. sign -1 subtracts from gross. */
function adjustment_kinds(): array
{
    return [
        'bonus'         => ['Bonus', 1],
        'commission'    => ['Commission', 1],
        'reimbursement' => ['Reimbursement', 1],
        'allowance'     => ['Allowance', 1],
        'incentive'     => ['Incentive', 1],
        'other'         => ['Other earning', 1],
        'deduction'     => ['Deduction', -1],
        'advance'       => ['Advance repayment', -1],
    ];
}

function adjustment_label(string $kind): string
{
    return adjustment_kinds()[$kind][0] ?? ucfirst($kind);
}

function adjustment_sign(string $kind): int
{
    return adjustment_kinds()[$kind][1] ?? 1;
}

// ─────────────────────────── Paid time off ───────────────────────────

/** Seed the standard leave types the first time an org opens the Leave page. */
function ensure_leave_types(int $orgId): void
{
    if (db_one('SELECT id FROM leave_types WHERE org_id = ? LIMIT 1', [$orgId])) {
        return;
    }
    foreach ([
        ['Vacation', 'vacation', 1, 15],
        ['Sick leave', 'sick', 1, 10],
        ['Public holiday', 'holiday', 1, 0],
        ['Unpaid leave', 'unpaid', 0, 0],
    ] as [$name, $code, $paid, $days]) {
        db_exec('INSERT INTO leave_types (org_id, name, code, paid, days_per_year) VALUES (?, ?, ?, ?, ?)',
            [$orgId, $name, $code, $paid, $days]);
    }
}

function leave_types_for(int $orgId, bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM leave_types WHERE org_id = ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY name';
    return db_all($sql, [$orgId]);
}

/** The ISO weekdays a member is scheduled to work; Mon–Fri when unset. */
function member_work_days(array $m): array
{
    $days = array_values(array_filter(explode(',', (string) ($m['work_days'] ?? ''))));
    return $days ?: ['1', '2', '3', '4', '5'];
}

/**
 * Working days in [from, to] (inclusive local dates) that fall on the member's
 * scheduled days. Half-day requests count 0.5 and only ever span one day.
 */
function leave_working_days(array $m, string $from, string $to, bool $halfDay = false): float
{
    $days = member_work_days($m);
    $n = 0.0;
    $d = $from;
    for ($i = 0; $i < 400 && strcmp($d, $to) <= 0; $i++) {
        if (in_array((string) ((int) date('N', strtotime($d . ' 12:00:00'))), $days, true)) {
            $n += 1.0;
        }
        $d = date_add_days($d, 1);
    }
    return $halfDay ? min($n, 0.5) : $n;
}

/**
 * Approved leave that overlaps a local date window, per user.
 * Returns [userId => ['paid_hours'=>f, 'unpaid_hours'=>f, 'days'=>f, 'by_type'=>[name=>days]]].
 * Requests are clipped to the window so a leave spanning two pay periods is split
 * across them rather than being paid twice.
 */
function leave_in_period(array $userIds, string $fromDate, string $toDate, array $usersById): array
{
    $out = [];
    if (!$userIds) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $rows = db_all(
        "SELECT lr.*, lt.name AS type_name, lt.paid
         FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id
         WHERE lr.user_id IN ($in) AND lr.status = 'approved'
           AND lr.start_date <= ? AND lr.end_date >= ?",
        array_merge($userIds, [$toDate, $fromDate])
    );
    foreach ($rows as $r) {
        $uid = (int) $r['user_id'];
        $m = $usersById[$uid] ?? [];
        $from = strcmp($r['start_date'], $fromDate) < 0 ? $fromDate : $r['start_date'];
        $to   = strcmp($r['end_date'], $toDate) > 0 ? $toDate : $r['end_date'];
        $days = leave_working_days($m, $from, $to, (bool) $r['half_day']);
        if ($days <= 0) {
            continue;
        }
        $hours = $days * (float) $r['hours_per_day'];
        $out[$uid] = $out[$uid] ?? ['paid_hours' => 0.0, 'unpaid_hours' => 0.0, 'days' => 0.0, 'by_type' => []];
        $out[$uid][$r['paid'] ? 'paid_hours' : 'unpaid_hours'] += $hours;
        $out[$uid]['days'] += $days;
        $out[$uid]['by_type'][$r['type_name']] = ($out[$uid]['by_type'][$r['type_name']] ?? 0) + $days;
    }
    return $out;
}

/** Leave taken (approved days) per type for a user in a calendar year. */
function leave_used_days(int $userId, int $year): array
{
    $rows = db_all(
        "SELECT leave_type_id, start_date, end_date, half_day, hours_per_day
         FROM leave_requests WHERE user_id = ? AND status = 'approved'
           AND start_date <= ? AND end_date >= ?",
        [$userId, "$year-12-31", "$year-01-01"]
    );
    $m = db_one('SELECT work_days FROM users WHERE id = ?', [$userId]) ?: [];
    $used = [];
    foreach ($rows as $r) {
        $from = max($r['start_date'], "$year-01-01");
        $to   = min($r['end_date'], "$year-12-31");
        $used[(int) $r['leave_type_id']] = ($used[(int) $r['leave_type_id']] ?? 0)
            + leave_working_days($m, $from, $to, (bool) $r['half_day']);
    }
    return $used;
}

/** Entitlement (days) per leave type for a user/year, falling back to the type default. */
function leave_entitlement_days(int $userId, int $orgId, int $year): array
{
    $out = [];
    foreach (leave_types_for($orgId) as $t) {
        $out[(int) $t['id']] = (float) $t['days_per_year'];
    }
    foreach (db_all('SELECT leave_type_id, days, carried_days FROM leave_entitlements WHERE user_id = ? AND year = ?',
             [$userId, $year]) as $r) {
        $out[(int) $r['leave_type_id']] = (float) $r['days'] + (float) $r['carried_days'];
    }
    return $out;
}

// ─────────────────────────── Pay adjustments ───────────────────────────

/** Approved adjustments landing in a local date window, grouped by user. */
function adjustments_in_period(array $userIds, string $fromDate, string $toDate, bool $approvedOnly = true): array
{
    if (!$userIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $sql = "SELECT * FROM pay_adjustments
            WHERE user_id IN ($in) AND effective_date >= ? AND effective_date <= ?";
    $params = array_merge($userIds, [$fromDate, $toDate]);
    if ($approvedOnly) {
        $sql .= " AND status = 'approved'";
    }
    $sql .= ' ORDER BY effective_date ASC, id ASC';
    $out = [];
    foreach (db_all($sql, $params) as $r) {
        $out[(int) $r['user_id']][] = $r;
    }
    return $out;
}

// ─────────────────────────── Wise payout details ───────────────────────────

function wise_accounts_for(int $orgId): array
{
    $out = [];
    foreach (db_all('SELECT * FROM wise_accounts WHERE org_id = ?', [$orgId]) as $r) {
        $out[(int) $r['user_id']] = $r;
    }
    return $out;
}

/**
 * Is this a real, mailable/payable address? Payroll imports mint synthetic
 * addresses for employees that only exist in a spreadsheet — those must never be
 * emailed or treated as a Wise recipient.
 */
function is_synthetic_email(?string $email): bool
{
    return $email !== null && str_ends_with(strtolower($email), '@import.deskpulse.local');
}

/**
 * Require a signed-in member of STAFF. A client portal login (client_viewer) is an
 * outside customer, not an employee: it has no leave, no payslip and no pay of any
 * kind, so every payroll-personal page must turn it away even though those pages
 * are otherwise open to any logged-in user.
 */
function require_staff(): array
{
    $u = require_login();
    if (($u['role'] ?? '') === 'client_viewer') {
        deny_access();
    }
    return $u;
}

// ─────────────────────────── The pay run ───────────────────────────

/**
 * Compute everything needed for the payroll table, a payslip and the Wise salary
 * run for one period, for every member the caller can see.
 *
 * Returns ['rows'=>[…], 'totals'=>[…], 'currency'=>…] with one row per member:
 *   hours, overtime_hours, leave_paid_hours, base, leave_pay, earnings,
 *   deductions, gross, net, adjustments[], cap flags, wise account.
 */
function compute_pay_run(array $u, array $ctx, ?int $onlyUserId = null): array
{
    $orgId     = (int) $u['org_id'];
    $cfg       = report_org_cfg($orgId);
    $usersById = users_by_id($orgId);
    $ids       = visible_user_ids($u);
    if ($onlyUserId !== null) {
        $ids = in_array($onlyUserId, $ids, true) ? [$onlyUserId] : [];
    }
    // Client portal logins are not staff and are never paid.
    $ids = array_values(array_filter($ids, fn ($id) => ($usersById[$id]['role'] ?? '') !== 'client_viewer'));
    if (!$ids) {
        return ['rows' => [], 'totals' => [], 'currency' => $cfg['pay_currency']];
    }

    $sessions = sessions_for_users($ids, $ctx['start'], $ctx['end']);
    $adjs     = adjustments_in_period($ids, $ctx['start_date'], $ctx['end_date']);
    $leave    = leave_in_period($ids, $ctx['start_date'], $ctx['end_date'], $usersById);
    $wise     = wise_accounts_for($orgId);

    // Worked seconds per user, and per user per local day (for the daily cap check).
    $secs = $ot = $daily = [];
    foreach ($sessions as $s) {
        $uid = (int) $s['user_id'];
        $c   = creditable_active_s($s);
        $secs[$uid] = ($secs[$uid] ?? 0) + $c;
        if (($s['overtime_status'] ?? 'none') === 'approved') {
            $ot[$uid] = ($ot[$uid] ?? 0) + (int) ($s['overtime_s'] ?? 0);
        }
        $day = utc_to_local_date($s['started_at'], $cfg['tz']);
        $daily[$uid][$day] = ($daily[$uid][$day] ?? 0) + $c;
    }

    // Monthly salaries are prorated by calendar days over the month the period
    // starts in, so a semi-monthly month's two halves sum to exactly one salary.
    $daysInMonth  = (int) date('t', strtotime($ctx['start_date'] . ' 12:00:00'));
    $monthFactor  = min(1.0, ((int) $ctx['days']) / max(1, $daysInMonth));

    $rows = [];
    foreach ($ids as $uid) {
        $m = $usersById[$uid] ?? null;
        if (!$m) {
            continue;
        }
        $hours   = ($secs[$uid] ?? 0) / 3600.0;
        $otHours = ($ot[$uid] ?? 0) / 3600.0;
        $rate    = user_hourly_rate($m);
        $cur     = $m['currency'] ?: $cfg['pay_currency'];
        $monthly = ($m['pay_type'] ?? 'hourly') === 'monthly';

        $base     = $monthly ? (float) $m['pay_rate'] * $monthFactor : $hours * $rate;
        $lv       = $leave[$uid] ?? ['paid_hours' => 0.0, 'unpaid_hours' => 0.0, 'days' => 0.0, 'by_type' => []];
        // A monthly salary already covers the day off — only hourly staff get paid leave added.
        $leavePay = $monthly ? 0.0 : $lv['paid_hours'] * $rate;

        $earnings = $deductions = 0.0;
        $lines = [];
        foreach ($adjs[$uid] ?? [] as $a) {
            $amt = (float) $a['amount'];
            if ((int) $a['sign'] < 0) {
                $deductions += $amt;
            } else {
                $earnings += $amt;
            }
            $lines[] = $a;
        }

        $gross = $base + $leavePay + $earnings;
        $net   = $gross - $deductions;

        // Cap checks — advisory flags, never a block on tracking.
        $flags = [];
        $periodCap = (float) ($m['period_hours_cap'] ?? 0);
        $dailyCap  = (float) ($m['daily_hours_cap'] ?? 0);
        $weeklyCap = (float) ($m['weekly_hours_cap'] ?? 0);
        if ($periodCap > 0 && $hours > $periodCap + 0.01) {
            $flags[] = sprintf('Over pay-period cap by %.2f h', $hours - $periodCap);
        }
        if ($dailyCap > 0) {
            $worst = 0.0;
            foreach ($daily[$uid] ?? [] as $d => $sec) {
                $worst = max($worst, $sec / 3600.0);
            }
            if ($worst > $dailyCap + 0.01) {
                $flags[] = sprintf('Busiest day %.2f h exceeds the %.2f h daily cap', $worst, $dailyCap);
            }
        }
        if ($weeklyCap > 0 && $ctx['days'] >= 7) {
            $weeks = max(1.0, $ctx['days'] / 7.0);
            if ($hours / $weeks > $weeklyCap + 0.01) {
                $flags[] = sprintf('Averaging %.2f h/week against a %.2f h cap', $hours / $weeks, $weeklyCap);
            }
        }

        $wa = $wise[$uid] ?? null;
        $rows[] = [
            'user' => $m, 'user_id' => $uid, 'name' => $m['name'], 'role' => $m['role'],
            'employment_type' => $m['employment_type'] ?? 'full_time',
            'pay_type' => $m['pay_type'] ?? 'hourly', 'pay_rate' => (float) $m['pay_rate'],
            'hourly' => $rate, 'currency' => $cur,
            'hours' => $hours, 'overtime_hours' => $otHours,
            'leave_paid_hours' => (float) $lv['paid_hours'],
            'leave_unpaid_hours' => (float) $lv['unpaid_hours'],
            'leave_days' => (float) $lv['days'], 'leave_by_type' => $lv['by_type'],
            'base' => $base, 'leave_pay' => $leavePay,
            'earnings' => $earnings, 'deductions' => $deductions,
            'gross' => $gross, 'net' => $net, 'adjustments' => $lines,
            'flags' => $flags,
            'wise' => $wa,
            'payable' => $wa && !empty($wa['active']) && ($wa['recipient_id'] || $wa['email']),
        ];
    }
    usort($rows, fn ($a, $b) => $b['net'] <=> $a['net']);

    $totals = ['hours' => 0.0, 'gross' => 0.0, 'net' => 0.0, 'earnings' => 0.0,
               'deductions' => 0.0, 'leave_days' => 0.0, 'headcount' => count($rows)];
    foreach ($rows as $r) {
        $totals['hours']      += $r['hours'];
        $totals['gross']      += $r['gross'];
        $totals['net']        += $r['net'];
        $totals['earnings']   += $r['earnings'];
        $totals['deductions'] += $r['deductions'];
        $totals['leave_days'] += $r['leave_days'];
    }
    return ['rows' => $rows, 'totals' => $totals, 'currency' => $cfg['pay_currency']];
}

// ─────────────────────────── Pages: pay adjustments ───────────────────────────

function dash_adjustments(): void
{
    $u = require_cap('pay_adjustments');
    $orgId = (int) $u['org_id'];

    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';
        // Org-scope every target before writing — the standard tenant guard.
        $target = db_one('SELECT id, currency FROM users WHERE id = ? AND org_id = ?',
            [(int) ($_POST['user_id'] ?? 0), $orgId]);

        if ($action === 'create' && $target) {
            $kind = array_key_exists($_POST['kind'] ?? '', adjustment_kinds()) ? $_POST['kind'] : 'bonus';
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['effective_date'] ?? '')
                ? $_POST['effective_date'] : date('Y-m-d');
            $amount = round(abs((float) ($_POST['amount'] ?? 0)), 2);
            if ($amount <= 0) {
                flash('Enter an amount greater than zero.', 'error');
            } else {
                db_exec(
                    'INSERT INTO pay_adjustments (org_id, user_id, kind, label, amount, sign, currency,
                                                  taxable, effective_date, note, status, created_by_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$orgId, $target['id'], $kind,
                     substr(trim((string) ($_POST['label'] ?? '')) ?: adjustment_label($kind), 0, 160),
                     $amount, adjustment_sign($kind),
                     substr((string) ($_POST['currency'] ?? ($target['currency'] ?: 'USD')), 0, 8),
                     isset($_POST['taxable']) ? 1 : 0, $date,
                     substr((string) ($_POST['note'] ?? ''), 0, 2000), 'approved', $u['id']]
                );
                flash('Pay adjustment added.', 'success');
            }
        } elseif ($action === 'delete') {
            db_exec('DELETE FROM pay_adjustments WHERE id = ? AND org_id = ?',
                [(int) ($_POST['adjustment_id'] ?? 0), $orgId]);
            flash('Pay adjustment removed.', 'success');
        }
        redirect('/app/adjustments' . ($_POST['back'] ?? ''));
    }

    [$start, $end, $period, $periodDate] = period_range_from_request('pay', ['day', 'week', 'pay', 'month']);
    $ctx = $GLOBALS['DP_PERIOD_CTX'];
    $ids = visible_user_ids($u);
    $usersById = users_by_id($orgId);
    $rows = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_all(
            "SELECT a.*, u.name AS user_name, c.name AS created_by
             FROM pay_adjustments a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN users c ON c.id = a.created_by_id
             WHERE a.user_id IN ($in) AND a.effective_date >= ? AND a.effective_date <= ?
             ORDER BY a.effective_date DESC, a.id DESC",
            array_merge($ids, [$ctx['start_date'], $ctx['end_date']])
        );
    }
    $members = array_values(array_filter($usersById,
        fn ($m) => in_array((int) $m['id'], $ids, true) && $m['role'] !== 'client_viewer'));
    usort($members, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

    view('dashboard/adjustments', array_merge(nav_context($u), [
        'title' => 'Pay adjustments', 'active' => 'adjustments',
        'rows' => $rows, 'members' => $members, 'period' => $period,
        'period_date' => $periodDate, 'ctx' => $ctx,
        'currency' => report_org_cfg($orgId)['pay_currency'],
    ]));
}

// ─────────────────────────── Pages: leave ───────────────────────────

function dash_leave(): void
{
    $u = require_staff();
    $orgId = (int) $u['org_id'];
    ensure_leave_types($orgId);
    $canApprove = can($u, 'leave_approve');

    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'request') {
            // Anyone may request leave for themselves; approvers may file on behalf of others.
            $forId = (int) ($_POST['user_id'] ?? $u['id']);
            if ($forId !== (int) $u['id'] && !$canApprove) {
                abort(403, 'Not allowed');
            }
            $target = db_one('SELECT * FROM users WHERE id = ? AND org_id = ?', [$forId, $orgId]);
            $type = db_one('SELECT * FROM leave_types WHERE id = ? AND org_id = ?',
                [(int) ($_POST['leave_type_id'] ?? 0), $orgId]);
            $from = $_POST['start_date'] ?? '';
            $to   = $_POST['end_date'] ?? '';
            $ok   = $target && $type
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to);
            if ($ok && strcmp($from, $to) > 0) {
                [$from, $to] = [$to, $from];
            }
            if (!$ok) {
                flash('Pick a leave type and a valid date range.', 'error');
            } else {
                $half  = !empty($_POST['half_day']) && $from === $to;
                $hpd   = max(0.5, min(24.0, (float) ($_POST['hours_per_day'] ?? 8)));
                $days  = leave_working_days($target, $from, $to, $half);
                if ($days <= 0) {
                    flash('That range contains no scheduled working days for this member.', 'error');
                } else {
                    // An approver filing directly is trusted; a self-request waits for review.
                    $status = $canApprove ? 'approved' : 'pending';
                    db_exec(
                        'INSERT INTO leave_requests (org_id, user_id, leave_type_id, start_date, end_date,
                                hours_per_day, total_days, total_hours, half_day, reason, status,
                                reviewed_by_id, reviewed_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$orgId, $target['id'], $type['id'], $from, $to, $hpd, $days, $days * $hpd,
                         $half ? 1 : 0, substr((string) ($_POST['reason'] ?? ''), 0, 2000), $status,
                         $canApprove ? $u['id'] : null, $canApprove ? gmdate('Y-m-d H:i:s') : null]
                    );
                    flash($status === 'approved'
                        ? 'Leave recorded.'
                        : 'Leave request submitted for approval.', 'success');
                }
            }
        } elseif ($action === 'review' && $canApprove) {
            $req = db_one('SELECT * FROM leave_requests WHERE id = ? AND org_id = ?',
                [(int) ($_POST['request_id'] ?? 0), $orgId]);
            $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approved' : 'rejected';
            if ($req) {
                db_exec('UPDATE leave_requests SET status = ?, reviewed_by_id = ?, reviewed_at = ?, review_note = ?
                         WHERE id = ?',
                    [$decision, $u['id'], gmdate('Y-m-d H:i:s'),
                     substr((string) ($_POST['review_note'] ?? ''), 0, 2000), $req['id']]);
                flash('Leave request ' . $decision . '.', 'success');
            }
        } elseif ($action === 'cancel') {
            // A member may withdraw their own request while it is still pending.
            $req = db_one('SELECT * FROM leave_requests WHERE id = ? AND org_id = ?',
                [(int) ($_POST['request_id'] ?? 0), $orgId]);
            if ($req && ((int) $req['user_id'] === (int) $u['id'] || $canApprove)) {
                db_exec("UPDATE leave_requests SET status = 'cancelled' WHERE id = ?", [$req['id']]);
                flash('Leave request cancelled.', 'success');
            }
        } elseif ($action === 'save_type' && $canApprove) {
            $id = (int) ($_POST['type_id'] ?? 0);
            $name = substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
            $code = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', substr(trim((string) ($_POST['code'] ?? '')), 0, 24)));
            if ($name && $code) {
                if ($id && db_one('SELECT id FROM leave_types WHERE id = ? AND org_id = ?', [$id, $orgId])) {
                    db_exec('UPDATE leave_types SET name = ?, paid = ?, days_per_year = ?, active = ? WHERE id = ?',
                        [$name, isset($_POST['paid']) ? 1 : 0, (float) ($_POST['days_per_year'] ?? 0),
                         isset($_POST['active']) ? 1 : 0, $id]);
                } else {
                    db_exec('INSERT INTO leave_types (org_id, name, code, paid, days_per_year)
                             VALUES (?, ?, ?, ?, ?)',
                        [$orgId, $name, $code, isset($_POST['paid']) ? 1 : 0, (float) ($_POST['days_per_year'] ?? 0)]);
                }
                flash('Leave type saved.', 'success');
            }
        } elseif ($action === 'save_entitlement' && $canApprove) {
            $target = db_one('SELECT id FROM users WHERE id = ? AND org_id = ?',
                [(int) ($_POST['user_id'] ?? 0), $orgId]);
            $type = db_one('SELECT id FROM leave_types WHERE id = ? AND org_id = ?',
                [(int) ($_POST['leave_type_id'] ?? 0), $orgId]);
            $year = max(2000, min(2100, (int) ($_POST['year'] ?? date('Y'))));
            if ($target && $type) {
                db_exec('INSERT INTO leave_entitlements (org_id, user_id, leave_type_id, year, days, carried_days)
                         VALUES (?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE days = VALUES(days), carried_days = VALUES(carried_days)',
                    [$orgId, $target['id'], $type['id'], $year,
                     (float) ($_POST['days'] ?? 0), (float) ($_POST['carried_days'] ?? 0)]);
                flash('Entitlement saved.', 'success');
            }
        }
        redirect('/app/leave');
    }

    $types = leave_types_for($orgId, false);
    $ids = $canApprove ? visible_user_ids($u) : [(int) $u['id']];
    $usersById = users_by_id($orgId);
    $requests = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $requests = db_all(
            "SELECT lr.*, lt.name AS type_name, lt.paid, us.name AS user_name, rv.name AS reviewer
             FROM leave_requests lr
             JOIN leave_types lt ON lt.id = lr.leave_type_id
             JOIN users us ON us.id = lr.user_id
             LEFT JOIN users rv ON rv.id = lr.reviewed_by_id
             WHERE lr.user_id IN ($in)
             ORDER BY FIELD(lr.status,'pending','approved','rejected','cancelled'), lr.start_date DESC
             LIMIT 300",
            $ids
        );
    }
    $year = (int) date('Y');
    $balances = [];
    foreach (leave_types_for($orgId) as $t) {
        $ent = leave_entitlement_days((int) $u['id'], $orgId, $year)[(int) $t['id']] ?? 0.0;
        $used = leave_used_days((int) $u['id'], $year)[(int) $t['id']] ?? 0.0;
        $balances[] = ['type' => $t, 'entitled' => $ent, 'used' => $used, 'left' => $ent - $used];
    }
    $members = array_values(array_filter($usersById,
        fn ($m) => in_array((int) $m['id'], $ids, true) && $m['role'] !== 'client_viewer'));
    usort($members, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

    view('dashboard/leave', array_merge(nav_context($u), [
        'title' => 'Time off', 'active' => 'leave', 'types' => $types,
        'requests' => $requests, 'balances' => $balances, 'members' => $members,
        'can_approve' => $canApprove, 'me_id' => (int) $u['id'], 'year' => $year,
    ]));
}

// ─────────────────────────── Wise payout details ───────────────────────────

/** Values that mean "this cell is broken", not data. */
function wise_is_junk(string $v): bool
{
    $v = strtoupper(trim($v));
    return $v === '' || $v === '#REF!' || $v === '#N/A' || $v === '#VALUE!' || $v === '#NAME?';
}

/**
 * Parse an uploaded Wise sheet (.xlsx or .csv) into normalized rows.
 * Returns ['rows'=>[…], 'header_row'=>n, 'sheet'=>…, 'skipped'=>['count'=>n,'reasons'=>[…]], 'error'=>?].
 */
function wise_parse_file(string $path): array
{
    $spec = import_spec('wise');
    $need = ['Wise Name'];

    // Try every worksheet (a workbook often has the payout tab second).
    $candidates = [];
    if (preg_match('/\.(csv|tsv|txt)$/i', $path)) {
        $candidates[] = [null, csv_rows($path)];
    } else {
        foreach (xlsx_sheet_names($path) ?: [null] as $sn) {
            $candidates[] = [$sn, xlsx_rows($path, $sn)];
        }
    }

    $best = null;
    foreach ($candidates as [$sheet, $rows]) {
        if (!$rows) {
            continue;
        }
        [$hr, $map] = sheet_find_header($rows, $need);
        if ($hr > 0) {
            $best = [$sheet, $rows, $hr, $map];
            break;
        }
    }
    if (!$best) {
        return ['rows' => [], 'header_row' => 0, 'sheet' => null,
                'skipped' => ['count' => 0, 'reasons' => []],
                'error' => 'Could not find a header row. The sheet needs at least a "Wise Name" column — '
                         . 'see the column guide below for the accepted names.'];
    }
    [$sheet, $rows, $headerRow, $map] = $best;
    $col = import_cell_reader($spec, $map);

    $out = [];
    $skipped = 0;
    $reasons = [];
    foreach ($rows as $rowNum => $row) {
        if ($rowNum <= $headerRow) {
            continue;
        }
        $name = $col($row, 'Wise Name');
        $rid  = $col($row, 'Wise Recipient ID');
        $mail = $col($row, 'EMAIL');
        // Fully blank spacer rows are normal in exports — skip silently.
        if (wise_is_junk($name) && wise_is_junk($rid) && wise_is_junk($mail)) {
            if (trim(implode('', $row)) === '') {
                continue;
            }
        }
        // A repeated header block inside the data (common in these exports).
        if (sheet_norm_label($name) === 'wise name' || sheet_norm_label($rid) === 'wise recipient id') {
            continue;
        }
        if (wise_is_junk($name)) {
            $skipped++;
            $reasons['Broken or empty "Wise Name"'] = ($reasons['Broken or empty "Wise Name"'] ?? 0) + 1;
            continue;
        }
        $type = strtoupper($col($row, 'Type'));
        $out[] = [
            'row'             => $rowNum,
            'recipient_id'    => wise_is_junk($rid) ? '' : $rid,
            'account_holder'  => $name,
            'email'           => (!wise_is_junk($mail) && filter_var($mail, FILTER_VALIDATE_EMAIL)) ? strtolower($mail) : '',
            'account_summary' => wise_is_junk($col($row, 'Wise account')) ? '' : $col($row, 'Wise account'),
            'source_currency' => strtoupper($col($row, 'from Currency')) ?: '',
            'target_currency' => strtoupper($col($row, 'to Currency')) ?: '',
            'source_label'    => wise_is_junk($col($row, 'Source')) ? '' : $col($row, 'Source'),
            'recipient_type'  => in_array($type, ['PERSON', 'BUSINESS'], true) ? $type : 'PERSON',
            'external_ref'    => wise_is_junk($col($row, 'VT ID')) ? '' : $col($row, 'VT ID'),
        ];
    }
    return ['rows' => $out, 'header_row' => $headerRow, 'sheet' => $sheet,
            'skipped' => ['count' => $skipped, 'reasons' => $reasons], 'error' => null];
}

/**
 * Match each parsed row to an employee and (when $commit) upsert the payout
 * record. Matching order: Wise Recipient ID → VT ID → email → exact name.
 * Never creates people — unmatched rows are reported so the admin can fix the
 * sheet or add the employee first.
 */
function wise_ingest(array $u, array $rows, bool $commit): array
{
    $orgId = (int) $u['org_id'];
    $sum = ['matched' => 0, 'created' => 0, 'updated' => 0, 'unmatched' => 0,
            'unmatched_names' => [], 'matches' => [], 'fatal' => null];

    $people = db_all('SELECT id, name, email, external_ref, wise_id, wise_name FROM users
                      WHERE org_id = ? AND role <> ?', [$orgId, 'client_viewer']);
    $byWise = $byRef = $byEmail = $byName = [];
    foreach ($people as $p) {
        if (!empty($p['wise_id']))      { $byWise[strtolower($p['wise_id'])] = $p; }
        if (!empty($p['external_ref'])) { $byRef[strtolower($p['external_ref'])] = $p; }
        if (!empty($p['email']))        { $byEmail[strtolower($p['email'])] = $p; }
        $byName[strtolower(trim($p['name']))] = $p;
        if (!empty($p['wise_name']))    { $byName[strtolower(trim($p['wise_name']))] = $p; }
    }
    $existing = wise_accounts_for($orgId);
    $cfg = report_org_cfg($orgId);

    if ($commit) {
        db()->beginTransaction();
    }
    try {
        foreach ($rows as $r) {
            $p = null;
            $how = '';
            if ($r['recipient_id'] !== '' && isset($byWise[strtolower($r['recipient_id'])])) {
                $p = $byWise[strtolower($r['recipient_id'])]; $how = 'Wise Recipient ID';
            } elseif ($r['external_ref'] !== '' && isset($byRef[strtolower($r['external_ref'])])) {
                $p = $byRef[strtolower($r['external_ref'])]; $how = 'VT ID';
            } elseif ($r['email'] !== '' && isset($byEmail[$r['email']])) {
                $p = $byEmail[$r['email']]; $how = 'email';
            } elseif (isset($byName[strtolower(trim($r['account_holder']))])) {
                $p = $byName[strtolower(trim($r['account_holder']))]; $how = 'name';
            }
            if (!$p) {
                $sum['unmatched']++;
                if (count($sum['unmatched_names']) < 25) {
                    $sum['unmatched_names'][] = $r['account_holder'];
                }
                continue;
            }
            $sum['matched']++;
            $uid = (int) $p['id'];
            $isNew = !isset($existing[$uid]);
            $sum[$isNew ? 'created' : 'updated']++;
            if (count($sum['matches']) < 25) {
                $sum['matches'][] = ['name' => $p['name'], 'holder' => $r['account_holder'],
                                     'how' => $how, 'new' => $isNew];
            }
            if (!$commit) {
                continue;
            }
            $src = $r['source_currency'] ?: $cfg['pay_currency'];
            $tgt = $r['target_currency'] ?: $src;
            db_exec(
                'INSERT INTO wise_accounts (org_id, user_id, recipient_id, account_holder, email,
                        account_summary, source_currency, target_currency, recipient_type, source_label, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                        recipient_id = VALUES(recipient_id), account_holder = VALUES(account_holder),
                        email = VALUES(email), account_summary = VALUES(account_summary),
                        source_currency = VALUES(source_currency), target_currency = VALUES(target_currency),
                        recipient_type = VALUES(recipient_type), source_label = VALUES(source_label),
                        updated_at = VALUES(updated_at)',
                [$orgId, $uid, $r['recipient_id'] ?: null, $r['account_holder'], $r['email'] ?: null,
                 $r['account_summary'] ?: null, $src, $tgt, $r['recipient_type'],
                 $r['source_label'] ?: 'source', gmdate('Y-m-d H:i:s')]
            );
            // Keep the identity keys on users in step — the payroll import matches on these.
            db_exec('UPDATE users SET wise_id = COALESCE(NULLIF(?, \'\'), wise_id),
                            wise_name = COALESCE(NULLIF(?, \'\'), wise_name) WHERE id = ?',
                [$r['recipient_id'], $r['account_holder'], $uid]);
        }
        if ($commit) {
            db()->commit();
        }
    } catch (\Throwable $e) {
        if ($commit && db()->inTransaction()) {
            db()->rollBack();
        }
        $sum['fatal'] = $e->getMessage();
    }
    return $sum;
}

/** Delete stale Wise upload temp files (mirrors import_gc()). */
function wise_import_gc(): void
{
    foreach (glob(upload_path('imports') . '/wise-*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 3600) {
            @unlink($f);
        }
    }
}

function dash_wise(): void
{
    $u = require_cap('wise_manage');
    $orgId = (int) $u['org_id'];
    $ctxPreview = null;

    if (request_method() === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $target = db_one('SELECT id, name FROM users WHERE id = ? AND org_id = ?',
                [(int) ($_POST['user_id'] ?? 0), $orgId]);
            if ($target) {
                $holder = substr(trim((string) ($_POST['account_holder'] ?? '')), 0, 160) ?: $target['name'];
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $email = ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
                db_exec(
                    'INSERT INTO wise_accounts (org_id, user_id, recipient_id, account_holder, email,
                            account_summary, source_currency, target_currency, recipient_type, source_label,
                            reference, active, note, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                            recipient_id = VALUES(recipient_id), account_holder = VALUES(account_holder),
                            email = VALUES(email), account_summary = VALUES(account_summary),
                            source_currency = VALUES(source_currency), target_currency = VALUES(target_currency),
                            recipient_type = VALUES(recipient_type), source_label = VALUES(source_label),
                            reference = VALUES(reference), active = VALUES(active), note = VALUES(note),
                            updated_at = VALUES(updated_at)',
                    [$orgId, $target['id'],
                     substr(trim((string) ($_POST['recipient_id'] ?? '')), 0, 64) ?: null,
                     $holder, $email,
                     substr(trim((string) ($_POST['account_summary'] ?? '')), 0, 160) ?: null,
                     strtoupper(substr((string) ($_POST['source_currency'] ?? 'USD'), 0, 8)),
                     strtoupper(substr((string) ($_POST['target_currency'] ?? 'USD'), 0, 8)),
                     ($_POST['recipient_type'] ?? 'PERSON') === 'BUSINESS' ? 'BUSINESS' : 'PERSON',
                     substr(trim((string) ($_POST['source_label'] ?? 'source')), 0, 40) ?: 'source',
                     substr(trim((string) ($_POST['reference'] ?? '')), 0, 80) ?: null,
                     isset($_POST['active']) ? 1 : 0,
                     substr((string) ($_POST['note'] ?? ''), 0, 2000) ?: null,
                     gmdate('Y-m-d H:i:s')]
                );
                db_exec('UPDATE users SET wise_id = COALESCE(NULLIF(?, \'\'), wise_id), wise_name = ? WHERE id = ?',
                    [trim((string) ($_POST['recipient_id'] ?? '')), $holder, $target['id']]);
                flash('Wise payout details saved for ' . $target['name'] . '.', 'success');
            }
            redirect('/app/wise');
        }

        if ($action === 'delete') {
            db_exec('DELETE FROM wise_accounts WHERE id = ? AND org_id = ?',
                [(int) ($_POST['wise_id'] ?? 0), $orgId]);
            flash('Wise payout details removed.', 'success');
            redirect('/app/wise');
        }

        if ($action === 'preview') {
            wise_import_gc();
            $file = $_FILES['file'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                flash('Choose a file to upload.', 'error');
                redirect('/app/wise');
            }
            if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
                flash('That file is larger than 20 MB.', 'error');
                redirect('/app/wise');
            }
            if (!preg_match('/\.(xlsx|csv|tsv)$/i', (string) ($file['name'] ?? ''), $m)) {
                flash('Upload an .xlsx or .csv file.', 'error');
                redirect('/app/wise');
            }
            $ext = strtolower($m[1]);
            $dir = upload_path('imports');
            @mkdir($dir, 0775, true);
            $token = random_token(16);
            $dest = $dir . '/wise-' . $token . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                flash('Could not read the uploaded file.', 'error');
                redirect('/app/wise');
            }
            $parse = wise_parse_file($dest);
            if ($parse['error']) {
                @unlink($dest);
                flash($parse['error'], 'error');
                redirect('/app/wise');
            }
            $ctxPreview = [
                'token'   => $token . '.' . $ext,
                'sheet'   => $parse['sheet'],
                'header'  => $parse['header_row'],
                'total'   => count($parse['rows']),
                'skipped' => $parse['skipped'],
                'summary' => wise_ingest($u, $parse['rows'], false),   // dry run — no writes
                'sample'  => array_slice($parse['rows'], 0, 20),
            ];
            // Fall through and render with the preview attached (no redirect, so the
            // token survives to the confirm step).
        } elseif ($action === 'commit') {
            // random_token() is URL-safe base64; keep exactly that charset plus the extension.
            $token = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($_POST['token'] ?? ''));
            $path = ($token !== '' && !str_contains($token, '..')) ? upload_path('imports/wise-' . $token) : '';
            if ($path === '' || !is_file($path)) {
                flash('That upload expired. Please choose the file again.', 'error');
                redirect('/app/wise');
            }
            $parse = wise_parse_file($path);
            $s = wise_ingest($u, $parse['rows'], true);
            @unlink($path);
            if ($s['fatal']) {
                flash('Import failed and was rolled back: ' . $s['fatal'], 'error');
            } else {
                flash(sprintf('Imported Wise details — %d matched (%d new, %d updated), %d unmatched.',
                    $s['matched'], $s['created'], $s['updated'], $s['unmatched']), 'success');
            }
            redirect('/app/wise');
        }
    }

    $usersById = users_by_id($orgId);
    $ids = visible_user_ids($u);
    $accounts = wise_accounts_for($orgId);
    $members = array_values(array_filter($usersById,
        fn ($m) => in_array((int) $m['id'], $ids, true) && $m['role'] !== 'client_viewer'));
    usort($members, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

    view('dashboard/wise', array_merge(nav_context($u), [
        'title' => 'Wise accounts', 'active' => 'wise',
        'members' => $members, 'accounts' => $accounts, 'preview' => $ctxPreview,
        'spec' => import_spec('wise'), 'spec_key' => 'wise',
        'pay_currency' => report_org_cfg($orgId)['pay_currency'],
    ]));
}

// ─────────────────────────── Salary run ───────────────────────────

/**
 * The Wise batch-payment column set, matching the payout sheet this org already
 * uses. Column I is intentionally blank — it exists in the source template, and
 * keeping the shape identical means the export can be uploaded without reshaping.
 */
function wise_export_headers(): array
{
    return ['Wise Recipient ID', 'Wise Name', 'EMAIL', 'Wise account',
            'from Currency', 'to Currency', 'Source', 'Amount', '', 'Type'];
}

function dash_salary_run(): void
{
    $u = require_cap('wise_manage');
    [$start, $end, $period, $periodDate] = period_range_from_request('pay', ['day', 'week', 'pay', 'month']);
    $ctx = $GLOBALS['DP_PERIOD_CTX'];
    $run = compute_pay_run($u, $ctx);

    view('dashboard/salary_run', array_merge(nav_context($u), [
        'title' => 'Salary run', 'active' => 'salary_run',
        'run' => $run, 'period' => $period, 'period_date' => $periodDate, 'ctx' => $ctx,
    ]));
}

function dash_salary_run_csv(): void
{
    $u = require_cap('wise_manage');
    [$start, $end, $period, $periodDate] = period_range_from_request('pay', ['day', 'week', 'pay', 'month']);
    $ctx = $GLOBALS['DP_PERIOD_CTX'];
    $run = compute_pay_run($u, $ctx);
    $payCur = $run['currency'];

    $out = fopen('php://temp', 'r+');
    fputcsv($out, wise_export_headers());
    foreach ($run['rows'] as $r) {
        // Only people with usable payout details and something to pay go in the batch.
        if (!$r['payable'] || $r['net'] <= 0) {
            continue;
        }
        $w = $r['wise'];
        fputcsv($out, [
            $w['recipient_id'] ?? '',
            $w['account_holder'] ?: $r['name'],
            is_synthetic_email($w['email'] ?? null) ? '' : ($w['email'] ?? ''),
            $w['account_summary'] ?: 'Wise account',
            $w['source_currency'] ?: $payCur,
            $w['target_currency'] ?: $payCur,
            $w['source_label'] ?: 'source',
            number_format($r['net'], 2, '.', ''),
            '',
            $w['recipient_type'] ?: 'PERSON',
        ]);
    }
    rewind($out);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="wise-salary-run-'
        . $ctx['start_date'] . '_to_' . $ctx['end_date'] . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo stream_get_contents($out);
    exit;
}

// ─────────────────────────── Payslips ───────────────────────────

/** The earning/deduction lines shown on a payslip, in print order. */
function payslip_lines(array $r): array
{
    $lines = [];
    if (($r['pay_type'] ?? 'hourly') === 'monthly') {
        $lines[] = ['Monthly salary (prorated for this period)', '', $r['base'], 1];
    } else {
        $lines[] = [sprintf('Worked hours @ %s/h', money($r['hourly'], $r['currency'])),
                    number_format($r['hours'], 2) . ' h', $r['base'], 1];
    }
    if ($r['overtime_hours'] > 0.001) {
        $lines[] = ['— of which approved overtime', number_format($r['overtime_hours'], 2) . ' h', null, 1];
    }
    if ($r['leave_pay'] > 0.001) {
        $lines[] = ['Paid time off', number_format($r['leave_paid_hours'], 2) . ' h', $r['leave_pay'], 1];
    } elseif ($r['leave_days'] > 0) {
        $lines[] = ['Time off taken (covered by salary)', number_format($r['leave_days'], 2) . ' d', null, 1];
    }
    foreach ($r['adjustments'] as $a) {
        $lines[] = [adjustment_label($a['kind']) . ' — ' . $a['label'], $a['effective_date'],
                    (float) $a['amount'], (int) $a['sign']];
    }
    return $lines;
}

/** Render one payslip as PDF bytes. */
function payslip_pdf(array $r, array $ctx, array $org): string
{
    $pdf = new DpPdf();
    $M = $pdf->margin;
    $R = $pdf->width() - $M;
    $cur = $r['currency'];
    $top = $M;

    $logo = pdf_logo_jpeg($org['logo_path'] ?? null, 34);
    if ($logo) {
        $pdf->image($logo['data'], $M, $top, $logo['w'], $logo['h']);
        $top += $logo['h'] + 10;
    }
    $pdf->setFont(true, 17)->setColor(15, 23, 42)->text($M, $top, $org['name'] ?? 'DeskPulse');
    $pdf->setFont(true, 17)->setColor(37, 99, 235)->textRight($R, $top, 'PAYSLIP');
    $top += 24;
    $pdf->line($M, $top, $R, $top, 1.2, [37, 99, 235]);
    $top += 16;

    // Employee + period block
    $pdf->setFont(false, 9)->setColor(100, 116, 139);
    $pdf->text($M, $top, 'EMPLOYEE');
    $pdf->textRight($R, $top, 'PAY PERIOD');
    $top += 13;
    $pdf->setFont(true, 11)->setColor(15, 23, 42);
    $pdf->text($M, $top, $r['name']);
    $pdf->textRight($R, $top, $ctx['label']);
    $top += 14;
    $pdf->setFont(false, 9)->setColor(71, 85, 105);
    $sub = trim((string) ($r['user']['job_title'] ?? ''));
    if ($sub !== '') {
        $pdf->text($M, $top, $sub);
    }
    $pdf->textRight($R, $top, $ctx['start_date'] . '  to  ' . $ctx['end_date']);
    $top += 12;
    $ref = trim((string) ($r['user']['external_ref'] ?? ''));
    if ($ref !== '') {
        $pdf->text($M, $top, 'Employee ref: ' . $ref);
    }
    $pdf->textRight($R, $top, employment_label($r['employment_type']));
    $top += 22;

    // Table header
    $colDesc = $M;
    $colQty  = $R - 200;
    $colAmt  = $R;
    $pdf->fillRect($M, $top - 4, $R - $M, 18, [241, 245, 249]);
    $pdf->setFont(true, 9)->setColor(71, 85, 105);
    $pdf->text($colDesc + 4, $top, 'DESCRIPTION');
    $pdf->text($colQty, $top, 'QTY');
    $pdf->textRight($colAmt - 4, $top, 'AMOUNT');
    $top += 20;

    $earnTotal = $dedTotal = 0.0;
    foreach (payslip_lines($r) as [$desc, $qty, $amount, $sign]) {
        $pdf->ensure(18);
        $top = max($top, $pdf->cursor);
        $pdf->setFont(false, 9.5)->setColor(30, 41, 59);
        foreach ($pdf->wrap($desc, $colQty - $colDesc - 12) as $i => $ln) {
            $pdf->text($colDesc + 4, $top + $i * 11, $ln);
        }
        if ($qty !== '') {
            $pdf->setColor(71, 85, 105)->text($colQty, $top, (string) $qty);
        }
        if ($amount !== null) {
            $neg = $sign < 0;
            $pdf->setColor($neg ? 185 : 30, $neg ? 28 : 41, $neg ? 28 : 59);
            $pdf->textRight($colAmt - 4, $top, ($neg ? '-' : '') . money(abs($amount), $cur));
            $neg ? $dedTotal += abs($amount) : $earnTotal += $amount;
        }
        $top += 11 * max(1, count($pdf->wrap($desc, $colQty - $colDesc - 12))) + 5;
        $pdf->line($M, $top - 3, $R, $top - 3, 0.4, [226, 232, 240]);
        $pdf->cursor = $top;
    }

    // Totals
    $top += 8;
    $pdf->setFont(false, 10)->setColor(71, 85, 105);
    $pdf->textRight($colAmt - 90, $top, 'Gross pay');
    $pdf->setFont(true, 10)->setColor(30, 41, 59)->textRight($colAmt - 4, $top, money($r['gross'], $cur));
    $top += 15;
    $pdf->setFont(false, 10)->setColor(71, 85, 105)->textRight($colAmt - 90, $top, 'Deductions');
    $pdf->setFont(true, 10)->setColor(185, 28, 28)->textRight($colAmt - 4, $top, '-' . money($r['deductions'], $cur));
    $top += 8;
    $pdf->line($colAmt - 220, $top + 6, $R, $top + 6, 0.8, [148, 163, 184]);
    $top += 18;
    $pdf->fillRect($colAmt - 220, $top - 5, 220, 24, [37, 99, 235]);
    $pdf->setFont(true, 12)->setColor(255, 255, 255);
    $pdf->text($colAmt - 212, $top + 1, 'NET PAY');
    $pdf->textRight($colAmt - 8, $top + 1, money($r['net'], $cur));
    $top += 36;

    // Payment + footer
    $w = $r['wise'] ?? null;
    $pdf->setFont(false, 9)->setColor(100, 116, 139);
    $pdf->text($M, $top, 'Payment method: ' . ($w
        ? 'Wise — ' . ($w['account_summary'] ?: 'Wise account') . ' (' . ($w['target_currency'] ?: $cur) . ')'
        : 'Not set'));
    $top += 12;
    if ($r['hours'] > 0) {
        $pdf->text($M, $top, sprintf('Hours logged this period: %.2f h', $r['hours']));
        $top += 12;
    }
    foreach ($r['leave_by_type'] as $tname => $days) {
        $pdf->text($M, $top, sprintf('%s: %.2f day(s)', $tname, $days));
        $top += 12;
    }
    $pdf->setColor(148, 163, 184)->setFont(false, 8);
    $pdf->text($M, $pdf->height() - $M - 10,
        'Generated by DeskPulse on ' . gmdate('j M Y H:i') . ' UTC. This is a computer-generated payslip.');
    return $pdf->output();
}

/**
 * Generate (and cache) the payslip PDF for one member/period.
 * Returns [absolutePath, payslipRow].
 */
function payslip_generate(array $r, array $ctx, array $org, bool $force = false): array
{
    $orgId = (int) $org['id'];
    $uid = (int) $r['user_id'];
    $existing = db_one('SELECT * FROM payslips WHERE user_id = ? AND period_start = ? AND period_end = ?',
        [$uid, $ctx['start_date'], $ctx['end_date']]);
    if ($existing && !$force && $existing['pdf_path'] && is_file(private_path($existing['pdf_path']))) {
        return [private_path($existing['pdf_path']), $existing];
    }

    $bytes = payslip_pdf($r, $ctx, $org);
    $rel = sprintf('payslips/%d/%s_%s/%d-%s.pdf', $orgId, $ctx['start_date'], $ctx['end_date'],
        $uid, bin2hex(random_bytes(6)));
    $abs = private_path($rel);
    @mkdir(dirname($abs), 0770, true);
    file_put_contents($abs, $bytes);

    // Replace any previous file for this period so old copies don't accumulate.
    if ($existing && $existing['pdf_path'] && $existing['pdf_path'] !== $rel) {
        @unlink(private_path($existing['pdf_path']));
    }
    db_exec(
        'INSERT INTO payslips (org_id, user_id, period_start, period_end, cycle, hours, gross,
                deductions, net, currency, breakdown, pdf_path)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE cycle = VALUES(cycle), hours = VALUES(hours), gross = VALUES(gross),
                deductions = VALUES(deductions), net = VALUES(net), currency = VALUES(currency),
                breakdown = VALUES(breakdown), pdf_path = VALUES(pdf_path), generated_at = CURRENT_TIMESTAMP',
        [$orgId, $uid, $ctx['start_date'], $ctx['end_date'], $ctx['cycle'],
         round($r['hours'], 2), round($r['gross'], 2), round($r['deductions'], 2), round($r['net'], 2),
         $r['currency'], json_encode(payslip_lines($r)), $rel]
    );
    $row = db_one('SELECT * FROM payslips WHERE user_id = ? AND period_start = ? AND period_end = ?',
        [$uid, $ctx['start_date'], $ctx['end_date']]);
    return [$abs, $row];
}

/** Stream a payslip PDF, but only to its owner or someone with the payroll cap. */
function dash_payslip_pdf(): void
{
    $u = require_staff();
    $wanted = (int) ($_GET['user_id'] ?? $u['id']);
    if ($wanted !== (int) $u['id'] && !can($u, 'payroll')) {
        deny_access();
    }
    [$start, $end, $period, $periodDate] = period_range_from_request('pay', ['day', 'week', 'pay', 'month']);
    $ctx = $GLOBALS['DP_PERIOD_CTX'];
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [(int) $u['org_id']]);

    $run = compute_pay_run($u, $ctx, $wanted);
    $row = $run['rows'][0] ?? null;
    if (!$row) {
        // A member computing their own payslip is not in visible_user_ids() when
        // they have no management scope, so fall back to a self-only run.
        if ($wanted === (int) $u['id']) {
            $selfCtx = $ctx;
            $run = compute_pay_run(array_merge($u, ['role' => $u['role']]), $selfCtx, $wanted);
            $row = $run['rows'][0] ?? null;
        }
        if (!$row) {
            abort(404, 'No payslip data for that period.');
        }
    }
    [$abs] = payslip_generate($row, $ctx, $org, !empty($_GET['refresh']));
    $name = 'payslip-' . preg_replace('/[^A-Za-z0-9]+/', '-', $row['name']) . '-' . $ctx['start_date'] . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

/** Admin/HR: generate and email payslips for a whole period. */
function dash_payslips(): void
{
    $u = require_cap('payroll');
    $orgId = (int) $u['org_id'];
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$orgId]);

    if (request_method() === 'POST') {
        check_csrf();
        // Re-resolve the period from the POSTed filter so the action matches the screen.
        $_GET['period'] = $_POST['period'] ?? 'pay';
        $_GET['date']   = $_POST['date'] ?? '';
        $_GET['from']   = $_POST['from'] ?? '';
        $_GET['to']     = $_POST['to'] ?? '';
        [$s, $e, $period, $periodDate] = period_range_from_request('pay', ['day', 'week', 'pay', 'month']);
        $ctx = $GLOBALS['DP_PERIOD_CTX'];
        $run = compute_pay_run($u, $ctx);
        $only = (int) ($_POST['user_id'] ?? 0);
        $email = ($_POST['action'] ?? '') === 'generate_email';

        $made = $sent = $skipped = 0;
        $notes = [];
        foreach ($run['rows'] as $r) {
            if ($only && (int) $r['user_id'] !== $only) {
                continue;
            }
            if ($r['gross'] <= 0 && $r['hours'] <= 0) {
                $skipped++;
                continue;
            }
            [$abs, $slip] = payslip_generate($r, $ctx, $org, true);
            $made++;
            if (!$email) {
                continue;
            }
            if (!mail_address_ok($r['user']['email'])) {
                $notes[] = $r['name'] . ' — no usable email address';
                continue;
            }
            if (!empty($slip['emailed_at']) && empty($_POST['resend'])) {
                $notes[] = $r['name'] . ' — already emailed, skipped';
                continue;
            }
            $vars = msg_tokens_for($r['user'], $org, ['period' => $ctx['label']]);
            $subject = 'Your payslip for ' . $ctx['label'];
            $text = "Hi " . $vars['first_name'] . ",\n\nYour payslip for " . $ctx['label']
                  . " is attached.\n\nNet pay: " . money($r['net'], $r['currency'])
                  . "\nHours logged: " . number_format($r['hours'], 2) . " h\n\n— " . ($org['name'] ?? 'DeskPulse');
            $id = mail_queue([
                'org_id' => $orgId, 'user_id' => (int) $r['user_id'],
                'to_email' => $r['user']['email'], 'to_name' => $r['name'],
                'subject' => $subject, 'text' => $text,
                'html' => mail_render_html(['org' => $org, 'heading' => $subject,
                    'body_html' => nl2br(e($text)),
                    'cta_url' => rtrim((string) public_base_url(), '/') . url('/app/payslip'),
                    'cta_label' => 'View in DeskPulse']),
                'attachments' => [['path' => $abs,
                                   'name' => 'payslip-' . $ctx['start_date'] . '.pdf',
                                   'mime' => 'application/pdf']],
                'kind' => 'payslip', 'created_by_id' => (int) $u['id'],
            ]);
            if ($id) {
                db_exec('UPDATE payslips SET emailed_at = ?, email_id = ? WHERE id = ?',
                    [gmdate('Y-m-d H:i:s'), $id, $slip['id']]);
                $sent++;
            }
        }
        $msg = "$made payslip(s) generated";
        if ($email) {
            $msg .= ", $sent queued for email";
            if (!mail_enabled()) {
                $msg .= ' (no SMTP transport configured yet, so nothing will actually send)';
            }
        }
        if ($skipped) {
            $msg .= ", $skipped skipped (nothing to pay)";
        }
        flash($msg . '.' . ($notes ? ' ' . implode('; ', array_slice($notes, 0, 6)) : ''), 'success');
        redirect('/app/payslips?' . period_qs($period, $periodDate));
    }

    [$start, $end, $period, $periodDate] = period_range_from_request('pay', ['day', 'week', 'pay', 'month']);
    $ctx = $GLOBALS['DP_PERIOD_CTX'];
    $run = compute_pay_run($u, $ctx);
    $slips = [];
    foreach (db_all('SELECT * FROM payslips WHERE org_id = ? AND period_start = ? AND period_end = ?',
             [$orgId, $ctx['start_date'], $ctx['end_date']]) as $s) {
        $slips[(int) $s['user_id']] = $s;
    }

    view('dashboard/payslips', array_merge(nav_context($u), [
        'title' => 'Payslips', 'active' => 'payslips', 'run' => $run, 'slips' => $slips,
        'period' => $period, 'period_date' => $periodDate, 'ctx' => $ctx,
        'mail_ready' => mail_enabled(),
    ]));
}

/** Download a blank, correctly-headed template for any registered import. */
function dash_import_template(array $p): void
{
    require_login();
    $key = preg_replace('/[^a-z0-9_]/i', '', (string) ($p['key'] ?? ''));
    $spec = import_spec($key);
    if (!$spec) {
        abort(404, 'Unknown import template');
    }
    $out = fopen('php://temp', 'r+');
    $headers = [];
    $examples = [];
    foreach ($spec['columns'] as $col) {
        $headers[] = $col['name'];
        $examples[] = $col['example'] ?? '';
    }
    fputcsv($out, $headers);
    fputcsv($out, $examples);
    rewind($out);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="deskpulse-' . $key . '-template.csv"');
    header('X-Content-Type-Options: nosniff');
    echo stream_get_contents($out);
    exit;
}
