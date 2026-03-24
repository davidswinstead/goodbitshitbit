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
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            name            TEXT    NOT NULL UNIQUE COLLATE NOCASE,
            current_elo     REAL    NOT NULL DEFAULT 1000.0,
            times_performed INTEGER NOT NULL DEFAULT 0
        )
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS gigs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            gig_date    TEXT    NOT NULL,
            name        TEXT    NOT NULL,
            youtube_url TEXT    NOT NULL DEFAULT ''
        )
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS performances (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            bit_id              INTEGER NOT NULL REFERENCES bits(id),
            gig_id              INTEGER NOT NULL REFERENCES gigs(id),
            duration_mins       REAL    NOT NULL,
            total_p_line_score  REAL    NOT NULL,
            calculated_ppm      REAL    NOT NULL
        )
    SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_perf_bit  ON performances(bit_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_perf_gig  ON performances(gig_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gig_date  ON gigs(gig_date)');

    echo "[OK] Database created / verified: $dbPath\n";
} catch (Exception $e) {
    exit("[FAIL] Database error: " . $e->getMessage() . "\n");
}

// ── 2. Generate .htpasswd with Apache SHA1 hash ───────────────────────────────
//
//  Format: {SHA}<base64(sha1(password))>
//  This is supported by ALL Apache versions via mod_authn_file without any
//  additional compile-time options — unlike bcrypt ($2y$) which requires
//  Apache to be built with --with-crypto (not available on most cPanel hosts).
//  NOTE: SHA1 is sufficient for Basic Auth over HTTPS (the transport layer
//  provides the real security; the hash just prevents plaintext in the file).

$htpasswdUser = 'david';
$htpasswdPass = 'smeghead';
$hash         = '{SHA}' . base64_encode(sha1($htpasswdPass, true));

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

