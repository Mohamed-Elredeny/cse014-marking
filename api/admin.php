<?php
/** api/admin.php?do=taOverview|labStats|matrix|overrides|activity|distribution|audit|reassign|clearReassign */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$me = require_main();
$uid = (int)$me['id'];
$do = $_GET['do'] ?? 'taOverview';

/**
 * Each assistant's standing in a lab, without an N+1 query.
 *   done    = all their sections complete (even if a colleague finished them)
 *   pending = they have entered nothing and their sections are incomplete
 *   working = started but not finished
 */
function ta_rows(int $labId): array
{
    $tas = all('SELECT id, name FROM users WHERE role = "ta" AND active = 1 ORDER BY id');
    if (!$tas) return [];

    $sections = array_column(all('SELECT id, name FROM sections ORDER BY sort, id'), null, 'id');

    $bySec = [];
    foreach (all('SELECT section_id, ta_id FROM section_tas') as $r) {
        $bySec[(int)$r['ta_id']][] = $r['section_id'];
    }

    $totals = array_column(
        all('SELECT section_id, COUNT(*) n FROM students GROUP BY section_id'), 'n', 'section_id');
    $done = array_column(
        all('SELECT section_id, COUNT(*) n FROM marks WHERE lab_id = ? GROUP BY section_id', [$labId]),
        'n', 'section_id');

    $own = [];
    foreach (all('SELECT ta_id, COUNT(*) n, MAX(updated_at) last
                  FROM marks WHERE lab_id = ? GROUP BY ta_id', [$labId]) as $r) {
        $own[(int)$r['ta_id']] = ['n' => (int)$r['n'], 'last' => $r['last']];
    }

    $rows = [];
    foreach ($tas as $ta) {
        $id = (int)$ta['id'];
        $secs = [];
        $tot = $sum = 0;
        $complete = !empty($bySec[$id]);

        foreach ($bySec[$id] ?? [] as $sid) {
            $t = (int)($totals[$sid] ?? 0);
            $d = (int)($done[$sid] ?? 0);
            $pct = $t ? (int)round($d / $t * 100) : 0;
            if ($pct < 100) $complete = false;
            $tot += $t; $sum += $d;
            $secs[] = ['section' => $sections[$sid] ?? ['id' => $sid, 'name' => $sid],
                       'total' => $t, 'done' => $d, 'pct' => $pct];
        }

        $mine = $own[$id]['n'] ?? 0;
        $rows[] = [
            'ta'       => ['id' => $id, 'name' => $ta['name']],
            'sections' => $secs,
            'total'    => $tot,
            'done'     => $sum,
            'mine'     => $mine,
            'pct'      => $tot ? (int)round($sum / $tot * 100) : 0,
            'lastAt'   => ms($own[$id]['last'] ?? null),
            'status'   => $complete ? 'done' : ($mine === 0 ? 'pending' : 'working'),
        ];
    }

    $order = ['pending' => 0, 'working' => 1, 'done' => 2];
    usort($rows, fn($a, $b) => $order[$a['status']] <=> $order[$b['status']] ?: $a['pct'] <=> $b['pct']);
    return $rows;
}

switch ($do) {

    case 'taOverview':
        ok(['rows' => ta_rows((int)want('labId'))]);

    case 'labStats': {
        $labId = (int)want('labId');

        $total  = (int)one('SELECT COUNT(*) n FROM students')['n'];
        $marked = (int)one('SELECT COUNT(*) n FROM marks WHERE lab_id = ?', [$labId])['n'];
        $active = (int)one('SELECT COUNT(DISTINCT ta_id) n FROM marks WHERE lab_id = ?', [$labId])['n'];

        $notStarted = (int)one(
            'SELECT COUNT(*) n FROM sections s
             WHERE NOT EXISTS (SELECT 1 FROM marks m WHERE m.section_id = s.id AND m.lab_id = ?)',
            [$labId])['n'];

        $rows = ta_rows($labId);
        ok([
            'total'        => $total,
            'marked'       => $marked,
            'pct'          => $total ? (int)round($marked / $total * 100) : 0,
            'activeTas'    => $active,
            'notStarted'   => $notStarted,
            'tasPending'   => count(array_filter($rows, fn($r) => $r['status'] === 'pending')),
            'tasDone'      => count(array_filter($rows, fn($r) => $r['status'] === 'done')),
            'taCount'      => count($rows),
            'sectionCount' => (int)one('SELECT COUNT(*) n FROM sections')['n'],
        ]);
    }

    case 'matrix': {
        $labs = labs_all();
        $sections = all('SELECT id, name FROM sections ORDER BY sort, id');
        $totals = array_column(
            all('SELECT section_id, COUNT(*) n FROM students GROUP BY section_id'), 'n', 'section_id');

        $cells = [];
        foreach (all('SELECT section_id, lab_id, COUNT(*) n FROM marks GROUP BY section_id, lab_id') as $r) {
            $cells[$r['section_id']][(int)$r['lab_id']] = (int)$r['n'];
        }
        $labHasMarks = array_column(
            all('SELECT lab_id, COUNT(*) n FROM marks GROUP BY lab_id'), 'n', 'lab_id');

        $out = [];
        foreach ($sections as $s) {
            $total = (int)($totals[$s['id']] ?? 0);
            $row = ['section' => $s, 'total' => $total, 'cells' => []];
            foreach ($labs as $l) {
                $n = $cells[$s['id']][$l['id']] ?? 0;
                $row['cells'][] = [
                    'labId'   => $l['id'],
                    'state'   => $l['state'],
                    'started' => $l['state'] === 'open' || !empty($labHasMarks[$l['id']]),
                    'pct'     => $total ? (int)round($n / $total * 100) : 0,
                ];
            }
            $out[] = $row;
        }
        ok(['matrix' => $out]);
    }

    /* Amendment trail — any grade that left the normal path. */
    case 'overrides': {
        $limit = max(1, min(100, (int)inp('limit', 20)));
        $rows = all('SELECT * FROM marks WHERE reason <> "" ORDER BY updated_at DESC LIMIT ' . $limit);
        ok(['items' => decorate_marks($rows)]);
    }

    case 'activity': {
        $limit = max(1, min(100, (int)inp('limit', 12)));
        $rows = all('SELECT * FROM marks ORDER BY updated_at DESC LIMIT ' . $limit);
        ok(['items' => decorate_marks($rows)]);
    }

    /**
     * Who is responsible for whom, for one lab.
     *
     * The distribution is computed rather than stored, so this is the only
     * place the full picture exists: every assistant with the students they
     * must mark, grouped by section, and whether each one is done.
     */
    case 'distribution': {
        $labId = (int)want('labId');

        $sections = all('SELECT id, name, day, time, room FROM sections ORDER BY sort, id');
        $tas = all('SELECT id, name, username FROM users WHERE role = "ta" AND active = 1 ORDER BY name');

        $marks = [];
        foreach (all('SELECT student_id, ta_id, grade, status FROM marks WHERE lab_id = ?', [$labId]) as $m) {
            $marks[$m['student_id']] = [
                'taId'   => (int)$m['ta_id'],
                'grade'  => (int)$m['grade'],
                'status' => $m['status'],
            ];
        }

        $roster = [];
        foreach (all('SELECT id, name, section_id FROM students ORDER BY sort, id') as $s) {
            $roster[$s['section_id']][] = $s;
        }

        // One computation per section, reused for every assistant.
        $assign = [];
        foreach ($sections as $sec) $assign[$sec['id']] = section_assignment($labId, $sec['id']);

        $names = user_names(array_column($marks, 'taId'));

        /**
         * `markedBy` is filled only when someone other than the responsible
         * assistant entered the grade — that is the case worth noticing.
         */
        $pack = function (array $s, int $ownerId) use ($marks, $names) {
            $m = $marks[$s['id']] ?? null;
            return [
                'id'       => $s['id'],
                'name'     => $s['name'],
                'marked'   => (bool)$m,
                'grade'    => $m['grade']  ?? null,
                'status'   => $m['status'] ?? null,
                'markedBy' => ($m && $m['taId'] !== $ownerId) ? ($names[$m['taId']] ?? '—') : null,
            ];
        };

        $rows = [];
        foreach ($tas as $ta) {
            $taId = (int)$ta['id'];
            $secOut = [];
            $total = $done = 0;

            foreach ($sections as $sec) {
                $mine = array_values(array_filter(
                    $roster[$sec['id']] ?? [],
                    fn($s) => ($assign[$sec['id']][$s['id']] ?? null) === $taId
                ));
                if (!$mine) continue;

                $students = array_map(fn($s) => $pack($s, $taId), $mine);
                $total += count($students);
                $done  += count(array_filter($students, fn($s) => $s['marked']));
                $secOut[] = ['section' => $sec, 'students' => $students];
            }

            $rows[] = [
                'ta'       => ['id' => $taId, 'name' => $ta['name'], 'username' => $ta['username']],
                'sections' => $secOut,
                'total'    => $total,
                'done'     => $done,
            ];
        }

        // Students in a section with no assistants assigned to it.
        $orphans = [];
        foreach ($sections as $sec) {
            $none = array_values(array_filter(
                $roster[$sec['id']] ?? [],
                fn($s) => !isset($assign[$sec['id']][$s['id']])
            ));
            if ($none) $orphans[] = ['section' => $sec, 'students' => array_map(fn($s) => $pack($s, 0), $none)];
        }

        ok(['rows' => $rows, 'unassigned' => $orphans]);
    }

    /* Administrative trail — lab state, rubrics, reassignment, accounts. */
    case 'audit': {
        $limit = max(1, min(200, (int)inp('limit', 30)));
        $rows = all('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . $limit);

        ok(['items' => array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'actorId'     => (int)$r['actor_id'],
            'actorName'   => $r['actor_name'],
            'action'      => $r['action'],
            'target'      => $r['target'],
            'targetType'  => $r['target_type'],
            'targetLabel' => $r['target_label'],
            'detail'      => $r['detail'],
            'ip'          => $r['ip'],
            'at'          => ms($r['at']),
        ], $rows)]);
    }

    /* Moving an absent assistant's students to a colleague. */
    case 'reassign': {
        require_post();

        $labId  = (int)want('labId');
        $secId  = want('sectionId');
        $from   = (int)want('fromTaId');
        $to     = (int)want('toTaId');
        $onlyUn = inp('onlyUnmarked', true);

        if (!$to || $from === $to) fail(L('err.pickOtherTa'));
        if (!one('SELECT id FROM users WHERE id = ? AND active = 1', [$to])) fail(L('err.taNotFound'));

        $assign = section_assignment($labId, $secId);
        $targets = array_keys(array_filter($assign, fn($t) => $t === $from));
        if (!$targets) fail(L('err.noStudentsForTa'));

        if ($onlyUn) {
            $ph = in_list(count($targets));
            $marked = array_column(
                all("SELECT student_id FROM marks WHERE lab_id = ? AND student_id IN ($ph)",
                    array_merge([$labId], $targets)), 'student_id');
            $targets = array_values(array_diff($targets, $marked));
        }
        if (!$targets) fail(L('err.noStudentsMovable'));

        $st = db()->prepare('INSERT INTO lab_assignments (lab_id, student_id, ta_id) VALUES (?,?,?)
                             ON DUPLICATE KEY UPDATE ta_id = VALUES(ta_id)');
        db()->beginTransaction();
        foreach ($targets as $sid) $st->execute([$labId, $sid, $to]);
        db()->commit();

        $names = user_names([$from, $to]);
        audit('roster.reassign', (string)$labId, 'lab', '',
              count($targets) . ' students · '
              . ($names[$from] ?? $from) . ' → ' . ($names[$to] ?? $to)
              . ' · ' . $secId);

        ok(['moved' => count($targets)]);
    }

    case 'clearReassign': {
        require_post();
        $labId = (int)want('labId');
        q('DELETE FROM lab_assignments WHERE lab_id = ?', [$labId]);
        audit('roster.clear', (string)$labId, 'lab');
        ok();
    }
}

fail(L('err.unknownAction'), 404);


/* ------------------------------------------------------------------ helpers */
function decorate_marks(array $rows): array
{
    if (!$rows) return [];

    $names = user_names(array_column($rows, 'ta_id'));
    $secs  = array_column(all('SELECT id, name FROM sections'), 'name', 'id');

    $ids = array_values(array_unique(array_column($rows, 'student_id')));
    $students = [];
    foreach (all('SELECT id, name FROM students WHERE id IN (' . in_list(count($ids)) . ')', $ids) as $s) {
        $students[$s['id']] = ['id' => $s['id'], 'name' => $s['name']];
    }

    return array_map(function ($m) use ($names, $secs, $students) {
        $row = mark_row($m, $names);
        $row['student']     = $students[$m['student_id']] ?? null;
        $row['sectionName'] = $secs[$m['section_id']] ?? $m['section_id'];
        return $row;
    }, $rows);
}
