<?php
// Drain the DeskPulse email outbox. Run from cron every 5 minutes:
//
//   */5 * * * * php /path/to/server/tools/mail_worker.php >/dev/null 2>&1
//
// Each message is marked sent/failed as it goes, and a message that has burned
// through MAIL_MAX_ATTEMPTS is left alone.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This script is CLI-only.\n");
}

$serverDir = dirname(__DIR__);
$GLOBALS['CONFIG'] = require $serverDir . '/config.php';
require $serverDir . '/src/helpers.php';
require $serverDir . '/src/db.php';
require $serverDir . '/src/reports.php';
require $serverDir . '/src/payroll.php';
require $serverDir . '/src/mailer.php';

$limit = (int) ($argv[1] ?? 25);

if (!mail_enabled()) {
    fwrite(STDERR, "Mail is disabled or smtp_host is unset in config.php — nothing to do.\n");
    exit(1);
}

[$sent, $failed] = mail_flush($limit);
printf("%s  sent=%d failed=%d\n", gmdate('Y-m-d H:i:s'), $sent, $failed);
exit($failed > 0 && $sent === 0 ? 2 : 0);
