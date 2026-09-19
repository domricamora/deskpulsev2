<?php /** App shell with sidebar. Expects: $title,$content,$active,$user,$is_manager,$pending_count */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'DeskPulse') ?> · DeskPulse</title>
<link rel="stylesheet" href="<?= e(url('/assets/css/deskpulse.css')) ?>">
</head>
<body class="app">
<?php
// Inline SVG icon set (Lucide-style, stroke = currentColor). No CDN, no build.
$dpIcons = [
    'overview'    => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
    'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'tasks'       => '<path d="m9 11 3 3 8-8"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9"/>',
    'approvals'   => '<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m9 12 2 2 4-4"/>',
    'overtime'    => '<circle cx="11" cy="12" r="8"/><path d="M11 8v4l2.5 1.5"/><path d="M19 3v4M17 5h4"/>',
    'live'        => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
    'image'       => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
    'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'clients'     => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
    'contracts'   => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M9 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2h-2"/><path d="m9 13 2 2 4-4"/>',
    'billing'     => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
    'devices'     => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
    'audit'       => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
    'download'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
    'upload'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
    'settings'    => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M2 14h4"/><path d="M10 8h4"/><path d="M18 16h4"/>',
    'profile'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
    'agents'      => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M5.5 16.5a3.5 3.5 0 0 1 7 0"/><path d="M15 9.5h4M15 13h3"/>',
    'share'       => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
    'payroll'     => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 10v4M18 10v4"/>',
    'payslip'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>',
    'platform'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
    'orgs'        => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M8 10h.01M16 10h.01M8 14h.01M16 14h.01"/>',
    'menu'        => '<path d="M3 6h18M3 12h18M3 18h18"/>',
    'help'        => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
    'efficiency'  => '<path d="M4 15a8 8 0 0 1 16 0"/><path d="M12 15 16.5 9.5"/><circle cx="12" cy="15" r="1.2"/>',
    'adjust'      => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><circle cx="4" cy="12" r="2"/><circle cx="12" cy="10" r="2"/><circle cx="20" cy="14" r="2"/>',
    'leave'       => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="m9 16 2 2 4-4"/>',
    'wise'        => '<circle cx="12" cy="12" r="9"/><path d="M8 9h8l-4 3 4 3H8"/>',
    'salary'      => '<path d="M3 17V7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M7 9h4M7 12h6M15.5 15.5h2"/>',
    'message'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/><path d="M8 9h8M8 13h5"/>',
];
$dpIcon = function (string $name) use ($dpIcons): string {
    $p = $dpIcons[$name] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
};
?>
<aside class="sidebar" id="app-sidebar">
  <a class="brand" href="<?= e(url('/?site=1')) ?>" style="text-decoration:none">
    <img src="<?= e(url('/assets/img/favicon.svg')) ?>" width="22" height="22" alt="" style="display:block">
    <span style="font-family:'Plus Jakarta Sans','Inter',sans-serif;font-weight:800;letter-spacing:-.02em;font-size:1.2rem;color:#e9eef8">Desk<span style="color:#2dd4bf">Pulse</span></span>
  </a>
  <?php if (!empty($org_logo)): ?>
    <div class="org-brand"><img src="<?= e($org_logo) ?>" alt="Company logo"></div>
  <?php endif; ?>
  <nav>
    <?php if (!empty($is_super) && empty($acting_org)): ?>
      <?php $orgCluster = in_array($active ?? '', ['platform_orgs', 'platform_accounts', 'platform_billing', 'platform_settings'], true); ?>
      <div class="nav-group">
        <div class="nav-group-label">Platform</div>
        <a class="<?= ($active ?? '') === 'platform' ? 'on' : '' ?>" href="<?= e(url('/app/platform')) ?>"><?= $dpIcon('platform') ?><span class="lbl">Platform</span></a>
        <a class="<?= $orgCluster ? 'on' : '' ?>" href="<?= e(url('/app/platform/orgs')) ?>"><?= $dpIcon('orgs') ?><span class="lbl">Organizations</span></a>
        <a class="<?= ($active ?? '') === 'platform_subscribers' ? 'on' : '' ?>" href="<?= e(url('/app/platform/subscribers')) ?>"><?= $dpIcon('users') ?><span class="lbl">Subscribers</span></a>
        <a class="<?= ($active ?? '') === 'platform_accounting' ? 'on' : '' ?>" href="<?= e(url('/app/platform/accounting')) ?>"><?= $dpIcon('salary') ?><span class="lbl">Accounting</span></a>
      </div>
    <?php endif; ?>
    <?php
    // Grouped nav: [label => [ [key, label, href, capability|null, icon, tooltip], ... ]].
    $groups = [
        'Work' => [
            ['overview', 'Overview', '/app/overview', null, 'overview', 'Your dashboard: active vs inactive time, top apps, screenshots and an activity timeline.'],
            ['timesheets', 'Timesheets', '/app/timesheets', null, 'clock', 'Tracked sessions by day. Add manual time entries (sent for approval).'],
            ['tasks', 'Tasks', '/app/tasks', null, 'tasks', 'Add tasks, pick what you\'re working on, and see time spent per task.'],
            ['leave', 'Time off', '/app/leave', null, 'leave', 'Request paid time off and track your remaining leave balance.'],
        ],
        'Insights' => [
            ['approvals', 'Approvals', '/app/approvals', 'approve_time', 'approvals', 'Review, approve or reject manual time entries your team submits.'],
            ['overtime', 'Overtime', '/app/overtime', 'approve_overtime', 'overtime', 'Approve hours worked beyond a schedule before they count toward payroll.'],
            ['live', 'Live', '/app/live', 'live', 'live', 'See who is tracking right now and what they\'re working on, in real time.'],
            ['screenshots', 'Screenshots', '/app/screenshots', 'screenshots', 'image', 'Browse periodic screenshots captured during tracked work.'],
            ['efficiency', 'Efficiency report', '/app/reports/efficiency', 'reports', 'efficiency', 'Per-person activity, task completion and an effectiveness score — for performance evaluation.'],
        ],
        'Manage' => [
            ['agents', 'Agents', '/app/agents', 'view_agents', 'agents', 'Manage the agents you oversee and the clients they\'re assigned to.'],
            ['team', 'Team', '/app/team', 'view_all', 'users', 'Manage accounts, roles, work schedules and pay/bill rates.'],
            ['clients', 'Clients', '/app/clients', 'clients_manage', 'clients', 'Add the companies you work for and their read-only portal logins.'],
            ['contracts', 'Contracts', '/app/contracts', 'contracts_manage', 'contracts', 'See every contract at a glance and assign the team members working under each.'],
            ['billing', 'Billing', '/app/billing', 'billing', 'billing', 'Bill customers per agent or as a flat monthly service charge.'],
            ['payroll', 'Pay breakdown', '/app/payroll', 'payroll', 'payroll', 'Internal labor cost: hours and pay per person for any period.'],
            ['adjustments', 'Pay adjustments', '/app/adjustments', 'pay_adjustments', 'adjust', 'Bonuses, commissions, reimbursements and deductions for a pay period.'],
            ['payslips', 'Payslips', '/app/payslips', 'payroll', 'payslip', 'Generate PDF payslips for a pay period and email them to employees.'],
            ['wise', 'Wise accounts', '/app/wise', 'wise_manage', 'wise', 'Employee payout details - import from Excel/CSV or enter them by hand.'],
            ['salary_run', 'Salary run', '/app/salary-run', 'wise_manage', 'salary', 'Net pay per employee for a period, exported as a Wise batch payment file.'],
            ['messages', 'Messages', '/app/messages', 'messaging', 'message', 'Send notices, custom messages and reminders to your team.'],
            ['import', 'Import data', '/app/import', 'data_import', 'upload', 'Upload a payroll Excel file to create clients, employees and time entries.'],
            ['devices', 'Devices', '/app/devices', 'devices', 'devices', 'Every installed agent with last-seen time; rename or remove a device.'],
            ['audit', 'Audit log', '/app/audit', 'audit', 'audit', 'A trail of key account and security actions across the organization.'],
        ],
        'Account' => [
            ['payslip', 'Payslip', '/app/payslip', null, 'payslip', 'Your paid-hours breakdown for a period, including approved overtime.'],
            ['share_links', 'Share links', '/app/share-links', 'view_all', 'share', 'Public read-only summary links (day/week/month) you can share.'],
            ['download', 'Download app', '/app/download', null, 'download', 'Get the desktop agent that tracks time and activity.'],
            ['settings', 'Settings', '/app/settings', null, 'settings', 'Monitoring policy, devices, share links and company branding.'],
            ['subscription', 'Subscription', '/app/subscription', 'subscription', 'billing', 'Your DeskPulse plan, free-trial status and monthly payment.'],
            ['profile', 'My profile', '/app/profile', null, 'profile', 'Update your name, contact details and password.'],
            ['welcome', 'Getting started', '/app/welcome', null, 'help', 'Reopen the quick role guide for what you can do in DeskPulse.'],
        ],
    ];
    // The platform operator's console is signup/oversight only — hide the
    // member-level pages (they have no rate/member data of their own). While
    // "acting as" an org, show the full nav so they can navigate that org.
    $superHidden = ['overview', 'timesheets', 'approvals', 'overtime', 'screenshots', 'devices',
                    'tasks', 'download', 'billing', 'payroll', 'import', 'settings', 'team', 'clients',
                    'contracts', 'live', 'agents', 'share_links', 'payslip', 'welcome', 'efficiency',
                    'adjustments', 'payslips', 'wise', 'salary_run', 'messages', 'leave',
                    // The tenant paywall page — the platform org never pays itself.
                    // Subscribers (above) is the super-admin equivalent.
                    'subscription'];
    $hideSuperLinks = !empty($is_super) && empty($acting_org);
    // Per-role nav removals (beyond capability gating).
    // A client portal login only gets its own engagement's read-only views + profile.
    $roleHidden = [
        'manager'       => ['settings'],
        // Client portal: exactly Agents, Timesheets, Tasks, Screenshots, Billing and
        // My profile. Overview and the Getting-started guide are dropped; Efficiency is
        // an internal performance report (client_viewer holds `reports` for data scope,
        // so it must be removed explicitly).
        'client_viewer' => ['overview', 'welcome', 'payslip', 'download', 'settings', 'efficiency',
                            'leave', 'adjustments', 'payslips', 'wise', 'salary_run', 'messages'],
    ];
    $hideForRole = $roleHidden[$user['role'] ?? ''] ?? [];
    foreach ($groups as $groupLabel => $items):
        // Resolve which items in this group are visible to the current user.
        $visible = array_filter($items, function ($it) use ($user, $hideSuperLinks, $superHidden, $hideForRole) {
            [$key, , , $cap] = $it;
            if ($cap !== null && !can($user, $cap)) return false;
            if ($hideSuperLinks && in_array($key, $superHidden, true)) return false;
            if (in_array($key, $hideForRole, true)) return false;
            return true;
        });
        if (!$visible) continue; ?>
      <div class="nav-group">
        <div class="nav-group-label"><?= e($groupLabel) ?></div>
        <?php foreach ($visible as [$key, $label, $href, $cap, $icon]): ?>
          <a class="<?= ($active ?? '') === $key ? 'on' : '' ?>" href="<?= e(url($href)) ?>">
            <?= $dpIcon($icon) ?><span class="lbl"><?= e($label) ?></span>
            <?php if ($key === 'approvals' && !empty($pending_count)): ?>
              <span class="badge"><?= (int) $pending_count ?></span>
            <?php endif; ?>
            <?php if ($key === 'overtime' && !empty($overtime_pending_count)): ?>
              <span class="badge"><?= (int) $overtime_pending_count ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>
  <div class="side-foot">
    <div class="who"><?= e($user['name'] ?? '') ?><br><small><?= e(role_label($user['role'] ?? '')) ?></small></div>
    <a class="ghost" href="<?= e(url('/logout')) ?>">Sign out</a>
  </div>
