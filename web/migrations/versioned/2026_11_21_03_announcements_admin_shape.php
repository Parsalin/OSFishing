<?php
/**
 * announcements: add the columns the admin panel's Announcements tab uses
 * (title, body, is_active) alongside the older message/priority/starts_at
 * shape that the `announcements` API reads (audit item 32). Every create
 * from the admin panel failed with "Unknown column 'title'" before.
 */
return function (PDO $pdo): void {
    $cols = $pdo->query('SHOW COLUMNS FROM announcements')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('title', $cols, true)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN title VARCHAR(150) NOT NULL DEFAULT '' AFTER id");
    }
    if (!in_array('body', $cols, true)) {
        $pdo->exec('ALTER TABLE announcements ADD COLUMN body TEXT DEFAULT NULL AFTER title');
    }
    if (!in_array('is_active', $cols, true)) {
        $pdo->exec('ALTER TABLE announcements ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER expires_at');
    }
};
