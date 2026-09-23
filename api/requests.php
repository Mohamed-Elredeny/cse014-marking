<?php
/** api/requests.php?do=create|mine|pending|decide */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_mail.php';

$me = require_login();
$uid = (int)$me['id'];
$do = $_GET['do'] ?? 'mine';

function req_row(array $r, array $names, array $secs, array $labMax, ?array $student): array
{
    return [
        'id'           => (int)$r['id'],
        'studentId'    => $r['student_id'],
        'student'      => $student,
        'labId'        => (int)$r['lab_id'],
        'labMax'       => $labMax[(int)$r['lab_id']] ?? 10,
        'sectionId'    => $r['section_id'],
        'sectionName'  => $secs[$r['section_id']] ?? $r['section_id'],
        'status'       => $r['status'],
        'grade'        => (int)$r['grade'],
        'items'        => json_decode($r['items'] ?: '[]', true) ?: [],
        'reason'       => $r['reason'],
        'taId'         => (int)$r['ta_id'],
        'taName'       => $names[(int)$r['ta_id']] ?? '—',
        'prevGrade'    => $r['prev_grade'] === null ? null : (int)$r['prev_grade'],
        'prevTaId'     => $r['prev_ta_id'] === null ? null : (int)$r['prev_ta_id'],
        'prevTaName'   => $r['prev_ta_id'] ? ($names[(int)$r['prev_ta_id']] ?? '—') : '—',
        'state'        => $r['state'],
        'decisionNote' => $r['decision_note'],
        'decidedAt'    => ms($r['decided_at']),
        'at'           => ms($r['created_at']),
    ];
}

function decorate(array $rows): array
{
    if (!$rows) return [];

    $names = user_names(array_merge(array_column($rows, 'ta_id'), array_column($rows, 'prev_ta_id')));
    $secs  = array_column(all('SELECT id, name FROM sections'), 'name', 'id');

    $labMax = [];
    foreach (labs_all() as $l) $labMax[$l['id']] = $l['max'];

    $ids = array_values(array_unique(array_column($rows, 'student_id')));
    $students = [];
    foreach (all('SELECT id, name FROM students WHERE id IN (' . in_list(count($ids)) . ')', $ids) as $s) {
        $students[$s['id']] = ['id' => $s['id'], 'name' => $s['name']];
    }

    return array_map(
        fn($r) => req_row($r, $names, $secs, $labMax, $students[$r['student_id']] ?? null),
        $rows
    );
}

