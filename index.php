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

// ── Full rating recalculation from scratch ──────────────────────────────────
//
//  Resets all bits to baseline Elo, then replays all gigs in chronological order
//  to recompute final ratings deterministically. Called after any gig add/edit.

function recalculateAllRatings(?int $summaryGigId = null): array
{
    $summary = [];
    $summaryBitDeltas = [];

    // Reset all bits to baseline
    db()->exec('UPDATE bits SET current_elo = ' . ELO_START . ', times_performed = 0');

    // Fetch all gigs in strict chronological order
    $gigs = db()->query(
        'SELECT id, gig_date FROM gigs ORDER BY gig_date ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    // Batch-fetch all performances grouped by gig
    $gigPerfMap = [];
    $allPerfs = db()->query(
        'SELECT gig_id, bit_id, duration_mins, total_p_line_score, calculated_ppm
           FROM performances
          ORDER BY gig_id, id'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allPerfs as $p) {
        $gid = (int)$p['gig_id'];
        if (!isset($gigPerfMap[$gid])) {
            $gigPerfMap[$gid] = [];
        }
        $gigPerfMap[$gid][] = $p;
    }

    db()->beginTransaction();

    // Replay each gig
    foreach ($gigs as $gig) {
        $gigId = (int)$gig['id'];
        $perfs = $gigPerfMap[$gigId] ?? [];

        if (empty($perfs)) continue;

        // Fetch current Elo for all bits in this gig (pre-gig, frozen for all matchups)
        $bits = [];
        $perfByBitId = [];
        foreach ($perfs as $p) {
            $bitId = (int)$p['bit_id'];
            if (isset($bits[$bitId])) continue;

            $perfByBitId[$bitId] = $p;

            $st = db()->prepare('SELECT id, name, current_elo FROM bits WHERE id = :id');
            $st->execute([':id' => $bitId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;

            $bits[$bitId] = [
                'id'        => $bitId,
                'name'      => (string)$row['name'],
                'pre_elo'   => (float)$row['current_elo'],
                'elo_delta' => 0.0,
            ];
        }

        // Round-robin Elo across all pairs in this gig
        $bitIds = array_keys($bits);
        $n = count($bitIds);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $idA = $bitIds[$i];
                $idB = $bitIds[$j];

                $rA = $bits[$idA]['pre_elo'];
                $rB = $bits[$idB]['pre_elo'];

                $expA = eloExpected($rA, $rB);
                $expB = 1.0 - $expA;

                // Determine winner by PPS
                $ppmA = null;
                $ppmB = null;
                foreach ($perfs as $p) {
                    if ((int)$p['bit_id'] === $idA) $ppmA = (float)$p['calculated_ppm'];
                    if ((int)$p['bit_id'] === $idB) $ppmB = (float)$p['calculated_ppm'];
                }

                if ($ppmA === null || $ppmB === null) continue;

                if ($ppmA > $ppmB) {
                    $sA = 1.0;  $sB = 0.0;
                } elseif ($ppmA < $ppmB) {
                    $sA = 0.0;  $sB = 1.0;
                } else {
                    $sA = 0.5;  $sB = 0.5;
                }

                $dA = ELO_K * ($sA - $expA);
                $dB = ELO_K * ($sB - $expB);

                $bits[$idA]['elo_delta'] += $dA;
                $bits[$idB]['elo_delta'] += $dB;

                if ($summaryGigId !== null && $gigId === $summaryGigId) {
                    $ppmA = isset($perfByBitId[$idA]) ? (float)$perfByBitId[$idA]['calculated_ppm'] : 0.0;
                    $ppmB = isset($perfByBitId[$idB]) ? (float)$perfByBitId[$idB]['calculated_ppm'] : 0.0;
                    $winner = $sA > $sB ? $bits[$idA]['name'] : ($sA < $sB ? $bits[$idB]['name'] : 'Tie');

                    $summary[] = [
                        'nameA'  => $bits[$idA]['name'],
                        'nameB'  => $bits[$idB]['name'],
                        'ppmA'   => $ppmA,
                        'ppmB'   => $ppmB,
                        'winner' => $winner,
                        'deltaA' => $dA,
                        'deltaB' => $dB,
                    ];
                }
            }
        }

        if ($summaryGigId !== null && $gigId === $summaryGigId) {
            foreach ($bits as $bit) {
                $pre = (float)$bit['pre_elo'];
                $change = (float)$bit['elo_delta'];
                $post = round($pre + $change, 1);
                $summaryBitDeltas[] = [
                    'name'   => $bit['name'],
                    'before' => round($pre, 1),
                    'after'  => $post,
                    'delta'  => $change,
                ];
            }

            usort($summaryBitDeltas, function (array $a, array $b): int {
                if ($a['delta'] === $b['delta']) {
                    return strcasecmp($a['name'], $b['name']);
                }
                return $a['delta'] < $b['delta'] ? 1 : -1;
            });
        }

        // Apply all deltas and update times_performed for this gig's bits
        foreach ($bits as $bit) {
            $newElo = round($bit['pre_elo'] + $bit['elo_delta'], 1);
            $st = db()->prepare(
                'UPDATE bits SET current_elo = :elo, times_performed = times_performed + 1 WHERE id = :id'
            );
            $st->execute([':elo' => $newElo, ':id' => $bit['id']]);
        }
    }

    db()->commit();

    return [
        'matches'    => $summary,
        'bit_deltas' => $summaryBitDeltas,
    ];
}

function buildBitBattleHistory(): array
{
    $history = [];

    $bitIds = db()->query('SELECT id FROM bits')->fetchAll(PDO::FETCH_COLUMN);
    $ratings = [];
    foreach ($bitIds as $bid) {
        $ratings[(int)$bid] = (float)ELO_START;
    }

    $gigs = db()->query(
        'SELECT id, gig_date, name
           FROM gigs
          ORDER BY gig_date ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $perfRows = db()->query(
        'SELECT p.gig_id, p.bit_id, p.calculated_ppm, b.name AS bit_name
           FROM performances p
           JOIN bits b ON b.id = p.bit_id
          ORDER BY p.gig_id ASC, p.id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $perfByGig = [];
    foreach ($perfRows as $row) {
        $gid = (int)$row['gig_id'];
        if (!isset($perfByGig[$gid])) {
            $perfByGig[$gid] = [];
        }
        $perfByGig[$gid][] = $row;
    }

    foreach ($gigs as $gig) {
        $gigId = (int)$gig['id'];
        $perfs = $perfByGig[$gigId] ?? [];
        if (count($perfs) < 2) {
            continue;
        }

        $bits = [];
        foreach ($perfs as $p) {
            $bid = (int)$p['bit_id'];
            if (isset($bits[$bid])) {
                continue;
            }
            if (!isset($ratings[$bid])) {
                $ratings[$bid] = (float)ELO_START;
            }

            $bits[$bid] = [
                'id'        => $bid,
                'name'      => (string)$p['bit_name'],
                'ppm'       => (float)$p['calculated_ppm'],
                'pre_elo'   => (float)$ratings[$bid],
                'elo_delta' => 0.0,
            ];
        }

        $bitKeys = array_keys($bits);
        $n = count($bitKeys);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $idA = $bitKeys[$i];
                $idB = $bitKeys[$j];

                $rA = $bits[$idA]['pre_elo'];
                $rB = $bits[$idB]['pre_elo'];
                $expA = eloExpected($rA, $rB);
                $expB = 1.0 - $expA;

                if ($bits[$idA]['ppm'] > $bits[$idB]['ppm']) {
                    $sA = 1.0;
                    $sB = 0.0;
                    $winner = $bits[$idA]['name'];
                } elseif ($bits[$idA]['ppm'] < $bits[$idB]['ppm']) {
                    $sA = 0.0;
                    $sB = 1.0;
                    $winner = $bits[$idB]['name'];
                } else {
                    $sA = 0.5;
                    $sB = 0.5;
                    $winner = 'Tie';
                }

                $dA = ELO_K * ($sA - $expA);
                $dB = ELO_K * ($sB - $expB);

                $bits[$idA]['elo_delta'] += $dA;
                $bits[$idB]['elo_delta'] += $dB;

                $history[$idA][] = [
                    'gig_id'   => $gigId,
                    'gig_date' => (string)$gig['gig_date'],
                    'gig_name' => (string)$gig['name'],
                    'nameA'    => $bits[$idA]['name'],
                    'nameB'    => $bits[$idB]['name'],
                    'ppmA'     => (float)$bits[$idA]['ppm'],
                    'ppmB'     => (float)$bits[$idB]['ppm'],
                    'winner'   => $winner,
                    'deltaA'   => $dA,
                    'deltaB'   => $dB,
                ];

                $history[$idB][] = [
                    'gig_id'   => $gigId,
                    'gig_date' => (string)$gig['gig_date'],
                    'gig_name' => (string)$gig['name'],
                    'nameA'    => $bits[$idB]['name'],
                    'nameB'    => $bits[$idA]['name'],
                    'ppmA'     => (float)$bits[$idB]['ppm'],
                    'ppmB'     => (float)$bits[$idA]['ppm'],
                    'winner'   => $winner === $bits[$idA]['name'] ? $bits[$idA]['name'] : ($winner === $bits[$idB]['name'] ? $bits[$idB]['name'] : 'Tie'),
                    'deltaA'   => $dB,
                    'deltaB'   => $dA,
                ];
            }
        }

        foreach ($bits as $bit) {
            $ratings[$bit['id']] = round($bit['pre_elo'] + $bit['elo_delta'], 1);
        }
    }

    return $history;
}

// ── Request handling ─────────────────────────────────────────────────────────

$flash           = [];   // ['type' => 'success|danger', 'html' => '...']
$matchSummary    = [];   // filled after a successful show log/edit
$bitDeltaSummary = [];   // per-bit net Elo changes for the summary show

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

// ── Edit a gig (with performance data) ───────────────────────────────────
    if ($action === 'edit_gig') {
        $id      = (int)($_POST['gig_id']      ?? 0);
        $gigDate = trim($_POST['gig_date']     ?? '');
        $gigName = trim($_POST['gig_name']     ?? '');
        $gigYt   = trim($_POST['gig_youtube']  ?? '');
        $bitIds    = $_POST['bit_id']    ?? [];
        $durations = $_POST['duration']  ?? [];
        $scores    = $_POST['score']     ?? [];

        if ($id <= 0) {
            $flash = ['type' => 'danger', 'html' => 'Invalid gig ID.'];
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gigDate)
               || !checkdate((int)substr($gigDate,5,2),(int)substr($gigDate,8,2),(int)substr($gigDate,0,4))) {
            $flash = ['type' => 'danger', 'html' => 'Invalid gig date.'];
        } elseif ($gigName === '') {
            $flash = ['type' => 'danger', 'html' => 'Gig name cannot be empty.'];
        } elseif (mb_strlen($gigName) > 200) {
            $flash = ['type' => 'danger', 'html' => 'Gig name is too long (max 200 chars).'];
        } elseif ($gigYt !== '' && !str_starts_with($gigYt, 'https://')) {
            $flash = ['type' => 'danger', 'html' => 'YouTube URL must start with https://.'];
        } else {
            try {
                $n = count($bitIds);
                if ($n < 2) {
                    throw new InvalidArgumentException('Select at least 2 bits per show.');
                }
                if ($n !== count($durations) || $n !== count($scores)) {
                    throw new InvalidArgumentException('Mismatched input arrays — please reload and try again.');
                }

                // Validate each bit row
                $seenIds = [];
                $perfData = [];
                for ($i = 0; $i < $n; $i++) {
                    $bid   = (int)($bitIds[$i] ?? 0);
                    $dur   = (float)($durations[$i] ?? 0);
                    $score = (float)($scores[$i] ?? 0);

                    if ($bid <= 0) {
                        throw new InvalidArgumentException("Row " . ($i + 1) . ": no bit selected.");
                    }
                    if (in_array($bid, $seenIds, true)) {
                        throw new InvalidArgumentException("Duplicate bit on row " . ($i + 1) . ".");
                    }
                    if ($dur <= 0) {
                        throw new InvalidArgumentException("Row " . ($i + 1) . ": duration must be > 0.");
                    }
                    if ($score < 0) {
                        throw new InvalidArgumentException("Row " . ($i + 1) . ": score cannot be negative.");
                    }

                    $seenIds[] = $bid;
                    $ppm = $dur > 0 ? round($score / $dur, 4) : 0.0;
                    $perfData[] = [
                        'bit_id' => $bid,
                        'duration' => $dur,
                        'score' => $score,
                        'ppm' => $ppm,
                    ];
                }

                db()->beginTransaction();

                // Update gig metadata
                $st = db()->prepare(
                    'UPDATE gigs SET gig_date = :d, name = :n, youtube_url = :y WHERE id = :id'
                );
                $st->execute([':d' => $gigDate, ':n' => $gigName, ':y' => $gigYt, ':id' => $id]);

                // Delete and re-insert performances for this gig
                $st = db()->prepare('DELETE FROM performances WHERE gig_id = :gid');
                $st->execute([':gid' => $id]);

                $stInsert = db()->prepare(
                    'INSERT INTO performances (bit_id, gig_id, duration_mins, total_p_line_score, calculated_ppm)
                     VALUES (:bid, :gid, :dur, :sc, :ppm)'
                );
                foreach ($perfData as $p) {
                    $stInsert->execute([
                        ':bid' => $p['bit_id'],
                        ':gid' => $id,
                        ':dur' => $p['duration'],
                        ':sc' => $p['score'],
                        ':ppm' => $p['ppm'],
                    ]);
                }

                db()->commit();

                // Recalculate all ratings from scratch and capture this gig summary
                $summaryData = recalculateAllRatings($id);
                $matchSummary = $summaryData['matches'] ?? [];
                $bitDeltaSummary = $summaryData['bit_deltas'] ?? [];

                $flash = ['type' => 'success', 'html' => 'Gig updated and all ratings recalculated.'];
            } catch (Exception $e) {
                try { db()->rollBack(); } catch (Exception) {}
                $flash = ['type' => 'danger', 'html' => htmlspecialchars($e->getMessage())];
                $matchSummary = [];
                $bitDeltaSummary = [];
            }
        }
    }

    // ── Delete a gig ──────────────────────────────────────────────────────────
    if ($action === 'delete_gig') {
        $id = (int)($_POST['gig_id'] ?? 0);
        if ($id <= 0) {
            $flash = ['type' => 'danger', 'html' => 'Invalid gig ID.'];
        } else {
            try {
                db()->beginTransaction();
                $st = db()->prepare('DELETE FROM performances WHERE gig_id = :id');
                $st->execute([':id' => $id]);
                $st = db()->prepare('DELETE FROM gigs WHERE id = :id');
                $st->execute([':id' => $id]);
                db()->commit();
                $flash = ['type' => 'success', 'html' => 'Gig and all its performance records deleted.'];
            } catch (Exception $e) {
                try { db()->rollBack(); } catch (Exception) {}
                $flash = ['type' => 'danger', 'html' => htmlspecialchars($e->getMessage())];
            }
        }
    }

    // ── Log a show ───────────────────────────────────────────────────────────
    if ($action === 'log_show') {
        try {
            $showDate  = $_POST['show_date']    ?? '';
            $gigName   = trim($_POST['gig_name']    ?? '');
            $gigYt     = trim($_POST['gig_youtube'] ?? '');
            $bitIds    = $_POST['bit_id']       ?? [];
            $durations = $_POST['duration']     ?? [];
            $scores    = $_POST['score']        ?? [];

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

            if ($gigName === '') {
                throw new InvalidArgumentException('Gig name cannot be empty.');
            }
            if (mb_strlen($gigName) > 200) {
                throw new InvalidArgumentException('Gig name is too long (max 200 chars).');
            }
            if ($gigYt !== '' && !str_starts_with($gigYt, 'https://')) {
                throw new InvalidArgumentException('YouTube URL must start with https://.');
            }

            $n = count($bitIds);
            if ($n < 2) {
                throw new InvalidArgumentException('Select at least 2 bits per show.');
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

            // Note: Round-robin Elo computation is now deferred to recalculateAllRatings()
            // which processes this gig along with all others in chronological order.

            // ── Create gig and performances, then recalculate all ratings ────────
            db()->beginTransaction();

            // Create the gig record
            $st = db()->prepare(
                'INSERT INTO gigs (gig_date, name, youtube_url) VALUES (:d, :n, :y)'
            );
            $st->execute([':d' => $showDate, ':n' => $gigName, ':y' => $gigYt]);
            $gigId = (int)db()->lastInsertId();

            // Insert performances for this gig
            foreach ($bits as $bit) {
                $st = db()->prepare(
                    'INSERT INTO performances
                         (bit_id, gig_id, duration_mins, total_p_line_score, calculated_ppm)
                     VALUES (:bid, :gig, :dur, :sc, :ppm)'
                );
                $st->execute([
                    ':bid' => $bit['id'],
                    ':gig' => $gigId,
                    ':dur' => $bit['duration'],
                    ':sc'  => $bit['raw_score'],
                    ':ppm' => $bit['ppm'],
                ]);
            }

            db()->commit();

            // Recalculate all ratings from scratch and capture this gig summary
            $summaryData = recalculateAllRatings($gigId);
            $matchSummary = $summaryData['matches'] ?? [];
            $bitDeltaSummary = $summaryData['bit_deltas'] ?? [];

            $flash = ['type' => 'success',
                      'html' => 'Show logged! Elo ratings recalculated.'];

        } catch (Exception $e) {
            try { db()->rollBack(); } catch (Exception) {}
            $flash        = ['type' => 'danger',    'html' => htmlspecialchars($e->getMessage())];
            $matchSummary = [];
            $bitDeltaSummary = [];
        }
    }
}

