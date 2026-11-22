<?php
// Sends the booking reminder emails that are due today (see app/Reminders.php).
//   php scripts/send-reminders.php            → send them
//   php scripts/send-reminders.php --dry-run  → only list what would be sent
// Run it once a day from a cron job (cPanel / Hostinger → Cron Jobs), e.g. every morning:
//   0 8 * * *  /usr/bin/php /home/USER/public_html/scripts/send-reminders.php
// Without a cron job the website sends them itself, at most once an hour, when someone visits.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use Ydr\Reminders;
use Ydr\Mailer;

if (!Reminders::enabled()) {
    echo "Reminders are switched off (setting reminders_on = 0).\n";
    exit(0);
}

if (in_array('--dry-run', $argv, true)) {
    $due = Reminders::due();
    foreach ($due as $d) echo "{$d['kind']} reminder: booking #{$d['id']} ({$d['daysLeft']} day(s) left)\n";
    echo count($due) . " reminder(s) due.\n";
    exit(0);
}

$sent = Reminders::run();
Mailer::flushQueue();
echo "Sent {$sent['arrival']} arrival and {$sent['payment']} payment reminder(s).\n";
