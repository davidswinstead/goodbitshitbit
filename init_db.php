<?php
/**
 * init_db.php — One-time setup script for the Comedy Bits Elo Tracker.
 *
 * Run once from the command line:   php init_db.php
 * Or visit once in the browser, then protect / delete it.
 *
 * Creates:
 *   comedy.db   — SQLite3 database with bits + performances tables
 *   .htpasswd   — Apache Basic Auth credentials (david / smeghead, bcrypt)
 *   .htaccess   — Injects the absolute path to .htpasswd automatically
 *
 * Requires: pdo_sqlite PHP extension (enabled by default in most setups).
 */

declare(strict_types=1);

$dir      = __DIR__;
$dbPath   = $dir . '/comedy.db';
$htpwPath = $dir . '/.htpasswd';
$htaPath  = $dir . '/.htaccess';

// ── 1. Create / verify SQLite database via PDO ──────────────────────────────

try {
    $pdo = new PDO('sqlite:' . $dbPath, options: [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS bits (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            name                TEXT    NOT NULL UNIQUE COLLATE NOCASE,
            current_elo         REAL    NOT NULL DEFAULT 1000.0,
            times_performed     INTEGER NOT NULL DEFAULT 0,
            last_performed_date TEXT
        )
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS performances (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            bit_id              INTEGER NOT NULL REFERENCES bits(id),
            show_date           TEXT    NOT NULL,
            duration_mins       REAL    NOT NULL,
            total_p_line_score  REAL    NOT NULL,
            calculated_ppm      REAL    NOT NULL
        )
    SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_perf_bit ON performances(bit_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_perf_date ON performances(show_date)');

    echo "[OK] Database created / verified: $dbPath\n";
} catch (Exception $e) {
    exit("[FAIL] Database error: " . $e->getMessage() . "\n");
}

// ── 2. Generate .htpasswd with bcrypt hash ───────────────────────────────────
//
//  Apache 2.4+ supports bcrypt ($2y$) natively via mod_authn_file.
//  The hash is re-generated each time init_db.php is run (new random salt).
//  The user-visible instruction is: username=david, password=smeghead.

$htpasswdUser = 'david';
$htpasswdPass = 'smeghead';
$hash         = password_hash($htpasswdPass, PASSWORD_BCRYPT, ['cost' => 10]);

file_put_contents($htpwPath, "$htpasswdUser:$hash\n");
@chmod($htpwPath, 0640); // no-op on Windows; restricts to owner+group on Linux/Mac

echo "[OK] .htpasswd written: $htpwPath\n";
echo "     User: $htpasswdUser  |  Password: $htpasswdPass\n";

// ── 3. Patch the AuthUserFile path in .htaccess ──────────────────────────────
//
//  .htaccess ships with a placeholder; we replace it with the real absolute
//  path so Apache can actually find the file.

if (!file_exists($htaPath)) {
    exit("[WARN] .htaccess not found at $htaPath — skipping AuthUserFile patch.\n" .
         "       Set AuthUserFile manually to: $htpwPath\n");
}

$htaccess = file_get_contents($htaPath);
$patched  = preg_replace(
    '/^AuthUserFile\s+.*$/m',
    'AuthUserFile ' . $htpwPath,
    $htaccess
);

file_put_contents($htaPath, $patched);

echo "[OK] .htaccess patched with AuthUserFile path.\n";
echo "\n[DONE] Setup complete. You may now delete or restrict access to init_db.php.\n";