// ── Dashboard data ────────────────────────────────────────────────────────────

$allowedSortCols = [
    'name',
    'current_elo',
    'times_performed',
    'last_performed_date',
    'avg_pps',
    'avg_length_secs'
];
$sortCol = in_array($_GET['sort'] ?? '', $allowedSortCols, true) ? $_GET['sort'] : 'current_elo';
$sortDir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

// Safe because $sortCol is whitelisted above
$sql = "
    SELECT
        b.*,
        MAX(g.gig_date)       AS last_performed_date,
        AVG(p.calculated_ppm) AS avg_pps,
        AVG(p.duration_mins)  AS avg_length_secs,
        COUNT(p.id)           AS perf_count
    FROM bits b
    LEFT JOIN performances p ON p.bit_id = b.id
    LEFT JOIN gigs g ON p.gig_id = g.id
    GROUP BY b.id
    ORDER BY $sortCol $sortDir
";
$allBits = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$testedBits   = array_values(array_filter($allBits, fn($b) => (int)$b['perf_count'] > 0));
$untestedBits = array_values(array_filter($allBits, fn($b) => (int)$b['perf_count'] === 0));

// Dropdown list (always alpha order for ergonomics)
$dropdownBits = db()->query('SELECT id, name FROM bits ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);

// Performances grouped by gig, newest date first
$perfRows = db()->query(
    'SELECT g.id AS gig_id, g.gig_date, g.name AS gig_name, g.youtube_url,
            p.id AS perf_id, p.bit_id, p.duration_mins, p.total_p_line_score, p.calculated_ppm,
            b.name AS bit_name
       FROM gigs g
       JOIN performances p ON p.gig_id = g.id
       JOIN bits b ON p.bit_id = b.id
      ORDER BY g.gig_date DESC, g.id DESC, p.id ASC'
)->fetchAll(PDO::FETCH_ASSOC);

$gigGroups = [];
foreach ($perfRows as $row) {
    $gid = $row['gig_id'];
    if (!isset($gigGroups[$gid])) {
        $gigGroups[$gid] = [
            'id'          => $gid,
            'gig_date'    => $row['gig_date'],
            'gig_name'    => $row['gig_name'],
            'youtube_url' => (string)($row['youtube_url'] ?? ''),
            'perfs'       => [],
        ];
    }
    $gigGroups[$gid]['perfs'][] = $row;
}

$bitBattleHistory = buildBitBattleHistory();

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
        .btn-chart  { opacity: .55; transition: opacity .15s; line-height: 1; text-decoration: none !important; }
        .btn-chart:hover { opacity: 1; }
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

                <?php if (!empty($bitDeltaSummary)): ?>
                    <div class="border-top p-3 bg-light-subtle">
                        <div class="fw-semibold mb-2">Overall Elo Change By Bit</div>
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Bit</th>
                                    <th class="text-end">Before</th>
                                    <th class="text-end">After</th>
                                    <th class="text-end">Net Δ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bitDeltaSummary as $d): ?>
                                    <tr>
                                        <td><?= h($d['name']) ?></td>
                                        <td class="text-end"><?= number_format((float)$d['before'], 1) ?></td>
                                        <td class="text-end"><?= number_format((float)$d['after'], 1) ?></td>
                                        <td class="text-end <?= (float)$d['delta'] >= 0 ? 'delta-pos' : 'delta-neg' ?>">
                                            <?= ((float)$d['delta'] >= 0 ? '+' : '') . number_format((float)$d['delta'], 1) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── Leaderboard: Tested Bits ── -->
    <div class="card mb-4 shadow-sm">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center"
             id="testedBitsHeader" data-toggle-target="testedBitsBody" style="cursor:pointer; user-select:none;">
            <h5 class="mb-0">&#127942; Tested Bits</h5>
            <small class="text-white-50"><?= count($testedBits) ?> tested / <?= count($allBits) ?> total</small>
        </div>
        <div class="card-body p-0" id="testedBitsBody">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:3rem">#</th>
                            <th style="width:4rem"></th>
                            <th><?= sortLink('name',                'Bit Name',           $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('current_elo',         'Elo Rating',         $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('times_performed',     'Performances',       $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('last_performed_date', 'Last Performed',     $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('avg_pps',             'Avg PPS',            $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('avg_length_secs',     'Avg Secs',           $sortCol, $sortDir) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($testedBits)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    No tested bits yet — log at least one show.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($testedBits as $rank => $bit): ?>
                            <?php
                                $avgPps  = $bit['avg_pps'] !== null ? number_format((float)$bit['avg_pps'], 2) : '—';
                                $avgLen  = $bit['avg_length_secs'] !== null ? (string)((int)(ceil(((float)$bit['avg_length_secs']) / 5) * 5)) : '—';
                                $elo     = (float)$bit['current_elo'];
                                $eloBg   = $elo >= 1100 ? 'bg-success' : ($elo >= 1000 ? 'bg-primary' : 'bg-secondary');
                            ?>
                            <tr>
                                <td class="text-muted"><?= $rank + 1 ?></td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">
                                        <button type="button"
                                                class="btn btn-sm btn-link btn-edit p-0"
                                                title="Edit bit"
                                                onclick='openEditModal(<?= (int)$bit['id'] ?>, <?= json_encode($bit['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                        >&#9999;&#65039;</button>
                                        <button type="button"
                                                class="btn btn-sm btn-link btn-chart p-0"
                                                title="View bit battles"
                                                onclick='openBitBattlesModal(<?= (int)$bit['id'] ?>, <?= json_encode($bit['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                        >&#128202;</button>
                                    </div>
                                </td>
                                <td class="fw-semibold"><?= h($bit['name']) ?></td>
                                <td>
                                    <span class="badge <?= $eloBg ?> elo-badge fs-6">
                                        <?= number_format($elo, 1) ?>
                                    </span>
                                </td>
                                <td><?= (int)$bit['times_performed'] ?></td>
                                <td><?= $bit['last_performed_date'] ? h($bit['last_performed_date']) : '<span class="text-muted">—</span>' ?></td>
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
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center"
             id="untestedBitsHeader" data-toggle-target="untestedBitsBody" style="cursor:pointer; user-select:none;">
            <h5 class="mb-0">🧪 Untested Bits</h5>
            <small class="text-white-50"><?= count($untestedBits) ?> untested</small>
        </div>
        <div class="card-body p-0" id="untestedBitsBody">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:3rem">#</th>
                            <th style="width:4rem"></th>
                            <th><?= sortLink('name',                'Bit Name',           $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('current_elo',         'Elo Rating',         $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('times_performed',     'Performances',       $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('last_performed_date', 'Last Performed',     $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('avg_pps',             'Avg PPS',            $sortCol, $sortDir) ?></th>
                            <th><?= sortLink('avg_length_secs',     'Avg Secs',           $sortCol, $sortDir) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($untestedBits)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    No untested bits.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($untestedBits as $rank => $bit): ?>
                            <?php
                                $avgPps  = $bit['avg_pps'] !== null ? number_format((float)$bit['avg_pps'], 2) : '—';
                                $avgLen  = $bit['avg_length_secs'] !== null ? (string)((int)(ceil(((float)$bit['avg_length_secs']) / 5) * 5)) : '—';
                                $elo     = (float)$bit['current_elo'];
                                $eloBg   = $elo >= 1100 ? 'bg-success' : ($elo >= 1000 ? 'bg-primary' : 'bg-secondary');
                            ?>
                            <tr>
                                <td class="text-muted"><?= $rank + 1 ?></td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">
                                        <button type="button"
                                                class="btn btn-sm btn-link btn-edit p-0"
                                                title="Edit bit"
                                                onclick='openEditModal(<?= (int)$bit['id'] ?>, <?= json_encode($bit['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                        >&#9999;&#65039;</button>
                                        <button type="button"
                                                class="btn btn-sm btn-link btn-chart p-0"
                                                title="View bit battles"
                                                onclick='openBitBattlesModal(<?= (int)$bit['id'] ?>, <?= json_encode($bit['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                        >&#128202;</button>
                                    </div>
                                </td>
                                <td class="fw-semibold"><?= h($bit['name']) ?></td>
                                <td>
                                    <span class="badge <?= $eloBg ?> elo-badge fs-6">
                                        <?= number_format($elo, 1) ?>
                                    </span>
                                </td>
                                <td><?= (int)$bit['times_performed'] ?></td>
                                <td><?= $bit['last_performed_date'] ? h($bit['last_performed_date']) : '<span class="text-muted">—</span>' ?></td>
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

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Gig Name</label>
                            <input type="text" name="gig_name" class="form-control"
                                   placeholder="e.g. The Compass, Open Mic"
                                   maxlength="200" required autocomplete="off">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                YouTube Recording
                                <span class="fw-normal text-muted">(optional)</span>
                            </label>
                            <input type="url" name="gig_youtube" class="form-control"
                                   placeholder="https://youtu.be/..."
                                   maxlength="500" autocomplete="off">
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

    <!-- ── Performances by Gig ── -->
    <div class="card mt-4 shadow-sm">
        <div class="card-header"><h5 class="mb-0">📈 Performances by Gig</h5></div>
        <div class="card-body p-0">
            <?php if (empty($gigGroups)): ?>
                <p class="text-center text-muted py-4 mb-0">No performances yet — log a show above.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-secondary">
                        <tr>
                            <th>Bit</th>
                            <th class="text-end">Duration (secs)</th>
                            <th class="text-end">Score</th>
                            <th class="text-end">PPS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gigGroups as $gig): ?>
                            <?php $perfCount = count($gig['perfs']); ?>
                            <?php $perfsJson = htmlspecialchars(json_encode($gig['perfs']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            <tr class="table-dark">
                                <td colspan="4" class="py-2">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <button type="button"
                                                class="btn btn-sm btn-link btn-edit p-0 text-white text-decoration-none"
                                                title="Edit gig"
                                                data-gig-id="<?= (int)$gig['id'] ?>"
                                                data-gig-date="<?= htmlspecialchars($gig['gig_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                data-gig-name="<?= htmlspecialchars($gig['gig_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                data-gig-youtube="<?= htmlspecialchars($gig['youtube_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                data-gig-perfs="<?= $perfsJson ?>"
                                                onclick="openEditGigModalFromButton(this)"
                                        >&#9999;&#65039;</button>
                                        <strong><?= h($gig['gig_date']) ?></strong>
                                        <span class="text-white-50">&mdash;</span>
                                        <span><?= h($gig['gig_name']) ?></span>
                                        <?php if ($gig['youtube_url'] !== '' && str_starts_with($gig['youtube_url'], 'https://')): ?>
                                            <a href="<?= h($gig['youtube_url']) ?>"
                                               target="_blank" rel="noopener noreferrer"
                                               class="text-danger text-decoration-none" title="Watch recording">&#9654;&#65039;</a>
                                        <?php endif; ?>
                                        <span class="badge bg-secondary"><?= $perfCount ?> bit<?= $perfCount !== 1 ? 's' : '' ?> compared</span>
                                    </div>
                                </td>
                            </tr>
                            <?php foreach ($gig['perfs'] as $perf): ?>
                            <tr>
                                <td><?= h($perf['bit_name']) ?></td>
                                <td class="text-end"><?= number_format((float)$perf['duration_mins'], 1) ?></td>
                                <td class="text-end"><?= number_format((float)$perf['total_p_line_score'], 1) ?></td>
                                <td class="text-end fw-bold"><?= number_format((float)$perf['calculated_ppm'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
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

<!-- ── Edit Gig Modal ───────────────────────────────────────────────────────── -->
<div class="modal fade" id="editGigModal" tabindex="-1" aria-labelledby="editGigModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_gig">
                <input type="hidden" name="gig_id" id="editGigId">
                <input type="hidden" id="editGigPerfCount">
                <div class="modal-header">
                    <h5 class="modal-title" id="editGigModalLabel">&#9999;&#65039; Edit Gig</h5>
                    <button type="button" class="btn-close" id="closeEditGigModalBtn" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="editGigDate">Date</label>
                        <input type="date" id="editGigDate" name="gig_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="editGigName">Gig Name</label>
                        <input type="text" id="editGigName" name="gig_name"
                               class="form-control" maxlength="200" required autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="editGigYoutube">
                            YouTube Recording
                            <span class="fw-normal text-muted">(optional)</span>
                        </label>
                        <input type="url" id="editGigYoutube" name="gig_youtube"
                               class="form-control" maxlength="500"
                               placeholder="https://youtu.be/...">
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="fw-semibold small">Performances</label>
                        <!-- Column headers for bit rows -->
                        <div class="row g-2 mb-1 text-muted small">
                            <div class="col-5">Bit</div>
                            <div class="col-3">Duration (secs)</div>
                            <div class="col-4">P-Line Score</div>
                        </div>

                        <div id="editGigBitRows"></div>

                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    onclick="addEditGigBitRow()">+ Add Bit</button>
                            <button type="button" class="btn btn-outline-danger btn-sm"
                                    onclick="removeEditGigBitRow()">− Remove Last</button>
                            <span class="ms-auto text-muted small align-self-center" id="editGigRowCount"></span>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 small">
                        <strong>PPS</strong> = Score ÷ Duration (seconds).
                        Ratings will be recalculated when you save.
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-danger btn-sm" id="openDeleteGigFromEditBtn">
                        Delete this gig&hellip;
                    </button>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" id="cancelEditGigBtn">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Delete Gig Confirmation Modal ───────────────────────────────────────── -->
<div class="modal fade" id="deleteGigModal" tabindex="-1" aria-labelledby="deleteGigModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteGigModalLabel">&#128465;&#65039; Delete Gig</h5>
                <button type="button" class="btn-close" id="closeDeleteGigModalBtn" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger mb-0">
                    <strong>Delete &ldquo;<span id="deleteGigName"></span>&rdquo;?</strong><br>
                    This will permanently remove this gig and all
                    <strong><span id="deleteGigPerfCount"></span> performance record(s)</strong> within it.<br>
                    <em>Elo ratings will not be recalculated.</em>
                </div>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_gig">
                <input type="hidden" name="gig_id" id="deleteGigId">
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelDeleteGigBtn">Cancel</button>
                    <button type="submit" class="btn btn-danger">Yes, delete permanently</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Bit Battles Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="bitBattlesModal" tabindex="-1" aria-labelledby="bitBattlesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bitBattlesModalLabel">&#128202; Bit Battles</h5>
                <button type="button" class="btn-close" id="closeBitBattlesModalBtn" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
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
                    <tbody id="bitBattlesBody"></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelBitBattlesBtn">Close</button>
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

const BIT_BATTLE_HISTORY = <?= json_encode(
    $bitBattleHistory,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;

const MIN_ROWS = 2;

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

function initBitTableCollapsibles() {
    const headers = document.querySelectorAll('[data-toggle-target]');
    headers.forEach((header) => {
        header.addEventListener('click', () => {
            const targetId = header.getAttribute('data-toggle-target');
            if (!targetId) return;
            const target = document.getElementById(targetId);
            if (!target) return;
            target.style.display = target.style.display === 'none' ? '' : 'none';
        });
    });
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
    initBitTableCollapsibles();
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

// ── Edit Gig Modal ────────────────────────────────────────────────────────────

const editGigModalEl   = document.getElementById('editGigModal');
const deleteGigModalEl = document.getElementById('deleteGigModal');
const bitBattlesModalEl = document.getElementById('bitBattlesModal');
let bsEditGigModal    = null;
let bsDeleteGigModal  = null;
let bsBitBattlesModal = null;

function showEditGigModal() {
    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
        if (!bsEditGigModal) bsEditGigModal = new window.bootstrap.Modal(editGigModalEl);
        bsEditGigModal.show();
        return;
    }
    editGigModalEl.style.display = 'block';
    editGigModalEl.classList.add('show');
    editGigModalEl.removeAttribute('aria-hidden');
    document.body.classList.add('modal-open');
    if (!modalBackdrop) {
        modalBackdrop = document.createElement('div');
        modalBackdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(modalBackdrop);
    }
}

function hideEditGigModal() {
    if (bsEditGigModal) { bsEditGigModal.hide(); return; }
    editGigModalEl.classList.remove('show');
    editGigModalEl.style.display = 'none';
    editGigModalEl.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    if (modalBackdrop) { modalBackdrop.remove(); modalBackdrop = null; }
}

function showDeleteGigModal() {
    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
        if (!bsDeleteGigModal) bsDeleteGigModal = new window.bootstrap.Modal(deleteGigModalEl);
        bsDeleteGigModal.show();
        return;
    }
    deleteGigModalEl.style.display = 'block';
    deleteGigModalEl.classList.add('show');
    deleteGigModalEl.removeAttribute('aria-hidden');
    document.body.classList.add('modal-open');
    if (!modalBackdrop) {
        modalBackdrop = document.createElement('div');
        modalBackdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(modalBackdrop);
    }
}

function hideDeleteGigModal() {
    if (bsDeleteGigModal) { bsDeleteGigModal.hide(); return; }
    deleteGigModalEl.classList.remove('show');
    deleteGigModalEl.style.display = 'none';
    deleteGigModalEl.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    if (modalBackdrop) { modalBackdrop.remove(); modalBackdrop = null; }
}

function showBitBattlesModal() {
    if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
        if (!bsBitBattlesModal) bsBitBattlesModal = new window.bootstrap.Modal(bitBattlesModalEl);
        bsBitBattlesModal.show();
        return;
    }
    bitBattlesModalEl.style.display = 'block';
    bitBattlesModalEl.classList.add('show');
    bitBattlesModalEl.removeAttribute('aria-hidden');
    document.body.classList.add('modal-open');
    if (!modalBackdrop) {
        modalBackdrop = document.createElement('div');
        modalBackdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(modalBackdrop);
    }
}

function hideBitBattlesModal() {
    if (bsBitBattlesModal) { bsBitBattlesModal.hide(); return; }
    bitBattlesModalEl.classList.remove('show');
    bitBattlesModalEl.style.display = 'none';
    bitBattlesModalEl.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    if (modalBackdrop) { modalBackdrop.remove(); modalBackdrop = null; }
}

function formatDelta(v) {
    const n = Number(v) || 0;
    return `${n >= 0 ? '+' : ''}${n.toFixed(1)}`;
}

function openBitBattlesModal(bitId, bitName) {
    const titleEl = document.getElementById('bitBattlesModalLabel');
    const bodyEl = document.getElementById('bitBattlesBody');
    titleEl.textContent = `Bit Battles - ${bitName}`;

    const rows = BIT_BATTLE_HISTORY[String(bitId)] || BIT_BATTLE_HISTORY[bitId] || [];
    if (rows.length === 0) {
        bodyEl.innerHTML = `
            <tr>
                <td colspan="8" class="text-center text-muted py-4">No battles found for this bit yet.</td>
            </tr>`;
        showBitBattlesModal();
        return;
    }

    const byGigCount = {};
    const byGigDelta = {};
    for (const r of rows) {
        const key = String(r.gig_id);
        byGigCount[key] = (byGigCount[key] || 0) + 1;
        byGigDelta[key] = (byGigDelta[key] || 0) + (Number(r.deltaA) || 0);
    }

    let html = '';
    let currentGigId = null;
    for (const m of rows) {
        const gigId = String(m.gig_id);
        if (gigId !== currentGigId) {
            const matchCount = byGigCount[gigId] || 0;
            const netDelta = byGigDelta[gigId] || 0;
            const netDeltaClass = netDelta >= 0 ? 'delta-pos' : 'delta-neg';
            html += `
                <tr class="table-dark">
                    <td colspan="8" class="py-2">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <strong>${escHtml(String(m.gig_date || ''))}</strong>
                            <span class="text-white-50">&mdash;</span>
                            <span>${escHtml(String(m.gig_name || ''))}</span>
                            <span class="badge bg-secondary">${matchCount} match-up${matchCount !== 1 ? 's' : ''}</span>
                            <span class="badge bg-light ${netDeltaClass}">Net ${formatDelta(netDelta)}</span>
                        </div>
                    </td>
                </tr>`;
            currentGigId = gigId;
        }

        const clA = m.winner === m.nameA ? 'match-win' : (m.winner === 'Tie' ? 'match-tie' : 'match-loss');
        const clB = m.winner === m.nameB ? 'match-win' : (m.winner === 'Tie' ? 'match-tie' : 'match-loss');

        html += `
            <tr>
                <td class="${clA}">${escHtml(String(m.nameA))}</td>
                <td class="text-end ${clA}">${(Number(m.ppmA) || 0).toFixed(2)}</td>
                <td class="text-center text-muted">vs</td>
                <td class="${clB}">${escHtml(String(m.nameB))}</td>
                <td class="text-end ${clB}">${(Number(m.ppmB) || 0).toFixed(2)}</td>
                <td><strong>${escHtml(String(m.winner))}</strong></td>
                <td class="text-end ${Number(m.deltaA) >= 0 ? 'delta-pos' : 'delta-neg'}">${formatDelta(m.deltaA)}</td>
                <td class="text-end ${Number(m.deltaB) >= 0 ? 'delta-pos' : 'delta-neg'}">${formatDelta(m.deltaB)}</td>
            </tr>`;
    }

    bodyEl.innerHTML = html;
    showBitBattlesModal();
}

function openEditGigModalFromButton(button) {
    const id       = button.getAttribute('data-gig-id');
    const date     = button.getAttribute('data-gig-date');
    const name     = button.getAttribute('data-gig-name');
    const youtube  = button.getAttribute('data-gig-youtube');
    const perfsStr = button.getAttribute('data-gig-perfs');

    openEditGigModal(id, date, name, youtube, perfsStr);
}

function openEditGigModal(id, date, name, youtube, perfsStr) {
    document.getElementById('editGigId').value      = id;
    document.getElementById('editGigDate').value    = date;
    document.getElementById('editGigName').value    = name;
    document.getElementById('editGigYoutube').value = youtube;

    // Clear existing bit rows
    const container = document.getElementById('editGigBitRows');
    container.innerHTML = '';

    // Populate from existing performances
    let perfs = [];
    try {
        if (perfsStr) {
            perfs = JSON.parse(perfsStr);
            console.log('Parsed performances:', perfs);
        }
    } catch (e) {
        console.error('Failed to parse performances JSON:', e);
    }

    if (perfs.length === 0) {
        // No perfs, add 2 blank rows
        addEditGigBitRow();
        addEditGigBitRow();
    } else {
        // Add a row for each existing performance
        for (const p of perfs) {
            console.log('Adding row for:', p);
            addEditGigBitRow(Number(p.bit_id), Number(p.duration_mins), Number(p.total_p_line_score));
        }
    }

    showEditGigModal();
    setTimeout(() => document.getElementById('editGigName').select(), 50);
}

function addEditGigBitRow(selectedBitId = 0, duration = 0, score = 0) {
    const container = document.getElementById('editGigBitRows');

    const div = document.createElement('div');
    div.className = 'row g-2 mb-2 edit-gig-bit-row';
    div.innerHTML = `
        <div class="col-5">
            <select name="bit_id[]" class="form-select" required>
                ${buildOptions(selectedBitId)}
            </select>
        </div>
        <div class="col-3">
            <input type="number" name="duration[]" class="form-control"
                   placeholder="e.g. 80" step="1" min="1" max="3600" value="${duration}" required>
        </div>
        <div class="col-4">
            <input type="number" name="score[]" class="form-control"
                   placeholder="e.g. 28" step="0.1" min="0" value="${score}" required>
        </div>`;
    container.appendChild(div);
    updateEditGigRowCount();
}

function removeEditGigBitRow() {
    const container = document.getElementById('editGigBitRows');
    const rows      = container.querySelectorAll('.edit-gig-bit-row');
    if (rows.length <= 2) { alert('Minimum 2 bits required for a comparison.'); return; }
    rows[rows.length - 1].remove();
    updateEditGigRowCount();
}

function updateEditGigRowCount() {
    const n = document.getElementById('editGigBitRows').querySelectorAll('.edit-gig-bit-row').length;
    const matches = n * (n - 1) / 2;
    document.getElementById('editGigRowCount').textContent =
        `${n} bits → ${matches} match-up${matches !== 1 ? 's' : ''}`;
}

// Validate no duplicate bits on edit gig submit
document.addEventListener('DOMContentLoaded', () => {
    const editGigForm = document.querySelector('#editGigModal form');
    if (editGigForm) {
        editGigForm.addEventListener('submit', function (e) {
            const selects = [...this.querySelectorAll('select[name="bit_id[]"]')];
            const ids     = selects.map(s => s.value).filter(v => v !== '');
            if (new Set(ids).size !== ids.length) {
                e.preventDefault();
                alert('You have selected the same bit more than once. Each bit must be unique per gig.');
            }
        });
    }
});

function openDeleteGigModal(id, name, perfCount) {
    document.getElementById('deleteGigId').value              = id;
    document.getElementById('deleteGigName').textContent      = name;
    document.getElementById('deleteGigPerfCount').textContent = perfCount;
    showDeleteGigModal();
}

document.getElementById('cancelEditGigBtn').addEventListener('click',    hideEditGigModal);
document.getElementById('closeEditGigModalBtn').addEventListener('click', hideEditGigModal);
document.getElementById('cancelDeleteGigBtn').addEventListener('click',    hideDeleteGigModal);
document.getElementById('closeDeleteGigModalBtn').addEventListener('click', hideDeleteGigModal);
document.getElementById('cancelBitBattlesBtn').addEventListener('click', hideBitBattlesModal);
document.getElementById('closeBitBattlesModalBtn').addEventListener('click', hideBitBattlesModal);
document.getElementById('openDeleteGigFromEditBtn').addEventListener('click', () => {
    const id = Number(document.getElementById('editGigId').value || 0);
    const name = document.getElementById('editGigName').value || '(unnamed)';
    const perfCount = Number(document.getElementById('editGigPerfCount').value || 0);
    hideEditGigModal();
    openDeleteGigModal(id, name, perfCount);
});

editGigModalEl.addEventListener('click',   (e) => { if (e.target === editGigModalEl)   hideEditGigModal(); });
deleteGigModalEl.addEventListener('click', (e) => { if (e.target === deleteGigModalEl) hideDeleteGigModal(); });
bitBattlesModalEl.addEventListener('click', (e) => { if (e.target === bitBattlesModalEl) hideBitBattlesModal(); });

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (editGigModalEl.classList.contains('show'))   hideEditGigModal();
    if (deleteGigModalEl.classList.contains('show')) hideDeleteGigModal();
    if (bitBattlesModalEl.classList.contains('show')) hideBitBattlesModal();
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmHr8dHMLTCEiAqmBxnz0G5vGvLX"
        crossorigin="anonymous"></script>
</body>
</html>
