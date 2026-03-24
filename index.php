<?php
/**
 * index.php — Comedy Bits Elo Tracker
 *
 * Single-file PHP 8.x + SQLite3 (via PDO) application.
 * Tracks stand-up comedy bits ranked by an Elo system based on PPM.
 *
 * Elo mechanics
 * ─────────────
 *  K  = 40
 *  Expected = 1 / (1 + 10 ^ ((OpponentRating - MyRating) / 400))
 *  Score    = 1 (win) | 0.5 (tie) | 0 (loss)  based on PPM
 *  Round-robin among all bits in a show.
 *  IMPORTANT: All Elo deltas are calculated FIRST using the pre-show ratings,
 *             then the sum of changes is applied atomically — this prevents
 *             the match order from biasing results.
 *
 * Requires: pdo_sqlite PHP extension (enabled by default in most setups).
 */

declare(strict_types=1);

// ── Constants ────────────────────────────────────────────────────────────────

define('DB_PATH',    __DIR__ . '/comedy.db');
define('ELO_K',      40);
define('ELO_START',  1000);

// ── Database ─────────────────────────────────────────────────────────────────

function db(): PDO
{
    static $instance = null;
    if ($instance === null) {
        if (!file_exists(DB_PATH)) {
            die('<p class="text-danger p-4"><strong>Database not found.</strong> '
                . 'Run <code>php init_db.php</code> first, then reload.</p>');
        }
        $instance = new PDO('sqlite:' . DB_PATH, options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $instance->exec('PRAGMA journal_mode = WAL');
        $instance->exec('PRAGMA foreign_keys = ON');
    }
    return $instance;
}

// ── Elo helpers ──────────────────────────────────────────────────────────────

function eloExpected(float $myRating, float $oppRating): float
{
    return 1.0 / (1.0 + 10 ** (($oppRating - $myRating) / 400.0));
}

// ── Request handling ─────────────────────────────────────────────────────────

$flash        = [];   // ['type' => 'success|danger', 'html' => '...']
$matchSummary = [];   // filled after a successful show log

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add a new bit ────────────────────────────────────────────────────────
    if ($action === 'add_bit') {
        $name = trim($_POST['bit_name'] ?? '');
        if ($name === '') {
            $flash = ['type' => 'danger', 'html' => 'Bit name cannot be empty.'];
        } elseif (mb_strlen($name) > 200) {
            $flash = ['type' => 'danger', 'html' => 'Bit name is too long (max 200 chars).'];
        } else {
            try {
                $st = db()->prepare('INSERT INTO bits (name) VALUES (:n)');
                $st->execute([':n' => $name]);
                $flash = ['type' => 'success',
                          'html' => 'Bit <strong>' . htmlspecialchars($name) . '</strong> added.'];
            } catch (Exception $e) {
                $msg   = str_contains($e->getMessage(), 'UNIQUE')
                    ? 'A bit with that name already exists.'
                    : htmlspecialchars($e->getMessage());
                $flash = ['type' => 'danger', 'html' => $msg];
            }
        }
    }

    // ── Rename a bit ──────────────────────────────────────────────────────────
    if ($action === 'rename_bit') {
        $id      = (int)($_POST['bit_id'] ?? 0);
        $newName = trim($_POST['new_name'] ?? '');
        if ($id <= 0) {
            $flash = ['type' => 'danger', 'html' => 'Invalid bit ID.'];
        } elseif ($newName === '') {
            $flash = ['type' => 'danger', 'html' => 'Bit name cannot be empty.'];
        } elseif (mb_strlen($newName) > 200) {
            $flash = ['type' => 'danger', 'html' => 'Bit name is too long (max 200 chars).'];
        } else {
            try {
                $st = db()->prepare('UPDATE bits SET name = :name WHERE id = :id');
                $st->execute([':name' => $newName, ':id' => $id]);
                $flash = ['type' => 'success',
                          'html' => 'Bit renamed to <strong>' . htmlspecialchars($newName) . '</strong>.'];
            } catch (Exception $e) {
                $msg   = str_contains($e->getMessage(), 'UNIQUE')
                    ? 'A bit with that name already exists.'
                    : htmlspecialchars($e->getMessage());
                $flash = ['type' => 'danger', 'html' => $msg];
            }
        }
    }

    // ── Delete a bit ──────────────────────────────────────────────────────────
    if ($action === 'delete_bit') {
        $id = (int)($_POST['bit_id'] ?? 0);
        if ($id <= 0) {
            $flash = ['type' => 'danger', 'html' => 'Invalid bit ID.'];
        } else {
            try {
                db()->beginTransaction();
                // Delete performances first (no ON DELETE CASCADE in schema)
                $st = db()->prepare('DELETE FROM performances WHERE bit_id = :id');
                $st->execute([':id' => $id]);
                $st = db()->prepare('DELETE FROM bits WHERE id = :id');
                $st->execute([':id' => $id]);
                db()->commit();
                $flash = ['type' => 'success', 'html' => 'Bit and all its performance history deleted.'];
            } catch (Exception $e) {
                try { db()->rollBack(); } catch (Exception) {}
                $flash = ['type' => 'danger', 'html' => htmlspecialchars($e->getMessage())];
            }
        }
    }

    // ── Log a show ───────────────────────────────────────────────────────────
    if ($action === 'log_show') {
        try {
            $showDate  = $_POST['show_date']  ?? '';
            $bitIds    = $_POST['bit_id']     ?? [];
            $durations = $_POST['duration']   ?? [];
            $scores    = $_POST['score']      ?? [];

            // Validate date
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $showDate)
                || !checkdate(
                    (int)substr($showDate, 5, 2),
                    (int)substr($showDate, 8, 2),
                    (int)substr($showDate, 0, 4)
                )
            ) {
                throw new InvalidArgumentException('Invalid show date.');
            }

            $n = count($bitIds);
            if ($n < 2 || $n > 5) {
                throw new InvalidArgumentException('Select between 2 and 5 bits per show.');
            }
            if ($n !== count($durations) || $n !== count($scores)) {
                throw new InvalidArgumentException('Mismatched input arrays — please reload and try again.');
            }

            // Collect & validate each bit's data
            $bits    = [];
            $seenIds = [];

            for ($i = 0; $i < $n; $i++) {
                $id    = (int)($bitIds[$i]    ?? 0);
                $dur   = (float)($durations[$i] ?? 0);
                $score = (float)($scores[$i]    ?? 0);

                if ($id <= 0) {
                    throw new InvalidArgumentException("Row " . ($i + 1) . ": no bit selected.");
                }
                if (in_array($id, $seenIds, true)) {
                    throw new InvalidArgumentException("Duplicate bit on row " . ($i + 1) . ".");
                }
                if ($dur <= 0) {
                    throw new InvalidArgumentException("Row " . ($i + 1) . ": duration must be > 0.");
                }
                if ($score < 0) {
                    throw new InvalidArgumentException("Row " . ($i + 1) . ": score cannot be negative.");
                }

                $seenIds[] = $id;

                // Fetch current Elo — use the pre-show rating for ALL comparisons
                $st = db()->prepare('SELECT id, name, current_elo FROM bits WHERE id = :id');
                $st->execute([':id' => $id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new InvalidArgumentException("Bit ID $id not found in database.");
                }

                $ppm      = $dur > 0 ? round($score / $dur, 4) : 0.0;
                $bits[$i] = [
                    'id'          => $id,
                    'name'        => $row['name'],
                    'pre_elo'     => (float)$row['current_elo'],  // frozen — never mutated in loop
                    'duration'    => $dur,
                    'raw_score'   => $score,
                    'ppm'         => $ppm,
                    'elo_delta'   => 0.0,   // accumulates across all matchups
                ];
            }

            // ── Round-robin Elo calculation (simultaneous-update method) ─────
            //
            //  We iterate every unique pair. We read ONLY pre_elo (frozen before
            //  the loop). Each pair contributes to elo_delta of both participants.
            //  After all pairs are computed, we apply the total delta in one pass.

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $rA = $bits[$i]['pre_elo'];
                    $rB = $bits[$j]['pre_elo'];

                    $expA = eloExpected($rA, $rB);
                    $expB = 1.0 - $expA; // symmetric — saves a pow() call

                    if ($bits[$i]['ppm'] > $bits[$j]['ppm']) {
                        $sA = 1.0;  $sB = 0.0;  $winner = $bits[$i]['name'];
                    } elseif ($bits[$i]['ppm'] < $bits[$j]['ppm']) {
                        $sA = 0.0;  $sB = 1.0;  $winner = $bits[$j]['name'];
                    } else {
                        $sA = 0.5;  $sB = 0.5;  $winner = 'Tie';
                    }

                    $dA = ELO_K * ($sA - $expA);
                    $dB = ELO_K * ($sB - $expB);

                    $bits[$i]['elo_delta'] += $dA;
                    $bits[$j]['elo_delta'] += $dB;

                    $matchSummary[] = [
                        'nameA'   => $bits[$i]['name'],
                        'nameB'   => $bits[$j]['name'],
                        'ppmA'    => $bits[$i]['ppm'],
                        'ppmB'    => $bits[$j]['ppm'],
                        'winner'  => $winner,
                        'deltaA'  => $dA,
                        'deltaB'  => $dB,
                    ];
                }
            }

            // ── Apply all deltas atomically ──────────────────────────────────
            db()->beginTransaction();

            foreach ($bits as &$bit) {
                $bit['new_elo'] = round($bit['pre_elo'] + $bit['elo_delta'], 1);

                $st = db()->prepare(
                    'UPDATE bits
                        SET current_elo         = :elo,
                            times_performed     = times_performed + 1,
                            last_performed_date = :date
                      WHERE id = :id'
                );
                $st->execute([
                    ':elo'  => $bit['new_elo'],
                    ':date' => $showDate,
                    ':id'   => $bit['id'],
                ]);

                $st = db()->prepare(
                    'INSERT INTO performances
                         (bit_id, show_date, duration_mins, total_p_line_score, calculated_ppm)
                     VALUES (:bid, :date, :dur, :sc, :ppm)'
                );
                $st->execute([
                    ':bid'  => $bit['id'],
                    ':date' => $showDate,
                    ':dur'  => $bit['duration'],
                    ':sc'   => $bit['raw_score'],
                    ':ppm'  => $bit['ppm'],
                ]);
            }
            unset($bit); // break reference

            db()->commit();

            $flash = ['type' => 'success',
                      'html' => 'Show logged! Elo ratings updated across '
                                . count($matchSummary) . ' match-up(s).'];

        } catch (Exception $e) {
            try { db()->rollBack(); } catch (Exception) {}
            $flash        = ['type' => 'danger',    'html' => htmlspecialchars($e->getMessage())];
            $matchSummary = [];
        }
    }
}

