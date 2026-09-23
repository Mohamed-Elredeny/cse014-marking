<?php
/** api/marks.php?do=save */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$me = require_login();
$uid = (int)$me['id'];
$isMain = $me['role'] === 'main';
$do = $_GET['do'] ?? 'save';

if ($do !== 'save') fail(L('err.unknownAction'), 404);
require_post();

$labId     = (int)want('labId');
$studentId = want('studentId');
$status    = (string)inp('status', 'present');
$items     = inp('items', []);
$note      = trim((string)inp('note', ''));
$reason    = trim((string)inp('reason', ''));

if (!in_array($status, ['present', 'late', 'absent'], true)) fail(L('err.badStatus'));

$lab = lab_get($labId);
if (!$lab) fail(L('err.labNotFound'));

$student = one('SELECT id, section_id FROM students WHERE id = ?', [$studentId]);
if (!$student) fail(L('err.studentNotFound'));

// The grade is recomputed from the rubric here too — the interface is not
// the source of truth.
$codes = [];
foreach ($lab['rubric'] as $r) $codes[$r['id']] = (int)$r['points'];

$items = array_values(array_unique(array_filter(
    is_array($items) ? $items : [],
    fn($c) => isset($codes[$c])
)));

if ($status === 'absent') {
    $items = [];
    $grade = 0;
} elseif (inp('grade') !== null && !$items) {
    // Manual grade — the assistant bypassed the rubric.
    $grade = (int)inp('grade');
} else {
    $grade = array_sum(array_map(fn($c) => $codes[$c], $items));
}

if ($grade < 0 || $grade > $lab['max']) fail(L('err.gradeRange', ['max' => $lab['max']]));

$closed = $lab['state'] === 'closed';

/* An entry made offline while the lab was open, arriving after it closed.
   The work was done on time, so it is accepted — and recorded in the log so
   it remains visible. */
$offlineAt = (int)inp('at', 0);
$wasOpen   = false;
if ($closed && $offlineAt > 0) {
    $w   = lab_window(schedule_get(), $labId);
    $sec = (int)($offlineAt / 1000);
    // No future timestamps, and nothing older than a week.
    if ($sec <= time() + 300 && $sec >= time() - 7 * 86400
        && $sec >= $w['from'] && $sec < $w['to']) {
        $wasOpen = true;
        if ($reason === '') $reason = L('mark.offlineReason');
    }
}

if ($closed && !$wasOpen && mb_strlen($reason) < 5) {
    fail(L('err.closedNeedReason'));
}

$prev = one('SELECT * FROM marks WHERE student_id = ? AND lab_id = ?', [$studentId, $labId]);

// Another assistant's grade needs the Main TA's approval, unless you are them.
$byMain = false;
if ($prev && (int)$prev['ta_id'] !== $uid) {
    if (!$isMain) {
        $names = user_names([$prev['ta_id']]);
        fail(L('err.needsApproval'), 409, [
            'needsApproval' => true,
            'existing'      => mark_row($prev, $names),
        ]);
    }
    if (mb_strlen($reason) < 5) fail(L('err.amendOtherReason'));
    $byMain = true;
}

// A pending request locks the student until the Main TA decides.
if (!$isMain) {
    $hasPending = one('SELECT id FROM change_requests
                       WHERE student_id = ? AND lab_id = ? AND state = "pending"',
                      [$studentId, $labId]);
    if ($hasPending) fail(L('err.pendingExists'));
}

/* UPSERT — this is what makes replaying the offline queue safe. */
q('INSERT INTO marks
     (student_id, lab_id, section_id, status, grade, items, note, reason, after_close, by_main, ta_id)
   VALUES (?,?,?,?,?,?,?,?,?,?,?)
   ON DUPLICATE KEY UPDATE
     section_id  = VALUES(section_id),
     status      = VALUES(status),
     grade       = VALUES(grade),
     items       = VALUES(items),
     note        = VALUES(note),
     reason      = VALUES(reason),
     after_close = VALUES(after_close),
     by_main     = VALUES(by_main),
     via_request = NULL,
     ta_id       = VALUES(ta_id)',
  [$studentId, $labId, $student['section_id'], $status, $grade,
   json_encode($items, JSON_UNESCAPED_UNICODE), $note, $reason,
   $closed ? 1 : 0, $byMain ? 1 : 0, $uid]);

$row = one('SELECT * FROM marks WHERE student_id = ? AND lab_id = ?', [$studentId, $labId]);

ok([
    'entry'    => mark_row($row, user_names([$uid])),
    'replaced' => (bool)$prev,
]);
