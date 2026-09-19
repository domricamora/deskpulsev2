<?php
/** Front controller — all requests route through here (see .htaccess). */
require dirname(__DIR__) . '/src/bootstrap.php';

$r = new Router();

// ── Marketing ──
$r->get('/', 'marketing_index');
$r->get('/pricing', 'marketing_pricing');
$r->get('/tools/cost-calculator', 'marketing_calculator');
$r->get('/download', 'marketing_download');
$r->get('/download/app', 'marketing_download_app');
$r->get('/robots.txt', 'marketing_robots');
$r->get('/sitemap.xml', 'marketing_sitemap');
$r->get('/llms.txt', 'marketing_llms');

// ── Content (server/content/<collection>/<slug>.md) ──
// Bound with closures because Router hands the handler only {params}, so a shared
// handler cannot infer which collection its own route belongs to.
$r->get('/blog', 'marketing_blog_index');
$r->get('/blog/{slug}', fn($p) => marketing_content('blog', (string) $p['slug']));
$r->get('/compare/{slug}', fn($p) => marketing_content('compare', (string) $p['slug']));
$r->get('/use-cases/{slug}', fn($p) => marketing_content('use-cases', (string) $p['slug']));
foreach (['privacy', 'terms', 'security', 'about', 'contact'] as $dpPage) {
    $r->get('/' . $dpPage, fn() => marketing_content('pages', $dpPage));
}

// ── Auth ──
$r->get('/register', 'handle_register');
$r->post('/register', 'handle_register');
$r->get('/login', 'handle_login');
$r->post('/login', 'handle_login');
$r->get('/logout', 'handle_logout');
$r->get('/forgot-password', 'handle_forgot_password');
$r->post('/forgot-password', 'handle_forgot_password');
$r->get('/reset-password', 'handle_reset_password');
$r->post('/reset-password', 'handle_reset_password');

// ── Federated sign-in (OIDC) ──
$r->get('/auth/{provider}', fn($p) => oauth_start((string) $p['provider']));
$r->get('/auth/{provider}/callback', fn($p) => oauth_callback((string) $p['provider']));

// ── Dashboard ──
$r->get('/app', function () {
    $u = current_user();
    if ($u && $u['role'] === 'super_admin' && empty($_SESSION['act_org'])) {
        redirect('/app/platform');
    }
    if ($u && $u['role'] === 'client_viewer') {
        redirect('/app/agents');   // client portal home = the agents on their engagement
    }
    redirect('/app/overview');
});
$r->get('/app/pending', 'dash_pending');
$r->get('/app/change-password', 'dash_change_password');
$r->post('/app/change-password', 'dash_change_password');
$r->get('/app/profile', 'dash_profile');
$r->post('/app/profile', 'dash_profile');
$r->get('/app/subscription', 'dash_subscription');
$r->get('/app/payment-receipt/{id}', 'dash_payment_receipt');
$r->post('/app/subscription', 'dash_subscription');
$r->get('/app/platform', 'dash_platform');
$r->post('/app/platform', 'dash_platform');
$r->get('/app/platform/orgs', 'dash_platform_orgs');
$r->get('/app/platform/user/{id}', 'dash_platform_user');
$r->get('/app/platform/accounts', 'dash_platform_accounts');
$r->get('/app/platform/billing', 'dash_platform_billing');
$r->post('/app/platform/billing', 'dash_platform_billing');
$r->get('/app/platform/subscription/{id}', 'dash_platform_subscription');
$r->post('/app/platform/subscription/{id}', 'dash_platform_subscription');
$r->get('/app/platform/subscribers', 'dash_platform_subscribers');
$r->get('/app/platform/subscribers.csv', 'dash_platform_subscribers_csv');
$r->get('/app/platform/accounting', 'dash_platform_accounting');
$r->post('/app/platform/accounting', 'dash_platform_accounting');
$r->get('/app/platform/accounting.csv', 'dash_platform_accounting_csv');
$r->get('/app/platform/sca-public-key.pem', 'dash_platform_sca_key');
$r->get('/app/platform/settings', 'dash_platform_settings');
$r->post('/app/platform/settings', 'dash_platform_settings');
$r->get('/app/platform/export.sql', 'dash_platform_export');
$r->get('/app/platform/act/{id}', 'dash_platform_act');
$r->get('/app/platform/return', 'dash_platform_return');
$r->get('/app/onboarding', 'dash_onboarding');
$r->post('/app/onboarding', 'dash_onboarding');
$r->get('/app/welcome', 'dash_welcome');
$r->post('/app/welcome', 'dash_welcome');
$r->get('/app/overview', 'dash_overview');
$r->get('/app/agent/{id}', 'dash_agent_info');
$r->get('/app/agents', 'dash_agents');
$r->post('/app/agents', 'dash_agents');
$r->get('/app/agents/{id}', 'dash_agent_detail');
$r->get('/app/share-links', 'dash_share_links');
$r->get('/app/timesheets', 'dash_timesheets');
$r->post('/app/timesheets', 'dash_timesheets');
$r->get('/app/approvals', 'dash_approvals');
$r->post('/app/approvals/{id}', 'dash_approval_action');
$r->get('/app/overtime', 'dash_overtime');
$r->post('/app/overtime/{id}', 'dash_overtime_action');
$r->get('/app/live', 'dash_live');
$r->get('/app/live/data', 'dash_live_data');
$r->get('/app/session/{id}', 'dash_session_detail');
$r->get('/app/screenshots', 'dash_screenshots');
$r->get('/app/download', 'dash_download');
$r->get('/app/team', 'dash_team');
$r->post('/app/team', 'dash_team');
$r->get('/app/tasks', 'dash_tasks');
$r->post('/app/tasks', 'dash_tasks');
$r->get('/app/clients', 'dash_clients');
$r->post('/app/clients', 'dash_clients');
$r->get('/app/contracts', 'dash_contracts');
$r->post('/app/contracts', 'dash_contracts');
$r->get('/app/billing', 'dash_billing');
$r->get('/app/billing.csv', 'dash_billing_csv');
$r->get('/app/payroll', 'dash_payroll');
$r->get('/app/reports/efficiency', 'dash_efficiency');
$r->get('/app/reports/efficiency.csv', 'dash_efficiency_csv');
$r->get('/app/payslip', 'dash_payslip');
$r->get('/app/devices', 'dash_devices');
$r->post('/app/devices', 'dash_devices');
$r->get('/app/audit', 'dash_audit');
$r->get('/app/settings', 'dash_settings');
$r->post('/app/settings', 'dash_settings');
$r->get('/app/export.csv', 'dash_export_csv');
$r->get('/app/import', 'dash_import');
$r->post('/app/import', 'dash_import');
$r->get('/app/import/template/{key}', 'dash_import_template');

