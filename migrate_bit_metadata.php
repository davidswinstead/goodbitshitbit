<?php
/**
 * One-time browser migration adding status and notes to the bits table.
 * Rerunning is safe and makes no changes once
 * both columns exist.
 */

declare(strict_types=1);

$dbPath = __DIR__ . '/comedy.db';

function reportMigration(string $message, bool $success): void
{
    http_response_code($success ? 200 : 500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Bit metadata migration</title></head><body>'
        . '<main style="max-width: 42rem; margin: 4rem auto; padding: 0 1rem; font: 16px/1.5 sans-serif;">'
        . '<h1>Bit metadata migration</h1><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        . '<p><a href="index.php">Return to the tracker</a></p></main></body></html>';
}

if (!file_exists($dbPath)) {
    reportMigration('Database not found. Run init_db.php first.', false);
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbPath, options: [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $columns = array_column($pdo->query('PRAGMA table_info(bits)')->fetchAll(), 'name');
    if (!$columns) {
        throw new RuntimeException('The bits table does not exist. Run init_db.php first.');
    }

    $addStatus = !in_array('status', $columns, true);
    $addNotes = !in_array('notes', $columns, true);
    if (!$addStatus && !$addNotes) {
        reportMigration('No changes made: status and notes columns already exist.', true);
        exit(0);
    }

    $pdo->beginTransaction();
    if ($addStatus) {
        $pdo->exec("ALTER TABLE bits ADD COLUMN status INTEGER NOT NULL DEFAULT 1");
        $pdo->exec('UPDATE bits SET status = 1');
    }
    if ($addNotes) {
        $pdo->exec("ALTER TABLE bits ADD COLUMN notes TEXT NOT NULL DEFAULT ''");
    }
    $pdo->commit();

    reportMigration('Migration complete. Existing bits have status 1 and empty notes.', true);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable) {}
    }
    reportMigration('Migration failed: ' . $e->getMessage(), false);
    exit(1);
}