<?php
/** api/roster.php?do=sections|roster|myWork|student|search */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$me = require_login();
$isMain = $me['role'] === 'main';
$uid = (int)$me['id'];
$do = $_GET['do'] ?? 'sections';

/** One student's standing in a lab: grade, pending request, responsible assistant. */
function student_state(array $st, int $labId, int $uid, bool $isMain): array
{
    $assigned = student_assigned_ta($labId, $st['id'], $st['section_id']);

    $m = one('SELECT * FROM marks WHERE student_id = ? AND lab_id = ?', [$st['id'], $labId]);
    $r = one('SELECT * FROM change_requests
              WHERE student_id = ? AND lab_id = ? AND state = "pending"
              ORDER BY id DESC LIMIT 1', [$st['id'], $labId]);

    $names = user_names([$assigned, $m['ta_id'] ?? null, $r['ta_id'] ?? null]);

    $entry = null;
    if ($m) {
        $entry = mark_row($m, $names);
        $entry['byMe'] = (int)$m['ta_id'] === $uid;
    }

    $pending = null;
    if ($r) {
        $pending = [
            'id'     => (int)$r['id'],
            'grade'  => (int)$r['grade'],
            'reason' => $r['reason'],
            'taId'   => (int)$r['ta_id'],
            'taName' => $names[(int)$r['ta_id']] ?? '—',
            'at'     => ms($r['created_at']),
        ];
    }

    return [
        'id'             => $st['id'],
        'name'           => $st['name'],
        'email'          => $st['email'],
        'sectionId'      => $st['section_id'],
        'assignedTaId'   => $assigned,
        'assignedTaName' => $assigned ? ($names[$assigned] ?? '—') : '—',
        'mine'           => $isMain || ($assigned === $uid),
        'entry'          => $entry,
        'pending'        => $pending,
    ];
}

switch ($do) {

    /* ---------------- sections ---------------- */
    case 'sections': {
        $rows = all('SELECT id, name, day, time, room FROM sections ORDER BY sort, id');
        $tas  = all('SELECT section_id, ta_id FROM section_tas ORDER BY sort, ta_id');
        $by = [];
        foreach ($tas as $t) $by[$t['section_id']][] = (int)$t['ta_id'];
        foreach ($rows as &$s) $s['taIds'] = $by[$s['id']] ?? [];
        ok(['sections' => $rows]);
    }

    /* ---------------- a full section roster ---------------- */
    case 'roster': {
        $sectionId = want('sectionId');
        $labId     = (int)want('labId');

        $assign = section_assignment($labId, $sectionId);
        $roster = all('SELECT id, name, email, section_id FROM students
                       WHERE section_id = ? ORDER BY sort, id', [$sectionId]);
        if (!$roster) ok(['roster' => []]);

        $ids = array_column($roster, 'id');
        $ph  = in_list(count($ids));

        $marks = [];
        foreach (all("SELECT * FROM marks WHERE lab_id = ? AND student_id IN ($ph)",
                     array_merge([$labId], $ids)) as $m) {
            $marks[$m['student_id']] = $m;
        }

        $pend = [];
        foreach (all("SELECT * FROM change_requests
                      WHERE lab_id = ? AND state = 'pending' AND student_id IN ($ph)",
                     array_merge([$labId], $ids)) as $r) {
            $pend[$r['student_id']] = $r;
        }

        $names = user_names(array_merge(
            array_values($assign),
            array_column($marks, 'ta_id'),
            array_column($pend, 'ta_id')
        ));

        $out = [];
        foreach ($roster as $st) {
            $a = $assign[$st['id']] ?? null;
            $m = $marks[$st['id']] ?? null;
            $r = $pend[$st['id']] ?? null;

            $entry = null;
            if ($m) {
                $entry = mark_row($m, $names);
                $entry['byMe'] = (int)$m['ta_id'] === $uid;
            }

            $out[] = [
                'id'             => $st['id'],
                'name'           => $st['name'],
                'email'          => $st['email'],
                'sectionId'      => $st['section_id'],
                'assignedTaId'   => $a,
                'assignedTaName' => $a ? ($names[$a] ?? '—') : '—',
                'mine'           => $isMain || ($a === $uid),
                'entry'          => $entry,
                'pending'        => $r ? [
                    'id'     => (int)$r['id'],
                    'grade'  => (int)$r['grade'],
                    'reason' => $r['reason'],
                    'taId'   => (int)$r['ta_id'],
                    'taName' => $names[(int)$r['ta_id']] ?? '—',
                    'at'     => ms($r['created_at']),
                ] : null,
            ];
        }
        ok(['roster' => $out]);
    }

    /* ---------------- an assistant's workload in a lab ---------------- */
    case 'myWork': {
        $labId = (int)want('labId');

        $sections = $isMain
            ? all('SELECT id, name, day, time, room FROM sections ORDER BY sort, id')
            : all('SELECT s.id, s.name, s.day, s.time, s.room FROM sections s
                   JOIN section_tas t ON t.section_id = s.id
                   WHERE t.ta_id = ? ORDER BY s.sort, s.id', [$uid]);

        $out = [];
        foreach ($sections as $sec) {
            $assign = section_assignment($labId, $sec['id']);
            $roster = array_column(
                all('SELECT id FROM students WHERE section_id = ?', [$sec['id']]), 'id');

            $done = [];
            if ($roster) {
                $ph = in_list(count($roster));
                $done = array_column(
                    all("SELECT student_id FROM marks WHERE lab_id = ? AND student_id IN ($ph)",
                        array_merge([$labId], $roster)), 'student_id');
            }
            $done = array_flip($done);

            $mine = $isMain ? $roster : array_values(array_filter(
                $roster, fn($id) => ($assign[$id] ?? null) === $uid));

            $out[] = [
                'section'   => $sec,
                'total'     => count($roster),
                'done'      => count(array_intersect_key($done, array_flip($roster))),
                'mineTotal' => count($mine),
                'mineDone'  => count(array_intersect_key($done, array_flip($mine))),
            ];
        }
        ok(['work' => $out]);
    }

    /* ---------------- a single student ---------------- */
    case 'student': {
        $labId = (int)want('labId');
        $st = one('SELECT id, name, email, section_id FROM students WHERE id = ?', [want('studentId')]);
        if (!$st) ok(['student' => null]);

        $data = student_state($st, $labId, $uid, $isMain);
        $data['section'] = one('SELECT id, name, day, time, room FROM sections WHERE id = ?',
                               [$st['section_id']]);
        ok(['student' => $data]);
    }

    /* ---------------- live search ---------------- */
    case 'search': {
        $raw   = trim((string)inp('q', ''));
        $labId = (int)inp('labId', 0);
        $sec   = (string)inp('sectionId', '');
        $limit = max(1, min(20, (int)inp('limit', 8)));

        if (mb_strlen($raw) < 2) ok(['query' => $raw, 'items' => [], 'more' => 0]);

        $qn  = ar_norm($raw);
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $raw) . '%';
        $liken = '%' . str_replace(['%', '_'], ['\%', '\_'], $qn) . '%';

        $rows = all('SELECT id, name, email, section_id FROM students
                     WHERE id LIKE ? OR name_norm LIKE ? OR email LIKE ?
                     LIMIT 80', [$like, $liken, $like]);

        // Ranking: current section first, then ID, name, email.
        $weights = ['id' => 0, 'name' => 8, 'email' => 16];
        $scored = [];
        foreach ($rows as $r) {
            $best = null;
            foreach (['id' => $r['id'], 'name' => ar_norm($r['name']), 'email' => mb_strtolower($r['email'])] as $f => $hay) {
                $needle = ($f === 'id') ? $raw : $qn;
                $at = mb_strpos($hay, $needle);
                if ($at === false) continue;
                $rank = ($r['section_id'] === $sec ? 0 : 100)
                      + $weights[$f]
                      + ($at === 0 ? 0 : 4)
                      + min($at, 20) / 100;
                if ($best === null || $rank < $best['rank']) {
                    $best = ['field' => $f, 'at' => $at, 'len' => mb_strlen($needle), 'rank' => $rank];
                }
            }
            if ($best) $scored[] = ['row' => $r, 'm' => $best];
        }

        usort($scored, fn($a, $b) => $a['m']['rank'] <=> $b['m']['rank'] ?: strcmp($a['row']['id'], $b['row']['id']));
        $more  = max(0, count($scored) - $limit);
        $slice = array_slice($scored, 0, $limit);

        $secIds = array_unique(array_column(array_column($slice, 'row'), 'section_id'));
        $secs = [];
        if ($secIds) {
            foreach (all('SELECT id, name, day, time, room FROM sections
                          WHERE id IN (' . in_list(count($secIds)) . ')', array_values($secIds)) as $s) {
                $secs[$s['id']] = $s;
            }
        }

        $items = [];
        foreach ($slice as $x) {
            $r = $x['row'];
            $existing = null;
            if ($labId) {
                $m = one('SELECT * FROM marks WHERE student_id = ? AND lab_id = ?', [$r['id'], $labId]);
                if ($m) {
                    $existing = mark_row($m, user_names([$m['ta_id']]));
                    $existing['byMe'] = (int)$m['ta_id'] === $uid;
                }
            }
            $items[] = [
                'id'          => $r['id'],
                'name'        => $r['name'],
                'email'       => $r['email'],
                'sectionId'   => $r['section_id'],
                'section'     => $secs[$r['section_id']] ?? null,
                'sameSection' => $r['section_id'] === $sec,
                'match'       => ['field' => $x['m']['field'], 'at' => $x['m']['at'], 'len' => $x['m']['len']],
                'existing'    => $existing,
            ];
        }

        ok(['query' => $raw, 'items' => $items, 'more' => $more]);
    }
}

fail(L('err.unknownAction'), 404);