// ── Payroll: adjustments, time off, Wise payouts, salary run, payslips ──
$r->get('/app/adjustments', 'dash_adjustments');
$r->post('/app/adjustments', 'dash_adjustments');
$r->get('/app/leave', 'dash_leave');
$r->post('/app/leave', 'dash_leave');
$r->get('/app/wise', 'dash_wise');
$r->post('/app/wise', 'dash_wise');
$r->get('/app/salary-run', 'dash_salary_run');
$r->get('/app/salary-run.csv', 'dash_salary_run_csv');
$r->get('/app/payslip.pdf', 'dash_payslip_pdf');
$r->get('/app/payslips', 'dash_payslips');
$r->post('/app/payslips', 'dash_payslips');

// ── Messaging: custom messages, reminders, notices ──
$r->get('/app/messages', 'dash_messages');
$r->post('/app/messages', 'dash_messages');
$r->post('/app/notice/{id}/dismiss', 'dash_notice_dismiss');

// ── Public share ──
$r->get('/share/{token}', 'share_view');

// ── Webhooks (desktop agent) ──
// Wise payment events (RSA-SHA256 signed by Wise; see src/wise.php).
$r->post('/webhooks/wise', 'wh_wise');

$r->post('/webhooks/auth', 'wh_auth');
$r->get('/webhooks/me', 'wh_me');
$r->get('/webhooks/clients', 'wh_clients');
$r->get('/webhooks/policy', 'wh_policy');
$r->get('/webhooks/tasks', 'wh_tasks_list');
$r->post('/webhooks/tasks', 'wh_task_create');
$r->delete('/webhooks/tasks/{id}', 'wh_task_delete');
$r->post('/webhooks/session', 'wh_session_start');
$r->patch('/webhooks/session/{id}', 'wh_session_stop');
$r->post('/webhooks/session/{id}/task', 'wh_session_set_task');
$r->post('/webhooks/session/{id}/activity', 'wh_activity');
$r->post('/webhooks/session/{id}/windows', 'wh_windows');
$r->post('/webhooks/session/{id}/idle', 'wh_idle');
$r->post('/webhooks/session/{id}/screenshot', 'wh_screenshot');

// ── Remote desktop control (super-admin only, unpublished) ──
$r->get ('/app/remote/{device}',        'dash_remote_view');
$r->post('/app/remote/{device}/start',  'dash_remote_start');
$r->post('/app/remote/{id}/stop',       'dash_remote_stop');
$r->get ('/app/remote/{id}/status',     'dash_remote_status');
$r->get ('/app/remote/{id}/frame',      'dash_remote_frame');
$r->post('/app/remote/{id}/input',      'dash_remote_input');
$r->get ('/webhooks/remote/poll',        'wh_remote_poll');
$r->post('/webhooks/remote/{id}/frame',  'wh_remote_frame');
$r->post('/webhooks/remote/{id}/end',    'wh_remote_end');

$r->dispatch(request_method(), current_route_path());