</aside>
<div class="nav-backdrop" id="nav-backdrop"></div>
<main class="main">
  <?php if (!empty($acting_org)): ?>
    <div class="acting-banner">★ Viewing <b><?= e($acting_org) ?></b> as super admin
      · <a href="<?= e(url('/app/platform/return')) ?>">Return to platform</a></div>
  <?php endif; ?>
  <header class="topbar">
    <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Open menu"
            aria-controls="app-sidebar" aria-expanded="false"><?= $dpIcon('menu') ?></button>
    <h1><?= e($title ?? '') ?></h1>
  </header>
  <?php foreach (take_flashes() as $f): ?>
    <div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>
  <?php foreach (($notices ?? []) as $n): ?>
    <div class="notice-banner lvl-<?= e($n['level']) ?>">
      <div class="nb-body">
        <b><?= e($n['title']) ?></b>
        <div><?= nl2br(e($n['body'])) ?></div>
      </div>
      <form method="post" action="<?= e(url('/app/notice/' . (int) $n['id'] . '/dismiss')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e(current_route_path()) ?>">
        <button class="lnk" type="submit" aria-label="Dismiss notice">dismiss</button>
      </form>
    </div>
  <?php endforeach; ?>
  <div class="content"><?= $content ?></div>
</main>
<script src="<?= e(url('/assets/js/vendor/chart.umd.js')) ?>"></script>
<script src="<?= e(url('/assets/js/charts.js')) ?>"></script>
<script src="<?= e(url('/assets/js/dashboard.js')) ?>"></script>
<script src="<?= e(url('/assets/js/tables.js')) ?>"></script>
</body>
</html>
