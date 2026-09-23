<?php
/** api/export.php?do=lab|all  → { filename, csv } */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

require_main();
$do = $_GET['do'] ?? 'lab';

/* Column values stay in English so the file is stable for downstream tools
   regardless of the interface language. */
const STATUS_EN = ['present' => 'present', 'late' => 'late', 'absent' => 'absent'];

function csv_cell($v): string
{
    $t = (string)($v ?? '');
    return preg_match('/[",\r\n]/', $t) ? '"' . str_replace('"', '""', $t) . '"' : $t;
}

/** A byte-order mark so Excel reads Arabic correctly. */
function to_csv(array $head, array $rows): string
{
    $lines = [implode(',', array_map('csv_cell', $head))];
    foreach ($rows as $r) $lines[] = implode(',', array_map('csv_cell', $r));
    return "\xEF\xBB\xBF" . implode("\r\n", $lines);
}

switch ($do) {

    case 'lab': {
        $labId = (int)want('labId');
        $lab = lab_get($labId);
        if (!$lab) fail(L('err.labNotFound'));

        $rows = all('SELECT s.id, s.name, s.email, sec.name AS section,
                            m.status, m.grade, m.reason, m.updated_at, u.name AS ta
                     FROM students s
                     JOIN sections sec ON sec.id = s.section_id
                     LEFT JOIN marks m ON m.student_id = s.id AND m.lab_id = ?
                     LEFT JOIN users u ON u.id = m.ta_id
                     ORDER BY sec.sort, sec.id, s.sort, s.id', [$labId]);

        $out = array_map(fn($r) => [
            $r['id'], $r['name'], $r['email'], $r['section'],
            $r['status'] ? STATUS_EN[$r['status']] : 'not_marked',
            $r['status'] ? $r['grade'] : '',
            $lab['max'],
            $r['ta'] ?? '',
            $r['updated_at'] ? gmdate('c', strtotime($r['updated_at'] . ' UTC')) : '',
            $r['reason'] ?? '',
        ], $rows);

        ok([
            'filename' => "CSE014_Lab{$labId}.csv",
            'csv'      => to_csv(
                ['student_id','name','email','section','status','grade','max','ta','at','reason'],
                $out
            ),
        ]);
    }

    case 'all': {
        $labs = labs_all();
        $maxTotal = array_sum(array_column($labs, 'max'));

        $students = all('SELECT s.id, s.name, s.email, sec.name AS section
                         FROM students s JOIN sections sec ON sec.id = s.section_id
                         ORDER BY sec.sort, sec.id, s.sort, s.id');

        $grades = [];
        foreach (all('SELECT student_id, lab_id, grade FROM marks') as $m) {
            $grades[$m['student_id']][(int)$m['lab_id']] = (int)$m['grade'];
        }

        $out = [];
        foreach ($students as $s) {
            $cells = [];
            $total = 0;
            foreach ($labs as $l) {
                $g = $grades[$s['id']][$l['id']] ?? null;
                $cells[] = $g === null ? '' : $g;
                $total += (int)$g;
            }
            $out[] = array_merge([$s['id'], $s['name'], $s['email'], $s['section']],
                                 $cells, [$total, $maxTotal]);
        }

        $head = array_merge(['student_id','name','email','section'],
                            array_map(fn($l) => 'L' . $l['id'], $labs),
                            ['total','max_total']);

        ok(['filename' => 'CSE014_all_labs.csv', 'csv' => to_csv($head, $out)]);
    }
}

fail(L('err.unknownAction'), 404);