switch ($do) {

    /* ------------- an assistant asks to change another's grade ------------- */
    case 'create': {
        require_post();

        $labId     = (int)want('labId');
        $studentId = want('studentId');
        $status    = (string)inp('status', 'present');
        $reason    = trim((string)inp('reason', ''));
        $items     = inp('items', []);

        if (mb_strlen($reason) < 5) fail(L('err.reqReason'));
        if (!in_array($status, ['present', 'late', 'absent'], true)) fail(L('err.badStatus'));

        $lab = lab_get($labId);
        if (!$lab) fail(L('err.labNotFound'));

        $student = one('SELECT id, section_id FROM students WHERE id = ?', [$studentId]);
        if (!$student) fail(L('err.studentNotFound'));

        if (one('SELECT id FROM change_requests
                 WHERE student_id = ? AND lab_id = ? AND state = "pending"', [$studentId, $labId])) {
            fail(L('err.reqExists'));
        }

        $codes = [];
        foreach ($lab['rubric'] as $r) $codes[$r['id']] = (int)$r['points'];
        $items = array_values(array_unique(array_filter(
            is_array($items) ? $items : [], fn($c) => isset($codes[$c]))));

        if ($status === 'absent')                      { $items = []; $grade = 0; }
        elseif (inp('grade') !== null && !$items)      { $grade = (int)inp('grade'); }
        else                                           { $grade = array_sum(array_map(fn($c) => $codes[$c], $items)); }

        if ($grade < 0 || $grade > $lab['max']) fail(L('err.gradeRange', ['max' => $lab['max']]));

        $prev = one('SELECT grade, ta_id FROM marks WHERE student_id = ? AND lab_id = ?',
                    [$studentId, $labId]);

        q('INSERT INTO change_requests
             (student_id, lab_id, section_id, status, grade, items, reason, ta_id, prev_grade, prev_ta_id)
           VALUES (?,?,?,?,?,?,?,?,?,?)',
          [$studentId, $labId, $student['section_id'], $status, $grade,
           json_encode($items, JSON_UNESCAPED_UNICODE), $reason, $uid,
           $prev['grade'] ?? null, $prev['ta_id'] ?? null]);

        $id  = (int)db()->lastInsertId();
        $row = one('SELECT * FROM change_requests WHERE id = ?', [$id]);

        $stName = one('SELECT name FROM students WHERE id = ?', [$studentId])['name'] ?? $studentId;
        notify_new_request($row, $me['name'], $stName);

        ok(['request' => decorate([$row])[0]]);
    }

    /* ------------------- the assistant's own requests ------------------- */
    case 'mine': {
        $limit = max(1, min(50, (int)inp('limit', 20)));
        $rows = all('SELECT * FROM change_requests WHERE ta_id = ?
                     ORDER BY created_at DESC LIMIT ' . $limit, [$uid]);
        ok(['requests' => decorate($rows)]);
    }

    /* ------------------ awaiting the Main TA's decision ------------------ */
    case 'pending': {
        require_main();
        $rows = all('SELECT * FROM change_requests WHERE state = "pending" ORDER BY created_at ASC');
        ok(['requests' => decorate($rows)]);
    }

    /* --------------------------- approve or reject --------------------------- */
    case 'decide': {
        require_main();
        require_post();

        $id       = (int)want('id');
        $decision = (string)want('decision');           // approved | rejected
        $note     = trim((string)inp('note', ''));

        if (!in_array($decision, ['approved', 'rejected'], true)) fail(L('err.badDecision'));
        if ($decision === 'rejected' && mb_strlen($note) < 3) fail(L('err.rejectNote'));

        $r = one('SELECT * FROM change_requests WHERE id = ?', [$id]);
        if (!$r) fail(L('err.reqNotFound'));
        if ($r['state'] !== 'pending') fail(L('err.reqDecided'));

        db()->beginTransaction();
        try {
            q('UPDATE change_requests
               SET state = ?, decided_by = ?, decided_at = NOW(), decision_note = ?
               WHERE id = ? AND state = "pending"',
              [$decision, $uid, $note, $id]);

            if ($decision === 'approved') {
                // The grade is applied and ownership passes to the requester.
                q('INSERT INTO marks
                     (student_id, lab_id, section_id, status, grade, items, note, reason,
                      after_close, by_main, via_request, ta_id)
                   VALUES (?,?,?,?,?,?,"",?,0,0,?,?)
                   ON DUPLICATE KEY UPDATE
                     section_id  = VALUES(section_id),
                     status      = VALUES(status),
                     grade       = VALUES(grade),
                     items       = VALUES(items),
                     reason      = VALUES(reason),
                     after_close = 0,
                     by_main     = 0,
                     via_request = VALUES(via_request),
                     ta_id       = VALUES(ta_id)',
                  [$r['student_id'], $r['lab_id'], $r['section_id'], $r['status'], $r['grade'],
                   $r['items'], $r['reason'], $id, $r['ta_id']]);
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            error_log('[marking] decide: ' . $e->getMessage());
            fail(L('err.decideFailed'), 500);
        }

        $row = one('SELECT * FROM change_requests WHERE id = ?', [$id]);
        $stName = one('SELECT name FROM students WHERE id = ?', [$row['student_id']])['name']
                  ?? $row['student_id'];

        audit($decision === 'approved' ? 'request.approve' : 'request.reject',
              (string)$row['student_id'], 'student', $stName,
              lab_label((int)$row['lab_id'], 'en') . ' → ' . $row['grade']);

        notify_request_decided($row, $stName);

        ok(['request' => decorate([$row])[0]]);
    }
}

fail(L('err.unknownAction'), 404);
