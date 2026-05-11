<?php
/**
 * convert.php — One-time migration for Comedy Bits Elo Tracker.
 *
 * Migrates the database from the old schema to the new schema:
 *   OLD: bits(last_performed_date), performances(show_date)
 *   NEW: gigs table; performances(gig_id); bits no longer stores last_performed_date
 *
 * SQLite 3.26 compatible — uses rename/create/copy/drop instead of DROP COLUMN.
 *
 * Run ONCE in production:  php convert.php
 * BACK UP your database before running!
 * Delete this file after successful migration.
 */

declare(strict_types=1);

$dbPath = __DIR__ . '/comedy.db';

if (!file_exists($dbPath)) {
    exit("[FAIL] comedy.db not found at $dbPath — nothing to migrate.\n");
}

// ── Create a timestamped backup ──────────────────────────────────────────────

$backupPath = $dbPath . '.bak.' . date('YmdHis');
if (!copy($dbPath, $backupPath)) {
    exit("[FAIL] Could not create backup at $backupPath — aborting for safety.\n");
}
echo "[OK]   Backup created: $backupPath\n";

try {
    $pdo = new PDO('sqlite:' . $dbPath, options: [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Disable FK enforcement during restructuring
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('PRAGMA journal_mode = WAL');

    $pdo->beginTransaction();

    // ── Step 1: Create gigs table ────────────────────────────────────────────

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS gigs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            gig_date    TEXT    NOT NULL,
            name        TEXT    NOT NULL,
            youtube_url TEXT    NOT NULL DEFAULT ''
        )
    SQL);
    echo "[OK]   Created gigs table (or already existed).\n";

    // ── Step 2: Add gig_id column to performances (if missing) ──────────────

    $perfCols     = $pdo->query("PRAGMA table_info(performances)")->fetchAll();
    $perfColNames = array_column($perfCols, 'name');

    if (!in_array('gig_id', $perfColNames, true)) {
        $pdo->exec('ALTER TABLE performances ADD COLUMN gig_id INTEGER');
        echo "[OK]   Added gig_id column to performances.\n";
    } else {
        echo "[SKIP] gig_id column already exists on performances.\n";
    }

    // ── Step 3: Create one gig per unique show_date & link performances ──────

    if (in_array('show_date', $perfColNames, true)) {
        $dates = $pdo->query(
            "SELECT DISTINCT show_date FROM performances WHERE show_date IS NOT NULL ORDER BY show_date"
        )->fetchAll();

        $insertGig  = $pdo->prepare(
            "INSERT INTO gigs (gig_date, name, youtube_url) VALUES (:d, :n, '')"
        );
        $updatePerfs = $pdo->prepare(
            "UPDATE performances SET gig_id = :gig WHERE show_date = :d AND gig_id IS NULL"
        );

        echo "[INFO] Found " . count($dates) . " unique show date(s) to migrate.\n";

        foreach ($dates as $row) {
            $date = $row['show_date'];

            // Skip if a gig was already created for this date (idempotent re-run)
            $checkSt = $pdo->prepare("SELECT id FROM gigs WHERE gig_date = :d AND name = :n LIMIT 1");
            $checkSt->execute([':d' => $date, ':n' => $date]);
            if ($existing = $checkSt->fetch()) {
                $gigId = (int)$existing['id'];
                $updatePerfs->execute([':gig' => $gigId, ':d' => $date]);
                echo "[SKIP] Gig for $date already exists (id=$gigId) — re-linked performances.\n";
                continue;
            }

            $insertGig->execute([':d' => $date, ':n' => $date]);
            $gigId = (int)$pdo->lastInsertId();
            $updatePerfs->execute([':gig' => $gigId, ':d' => $date]);

            $linked = (int)$pdo->query(
                "SELECT COUNT(*) AS c FROM performances WHERE gig_id = $gigId"
            )->fetch()['c'];
            echo "[OK]   Created gig id=$gigId for date $date — linked $linked performance(s).\n";
        }
    } else {
        echo "[SKIP] show_date not found on performances — already migrated?\n";
    }

    // Verify every performance has a gig_id before proceeding
    $orphans = (int)$pdo->query(
        "SELECT COUNT(*) AS c FROM performances WHERE gig_id IS NULL"
    )->fetch()['c'];

    if ($orphans > 0) {
        throw new RuntimeException(
            "$orphans performance row(s) still have no gig_id. "
            . "Check for performances with NULL show_date in the original data."
        );
    }
    echo "[OK]   All performance rows have a gig_id.\n";

    // ── Step 4: Recreate performances without show_date ──────────────────────

    $pdo->exec('DROP TABLE IF EXISTS performances_new');
    $pdo->exec(<<<SQL
        CREATE TABLE performances_new (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            bit_id              INTEGER NOT NULL REFERENCES bits(id),
            gig_id              INTEGER NOT NULL REFERENCES gigs(id),
            duration_mins       REAL    NOT NULL,
            total_p_line_score  REAL    NOT NULL,
            calculated_ppm      REAL    NOT NULL
        )
    SQL);

    $pdo->exec(<<<SQL
        INSERT INTO performances_new
               (id, bit_id, gig_id, duration_mins, total_p_line_score, calculated_ppm)
        SELECT  id, bit_id, gig_id, duration_mins, total_p_line_score, calculated_ppm
        FROM performances
    SQL);

    $oldPerfCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM performances")->fetch()['c'];
    $newPerfCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM performances_new")->fetch()['c'];
    if ($oldPerfCount !== $newPerfCount) {
        throw new RuntimeException(
            "Row count mismatch after performances copy: old=$oldPerfCount new=$newPerfCount"
        );
    }

    $pdo->exec('DROP TABLE performances');
    $pdo->exec('ALTER TABLE performances_new RENAME TO performances');
    echo "[OK]   Recreated performances table ($newPerfCount rows; show_date removed).\n";

    // ── Step 5: Recreate bits without last_performed_date ────────────────────

    $bitCols     = $pdo->query("PRAGMA table_info(bits)")->fetchAll();
    $bitColNames = array_column($bitCols, 'name');
    $hadLastPerf = in_array('last_performed_date', $bitColNames, true);

    $pdo->exec('DROP TABLE IF EXISTS bits_new');
    $pdo->exec(<<<SQL
        CREATE TABLE bits_new (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            name            TEXT    NOT NULL UNIQUE COLLATE NOCASE,
            current_elo     REAL    NOT NULL DEFAULT 1000.0,
            times_performed INTEGER NOT NULL DEFAULT 0
        )
    SQL);

    $pdo->exec(<<<SQL
        INSERT INTO bits_new (id, name, current_elo, times_performed)
        SELECT id, name, current_elo, times_performed FROM bits
    SQL);

    $oldBitCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM bits")->fetch()['c'];
    $newBitCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM bits_new")->fetch()['c'];
    if ($oldBitCount !== $newBitCount) {
        throw new RuntimeException(
            "Row count mismatch after bits copy: old=$oldBitCount new=$newBitCount"
        );
    }

    $pdo->exec('DROP TABLE bits');
    $pdo->exec('ALTER TABLE bits_new RENAME TO bits');

    $removed = $hadLastPerf ? ' (last_performed_date removed)' : ' (last_performed_date was already absent)';
    echo "[OK]   Recreated bits table ($newBitCount rows$removed).\n";

    // ── Step 6: Recreate indexes ─────────────────────────────────────────────

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_perf_bit  ON performances(bit_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_perf_gig  ON performances(gig_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gig_date  ON gigs(gig_date)');
    echo "[OK]   Indexes created/verified.\n";

    $pdo->commit();

    // ── Post-migration integrity checks ─────────────────────────────────────

    $pdo->exec('PRAGMA foreign_keys = ON');

    $ic = $pdo->query('PRAGMA integrity_check')->fetch()['integrity_check'];
    echo "[OK]   integrity_check: $ic\n";

    $fkViolations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    if (!empty($fkViolations)) {
        echo "[WARN] Foreign key violations detected:\n";
        foreach ($fkViolations as $v) {
            echo "       Table {$v['table']} rowid={$v['rowid']} → parent={$v['parent']}\n";
        }
    } else {
        echo "[OK]   Foreign key check passed.\n";
    }

    echo "\n[DONE] Migration complete.\n";
    echo "       Backup at: $backupPath\n";
    echo "       Verify the app works, then delete convert.php and the backup file.\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable) {}
    }
    exit(
        "[FAIL] Migration failed: " . $e->getMessage() . "\n"
        . "       Your database has NOT been modified (transaction rolled back).\n"
        . "       Backup is at: $backupPath\n"
    );
}
