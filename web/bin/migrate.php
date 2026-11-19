<?php
/**
 * Apply pending schema/data migrations from web/migrations/versioned/.
 *
 *   php bin/migrate.php            apply everything not yet applied
 *   php bin/migrate.php --status   list applied / pending, change nothing
 *
 * deploy.sh runs this on every deploy, after the new code is synced and
 * before PHP-FPM reloads. Each migration is a PHP file returning
 * `function (PDO $pdo): void`; files run in filename order and each is
 * recorded in schema_migrations once it finishes. Schema statements commit
 * implicitly in MariaDB, so every migration must be safe to re-run if it
 * dies half way (IF NOT EXISTS, guarded UPDATEs). A migration that rewrites
 * data opens its own transaction.
 *
 * A fresh database is built from migrations/schema.sql, then this script.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/bootstrap.php';

$statusOnly = in_array('--status', $argv, true);
$pdo = db();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        id         VARCHAR(100) NOT NULL PRIMARY KEY,
        applied_at DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$applied = $pdo->query('SELECT id FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob(__DIR__ . '/../migrations/versioned/*.php') ?: [];
sort($files, SORT_STRING);

$pending = 0;
foreach ($files as $file) {
    $id = basename($file, '.php');
    if (isset($applied[$id])) {
        if ($statusOnly) echo "  applied  {$id}\n";
        continue;
    }
    $pending++;
    if ($statusOnly) {
        echo "  PENDING  {$id}\n";
        continue;
    }

    echo "==> {$id}\n";
    $fn = require $file;
    if (!is_callable($fn)) {
        fwrite(STDERR, "    {$id} does not return a callable — stopping.\n");
        exit(1);
    }
    try {
        $fn($pdo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "    FAILED: " . $e->getMessage() . "\n");
        exit(1);
    }
    // A data migration may have recorded itself inside its own transaction.
    $pdo->prepare('INSERT IGNORE INTO schema_migrations (id) VALUES (:id)')->execute([':id' => $id]);
    echo "    done\n";
}

if ($pending === 0) {
    echo "Schema up to date.\n";
} elseif ($statusOnly) {
    echo "{$pending} pending.\n";
}