// ── Dashboard data ────────────────────────────────────────────────────────────

$allowedSortCols = [
    'name',
    'current_elo',
    'times_performed',
    'last_performed_date',
    'best_pps',
    'avg_pps',
    'avg_length_secs'
];
$sortCol = in_array($_GET['sort'] ?? '', $allowedSortCols, true) ? $_GET['sort'] : 'current_elo';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

// Safe because $sortCol is whitelisted above
$sql = "
    SELECT
        b.*,
        MAX(p.calculated_ppm) AS best_pps,
        AVG(p.calculated_ppm) AS avg_pps,
        AVG(p.duration_mins)  AS avg_length_secs,
        COUNT(p.id)           AS perf_count
    FROM bits b
    LEFT JOIN performances p ON p.bit_id = b.id
    GROUP BY b.id
    ORDER BY $sortCol $sortDir
";
$allBits = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$testedBits = array_values(array_filter($allBits, fn($b) => (int)$b['perf_count'] > 0));
$untestedBits = array_values(array_filter($allBits, fn($b) => (int)$b['perf_count'] === 0));

// Dropdown list (always alpha order for ergonomics)
$dropdownBits = db()->query('SELECT id, name FROM bits ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);

// ── Helpers ───────────────────────────────────────────────────────────────────

function sortLink(string $col, string $label, string $current, string $dir): string
{
    $newDir = ($current === $col && $dir === 'ASC') ? 'DESC' : 'ASC';
    $arrow  = '';
    if ($current === $col) {
        $arrow = $dir === 'ASC' ? ' &#9650;' : ' &#9660;';
    }
    $href = '?sort=' . urlencode($col) . '&dir=' . urlencode($newDir);
    return '<a href="' . $href . '" class="text-decoration-none text-white">'
         . htmlspecialchars($label) . $arrow . '</a>';
}

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comedy Bits Elo Tracker</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <style>
        body   { background: #f0f2f5; }
        .elo-badge { letter-spacing: .02em; font-variant-numeric: tabular-nums; }
        th a   { white-space: nowrap; }
        .match-win  { color: #198754; font-weight: 600; }
        .match-loss { color: #dc3545; }
        .match-tie  { color: #6c757d; }
        .delta-pos  { color: #198754; }
        .delta-neg  { color: #dc3545; }
        .btn-edit   { opacity: .55; transition: opacity .15s; line-height: 1; text-decoration: none !important; }
        .btn-edit:hover { opacity: 1; }
    </style>
</head>
<body>

<div class="container-lg py-4">

    <!-- Header -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <span style="font-size:2rem">🎤</span>
        <div>
            <h1 class="mb-0 fw-bold">Comedy Bits Elo Tracker</h1>
            <small class="text-muted">PPM-based Elo ranking for your stand-up bits</small>
        </div>
    </div>

    <!-- Flash message -->
    <?php if (!empty($flash)): ?>
        <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible" role="alert">
            <?= $flash['html'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Match summary (shown after a successful show log) -->
    <?php if (!empty($matchSummary)): ?>
        <div class="card mb-4 border-primary">
            <div class="card-header bg-primary text-white">
                <strong>Match Results — <?= count($matchSummary) ?> match-up(s) this show</strong>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Bit A</th>
                            <th class="text-end">PPM A</th>
                            <th class="text-center">vs</th>
                            <th>Bit B</th>
                            <th class="text-end">PPM B</th>
                            <th>Winner</th>
                            <th class="text-end">ΔA</th>
                            <th class="text-end">ΔB</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matchSummary as $m): ?>
                            <?php
                                $clA = $m['winner'] === $m['nameA'] ? 'match-win'
                                     : ($m['winner'] === 'Tie'   ? 'match-tie' : 'match-loss');
                                $clB = $m['winner'] === $m['nameB'] ? 'match-win'
                                     : ($m['winner'] === 'Tie'   ? 'match-tie' : 'match-loss');
                            ?>
                            <tr>
                                <td class="<?= $clA ?>"><?= h($m['nameA']) ?></td>
                                <td class="text-end <?= $clA ?>"><?= number_format($m['ppmA'], 2) ?></td>
                                <td class="text-center text-muted">vs</td>
                                <td class="<?= $clB ?>"><?= h($m['nameB']) ?></td>
                                <td class="text-end <?= $clB ?>"><?= number_format($m['ppmB'], 2) ?></td>
                                <td><strong><?= h($m['winner']) ?></strong></td>
                                <td class="text-end <?= $m['deltaA'] >= 0 ? 'delta-pos' : 'delta-neg' ?>">
                                    <?= ($m['deltaA'] >= 0 ? '+' : '') . number_format($m['deltaA'], 1) ?>
                                </td>
                                <td class="text-end <?= $m['deltaB'] >= 0 ? 'delta-pos' : 'delta-neg' ?>">
                                    <?= ($m['deltaB'] >= 0 ? '+' : '') . number_format($m['deltaB'], 1) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── Leaderboard: Tested Bits ── -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">&#127942; Tested Bits</h5>
            <small class="text-white-50"><?= count($testedBits) ?> tested / <?= count($allBits) ?> total</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:3rem">#</th>
                            <th style="width:2.5rem"></th>
                            <th><?= sortLink('name',                'Bit Name',           $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('current_elo',         'Elo Rating',         $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('times_performed',     'Performances',       $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('last_performed_date', 'Last Performed',     $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('best_pps',            'Best PPS',           $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('avg_pps',             'Avg PPS',            $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('avg_length_secs',     'Avg Length (secs)',  $sortCol, $sortDir) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($testedBits)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    No tested bits yet — log at least one show.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($testedBits as $rank => $bit): ?>
                            <?php
                                $bestPps = $bit['best_pps'] !== null ? number_format((float)$bit['best_pps'], 2) : '—';
                                $avgPps  = $bit['avg_pps'] !== null ? number_format((float)$bit['avg_pps'], 2) : '—';
                                $avgLen  = $bit['avg_length_secs'] !== null ? (string)((int)(ceil(((float)$bit['avg_length_secs']) / 5) * 5)) : '—';
                                $elo     = (float)$bit['current_elo'];
                                $eloBg   = $elo >= 1100 ? 'bg-success' : ($elo >= 1000 ? 'bg-primary' : 'bg-secondary');
                            ?>
                            <tr>
                                <td class="text-muted"><?= $rank + 1 ?></td>
                                <td class="text-center">
                                    <button type="button"
                                            class="btn btn-sm btn-link btn-edit p-0"
                                            title="Edit bit"
                                            onclick='openEditModal(<?= (int)$bit['id'] ?>, <?= json_encode($bit['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    >&#9999;&#65039;</button>
                                </td>
                                <td class="fw-semibold"><?= h($bit['name']) ?></td>
                                <td>
                                    <span class="badge <?= $eloBg ?> elo-badge fs-6">
                                        <?= number_format($elo, 1) ?>
                                    </span>
                                </td>
                                <td><?= (int)$bit['times_performed'] ?></td>
                                <td><?= $bit['last_performed_date'] ? h($bit['last_performed_date']) : '<span class="text-muted">—</span>' ?></td>
                                <td><?= $bestPps ?></td>
                                <td><?= $avgPps ?></td>
                                <td><?= $avgLen ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Leaderboard: Untested Bits ── -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">🧪 Untested Bits</h5>
            <small class="text-white-50"><?= count($untestedBits) ?> untested</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:3rem">#</th>
                            <th style="width:2.5rem"></th>
                            <th><?= sortLink('name',                'Bit Name',           $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('current_elo',         'Elo Rating',         $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('times_performed',     'Performances',       $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('last_performed_date', 'Last Performed',     $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('best_pps',            'Best PPS',           $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('avg_pps',             'Avg PPS',            $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('avg_length_secs',     'Avg Length (secs)',  $sortCol, $sortDir) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($untestedBits)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    No untested bits.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($untestedBits as $rank => $bit): ?>
                            <?php
                                $bestPps = $bit['best_pps'] !== null ? number_format((float)$bit['best_pps'], 2) : '—';
                                $avgPps  = $bit['avg_pps'] !== null ? number_format((float)$bit['avg_pps'], 2) : '—';
                                $avgLen  = $bit['avg_length_secs'] !== null ? (string)((int)(ceil(((float)$bit['avg_length_secs']) / 5) * 5)) : '—';
                                $elo     = (float)$bit['current_elo'];
                                $eloBg   = $elo >= 1100 ? 'bg-success' : ($elo >= 1000 ? 'bg-primary' : 'bg-secondary');
                            ?>
                            <tr>
                                <td class="text-muted"><?= $rank + 1 ?></td>
                                <td class="text-center">
                                    <button type="button"
                                            class="btn btn-sm btn-link btn-edit p-0"
                                            title="Edit bit"
                                            onclick='openEditModal(<?= (int)$bit['id'] ?>, <?= json_encode($bit['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    >&#9999;&#65039;</button>
                                </td>
                                <td class="fw-semibold"><?= h($bit['name']) ?></td>
                                <td>
                                    <span class="badge <?= $eloBg ?> elo-badge fs-6">
                                        <?= number_format($elo, 1) ?>
                                    </span>
                                </td>
                                <td><?= (int)$bit['times_performed'] ?></td>
                                <td><?= $bit['last_performed_date'] ? h($bit['last_performed_date']) : '<span class="text-muted">—</span>' ?></td>
                                <td><?= $bestPps ?></td>
                                <td><?= $avgPps ?></td>
                                <td><?= $avgLen ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Two-column forms ── -->
    <div class="row g-4">

        <!-- Add Bit -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header"><h5 class="mb-0">➕ Add New Bit</h5></div>
                <div class="card-body">
                    <form method="POST" novalidate>
                        <input type="hidden" name="action" value="add_bit">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Bit Name</label>
                            <input type="text" name="bit_name" class="form-control"
                                   placeholder="e.g. The Airport Rant"
                                   maxlength="200" required autocomplete="off">
                        </div>
                        <button type="submit" class="btn btn-success w-100">Add Bit</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Log Show -->
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header"><h5 class="mb-0">📋 Log a Show</h5></div>
                <div class="card-body">
                    <form method="POST" id="showForm" novalidate>
                        <input type="hidden" name="action" value="log_show">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Show Date</label>
                            <input type="date" name="show_date" class="form-control"
                                   value="<?= h(date('Y-m-d')) ?>" required>
                        </div>

                        <!-- Column headers for bit rows -->
                        <div class="row g-2 mb-1 text-muted small">
                            <div class="col-5">Bit</div>
                            <div class="col-3">Duration (secs)</div>
                            <div class="col-4">P-Line Score</div>
                        </div>

                        <div id="bitRows"></div>

                        <div class="d-flex gap-2 mt-2 mb-3">
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    onclick="addBitRow()">+ Add Bit</button>
                            <button type="button" class="btn btn-outline-danger btn-sm"
                                    onclick="removeBitRow()">− Remove Last</button>
                            <span class="ms-auto text-muted small align-self-center" id="rowCount"></span>
                        </div>

                        <div class="alert alert-info py-2 small mb-3">
                            <strong>PPS</strong> = Score ÷ Duration (seconds).
                            Round-robin Elo (K=40) runs across all selected bits simultaneously.
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            Calculate &amp; Log Show
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Recent Performances ── -->
    <div class="card mt-4 shadow-sm">
        <div class="card-header"><h5 class="mb-0">📈 Recent Performances (last 30)</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead class="table-secondary">
                        <tr>
                            <th>Date</th>
                            <th>Bit</th>
                            <th class="text-end">Duration (secs)</th>
                            <th class="text-end">Score</th>
                            <th class="text-end">PPM</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            $recQ = db()->query(
                                'SELECT p.show_date, b.name,
                                        p.duration_mins, p.total_p_line_score, p.calculated_ppm
                                   FROM performances p
                                   JOIN bits b ON p.bit_id = b.id
                                  ORDER BY p.show_date DESC, p.id DESC
                                  LIMIT 30'
                            );
                            $anyRecent = false;
                            while ($row = $recQ->fetch(PDO::FETCH_ASSOC)):
                                $anyRecent = true;
                        ?>
                        <tr>
                            <td><?= h($row['show_date']) ?></td>
                            <td><?= h($row['name']) ?></td>
                            <td class="text-end"><?= number_format((float)$row['duration_mins'], 1) ?></td>
                            <td class="text-end"><?= number_format((float)$row['total_p_line_score'], 1) ?></td>
                            <td class="text-end fw-bold"><?= number_format((float)$row['calculated_ppm'], 2) ?></td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if (!$anyRecent): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No performances yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <footer class="text-center text-muted small mt-4">Comedy Bits Elo Tracker &middot; PHP 8 + SQLite3</footer>

</div><!-- /container -->

<!-- ── Edit Bit Modal ───────────────────────────────────────────────────────── -->
<div class="modal fade" id="editBitModal" tabindex="-1" aria-labelledby="editBitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editBitModalLabel">&#9999;&#65039; Edit Bit</h5>
                <button type="button" class="btn-close" id="closeEditModalBtn" aria-label="Close"></button>
            </div>

            <!-- Rename form -->
            <form method="POST" id="renameForm">
                <input type="hidden" name="action" value="rename_bit">
                <input type="hidden" name="bit_id" id="editBitId">
                <div class="modal-body">
                    <label class="form-label fw-semibold" for="editBitName">Bit Name</label>
                    <input type="text" id="editBitName" name="new_name"
                           class="form-control" maxlength="200" required autocomplete="off">
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-danger btn-sm"
                            id="showDeleteConfirmBtn">Delete this bit&hellip;</button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" id="cancelRenameBtn">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Name</button>
                    </div>
                </div>
            </form>

            <!-- Delete confirmation (hidden until requested) -->
            <div id="deleteConfirmPanel" class="d-none">
                <div class="modal-body border-top">
                    <div class="alert alert-danger mb-0">
                        <strong>Delete &ldquo;<span id="deleteConfirmName"></span>&rdquo;?</strong><br>
                        This will permanently remove the bit <em>and all its performance history</em>.
                        This cannot be undone.
                    </div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="delete_bit">
                    <input type="hidden" name="bit_id" id="deleteBitId">
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm"
                                id="cancelDeleteBtn">Cancel</button>
                        <button type="submit" class="btn btn-danger">Yes, delete permanently</button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<!-- ── JavaScript: dynamic bit-row builder ─────────────────────────────────── -->
<script>
'use strict';

// PHP drops the bits list as JSON — used to populate each row's dropdown.
const ALL_BITS = <?= json_encode(
    array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], $dropdownBits),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;

const MIN_ROWS = 2;
const MAX_ROWS = 5;

function escHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function buildOptions(selectedId) {
    let html = '<option value="">— Select Bit —</option>';
    for (const b of ALL_BITS) {
        const sel = b.id === selectedId ? ' selected' : '';
        html += `<option value="${b.id}"${sel}>${escHtml(b.name)}</option>`;
    }
    return html;
}

function addBitRow(selectedId = 0) {
    const container = document.getElementById('bitRows');
    const count     = container.querySelectorAll('.bit-row').length;
    if (count >= MAX_ROWS) { alert(`Maximum ${MAX_ROWS} bits per show.`); return; }

    const div = document.createElement('div');
    div.className = 'row g-2 mb-2 bit-row';
    div.innerHTML = `
        <div class="col-5">
            <select name="bit_id[]" class="form-select" required>
                ${buildOptions(selectedId)}
            </select>
        </div>
        <div class="col-3">
            <input type="number" name="duration[]" class="form-control"
                   placeholder="e.g. 80" step="1" min="1" max="3600" required>
        </div>
        <div class="col-4">
            <input type="number" name="score[]" class="form-control"
                   placeholder="e.g. 28" step="0.1" min="0" required>
        </div>`;
    container.appendChild(div);
    updateRowCount();
}

function removeBitRow() {
    const container = document.getElementById('bitRows');
    const rows      = container.querySelectorAll('.bit-row');
    if (rows.length <= MIN_ROWS) { alert(`Minimum ${MIN_ROWS} bits required for a comparison.`); return; }
    rows[rows.length - 1].remove();
    updateRowCount();
}

function updateRowCount() {
    const n = document.getElementById('bitRows').querySelectorAll('.bit-row').length;
    const matches = n * (n - 1) / 2;
    document.getElementById('rowCount').textContent =
        `${n} bits → ${matches} match-up${matches !== 1 ? 's' : ''}`;
}

// Validate no duplicate bits on submit
document.getElementById('showForm').addEventListener('submit', function (e) {
    const selects = [...this.querySelectorAll('select[name="bit_id[]"]')];
    const ids     = selects.map(s => s.value).filter(v => v !== '');
    if (new Set(ids).size !== ids.length) {
        e.preventDefault();
        alert('You have selected the same bit more than once. Each bit must be unique per show.');
    }
});

// Initialise with 3 rows on page load
document.addEventListener('DOMContentLoaded', () => {
    for (let i = 0; i < 3; i++) addBitRow();
});

// ── Edit Bit Modal ────────────────────────────────────────────────────────────

// Bootstrap loads AFTER this script tag, so initialise the modal lazily
// the first time openEditModal() is called rather than at parse time.
let editModal = null;
let modalBackdrop = null;

const editModalEl        = document.getElementById('editBitModal');
const editBitIdInput     = document.getElementById('editBitId');
const editBitNameInput   = document.getElementById('editBitName');
const deleteBitIdInput   = document.getElementById('deleteBitId');
const deleteConfirmName  = document.getElementById('deleteConfirmName');
const deleteConfirmPanel = document.getElementById('deleteConfirmPanel');
const renameForm         = document.getElementById('renameForm');

function showEditModal() {
    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
        if (!editModal) {
            editModal = new window.bootstrap.Modal(editModalEl);
        }
        editModal.show();
        return;
    }

    // Fallback when Bootstrap JS is unavailable
    editModalEl.style.display = 'block';
    editModalEl.classList.add('show');
    editModalEl.removeAttribute('aria-hidden');
    document.body.classList.add('modal-open');

    if (!modalBackdrop) {
        modalBackdrop = document.createElement('div');
        modalBackdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(modalBackdrop);
    }
}

function hideEditModal() {
    if (editModal) {
        editModal.hide();
        return;
    }

    // Fallback when Bootstrap JS is unavailable
    editModalEl.classList.remove('show');
    editModalEl.style.display = 'none';
    editModalEl.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');

    if (modalBackdrop) {
        modalBackdrop.remove();
        modalBackdrop = null;
    }
}

function openEditModal(id, name) {
    // Reset to rename view
    deleteConfirmPanel.classList.add('d-none');
    renameForm.classList.remove('d-none');

    editBitIdInput.value   = id;
    deleteBitIdInput.value = id;
    editBitNameInput.value = name;
    deleteConfirmName.textContent = name;

    showEditModal();
    setTimeout(() => editBitNameInput.select(), 50);
}

// Keep the delete confirm name in sync if the user edits the name field
editBitNameInput.addEventListener('input', () => {
    deleteConfirmName.textContent = editBitNameInput.value || '(unnamed)';
});

// Show delete confirmation panel
document.getElementById('showDeleteConfirmBtn').addEventListener('click', () => {
    renameForm.classList.add('d-none');
    deleteConfirmPanel.classList.remove('d-none');
});

// Cancel delete — go back to rename view
document.getElementById('cancelDeleteBtn').addEventListener('click', () => {
    deleteConfirmPanel.classList.add('d-none');
    renameForm.classList.remove('d-none');
});

document.getElementById('cancelRenameBtn').addEventListener('click', hideEditModal);
document.getElementById('closeEditModalBtn').addEventListener('click', hideEditModal);

// Fallback close when clicking outside the dialog
editModalEl.addEventListener('click', (e) => {
    if (e.target === editModalEl) {
        hideEditModal();
    }
});

// Fallback Escape key support
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && editModalEl.classList.contains('show')) {
        hideEditModal();
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmHr8dHMLTCEiAqmBxnz0G5vGvLX"
        crossorigin="anonymous"></script>
</body>
</html>
