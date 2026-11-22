<?php
/**
 * Raise an admin alert from a shell job (backup.sh, cron):
 *
 *   php bin/alert.php <category> <message>
 *
 * At most one alert per category per 20 hours, so a job failing every night
 * shows once a day, not once a run.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/AdminAlerts.php';

$category = (string)($argv[1] ?? 'job_failed');
$message  = trim((string)($argv[2] ?? 'A scheduled job failed.'));
AdminAlerts::send($category, mb_substr($message, 0, 500), null, [], 'job:' . $category, 20);
