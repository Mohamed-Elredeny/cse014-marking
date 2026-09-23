<?php
/**
 * db/seed.php — sample data for testing
 *   php db/seed.php
 *
 * Truncates every table and refills it. Never run this against real data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }

require __DIR__ . '/../api/_bootstrap.php';

const PASSWORD   = 'demo';     // shared password for every sample account
const SECTIONS_N = 12;
const LABS_N     = 12;
const CURRENT_LAB = 4;

/* A fixed generator, so the sample data is the same on every run. */
function rng(int $seed): callable {
    $s = $seed;
    return function () use (&$s) {
        $s = ($s * 1664525 + 1013904223) % 4294967296;
        return $s / 4294967296;
    };
}

$FIRST = [['أحمد','ahmed'],['محمد','mohamed'],['يوسف','youssef'],['عمر','omar'],
          ['مريم','mariam'],['سارة','sara'],['نور','nour'],['حبيبة','habiba'],
          ['خالد','khaled'],['ملك','malak'],['زياد','ziad'],['فاطمة','fatma'],
          ['عبدالرحمن','abdelrahman'],['هنا','hana'],['كريم','karim'],['جنى','jana'],
          ['مصطفى','mostafa'],['رنا','rana'],['طارق','tarek'],['ليلى','laila'],
          ['سيف','seif'],['دينا','dina'],['حازم','hazem'],['آية','aya']];

$LAST  = [['عبدالله','abdallah'],['السيد','elsayed'],['حسن','hassan'],['إبراهيم','ibrahim'],
          ['فؤاد','fouad'],['منصور','mansour'],['شعبان','shaaban'],['الشريف','elsherif'],
          ['رمضان','ramadan'],['زكي','zaki'],['عثمان','othman'],['غانم','ghanem'],
          ['سليم','selim'],['نبيل','nabil'],['مرسي','morsy'],['قنديل','kandil']];

$TA_NAMES = ['م. أحمد سمير','م. مريم فتحي','م. يوسف عادل','م. نورهان حسن',
             'م. كريم مجدي','م. سلمى رأفت','م. عمر الشناوي','م. هاجر وليد'];

$DEFAULT_RUBRIC = [
    ['r1','الكود بيكومبايل ويشتغل من غير أخطاء', 3],
    ['r2','المخرجات مظبوطة في كل حالات الاختبار', 3],
    ['r3','استخدم الأسلوب المطلوب في اللاب', 2],
    ['r4','تسمية المتغيرات والتنسيق والتعليقات', 1],
    ['r5','رد على سؤال المعيد وفاهم كوده', 1],
];

$RUBRICS = [
    1 => [['r1','ظبط الـ IDE وعمل compile لأول برنامج',2],
          ['r2','استخدم printf / scanf صح',3],
          ['r3','أنواع البيانات والمتغيرات مظبوطة',2],
          ['r4','المخرجات مطابقة للمطلوب',2],
          ['r5','التنسيق والتعليقات',1]],
    4 => [['r1','الكود بيكومبايل ويشتغل',2],
          ['r2','اختار الـ loop الصح (for / while)',2],
          ['r3','شرط التوقف مظبوط ومفيش infinite loop',2],
          ['r4','المخرجات مطابقة في كل الحالات',2],
          ['r5','التنسيق وتسمية المتغيرات',1],
          ['r6','رد على سؤال المعيد',1]],
];

$db = db();
echo "Clearing the tables...\n";
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['change_requests','marks','lab_assignments','lab_rubric','labs',
          'students','section_tas','sections','users'] as $t) {
    $db->exec("TRUNCATE TABLE $t");
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

/* ------------------------------------------------ المستخدمين */
$hash = password_hash(PASSWORD, PASSWORD_DEFAULT);
q('INSERT INTO users (username, name, password_hash, role) VALUES (?,?,?,?)',
  ['main', 'م. عبدالله (Main TA)', $hash, 'main']);
$mainId = (int)$db->lastInsertId();

$taIds = [];
foreach ($TA_NAMES as $i => $n) {
    q('INSERT INTO users (username, name, password_hash, role) VALUES (?,?,?,?)',
      ['ta' . ($i + 1), $n, $hash, 'ta']);
    $taIds[] = (int)$db->lastInsertId();
}
echo "Users: 1 Main TA + " . count($taIds) . " assistants\n";

/* ------------------------------------------------ السكاشن */
$DAYS  = ['السبت','الأحد','الاثنين','الثلاثاء','الأربعاء'];
$TIMES = ['09:00','11:00','13:00','15:00'];

for ($i = 0; $i < SECTIONS_N; $i++) {
    $n  = $i + 1;
    $id = 'S' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
    q('INSERT INTO sections (id, name, day, time, room, sort) VALUES (?,?,?,?,?,?)',
      [$id, "Section $n", $DAYS[$i % 5], $TIMES[$i % 4],
       'Lab ' . chr(65 + ($i % 4)) . (($i % 3) + 1), $n]);

    // معيدين لكل سكشن — الترتيب هو اللي التقسيم بيتبعه
    q('INSERT INTO section_tas (section_id, ta_id, sort) VALUES (?,?,0)', [$id, $taIds[$i % 8]]);
    q('INSERT INTO section_tas (section_id, ta_id, sort) VALUES (?,?,1)', [$id, $taIds[($i + 3) % 8]]);
}
echo "Sections: " . SECTIONS_N . "\n";

/* ------------------------------------------------ الطلاب */
$rand = rng(20260921);
$serial = 0;
$stIns = $db->prepare('INSERT INTO students (id, name, name_norm, email, section_id, sort)
                       VALUES (?,?,?,?,?,?)');
$rosters = [];

for ($i = 0; $i < SECTIONS_N; $i++) {
    $secId = 'S' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);
    $count = 24 + (int)floor($rand() * 8);
    $rosters[$secId] = [];

    for ($k = 0; $k < $count; $k++) {
        $serial++;
        $f = $FIRST[(int)floor($rand() * count($FIRST))];
        $l = $LAST[(int)floor($rand() * count($LAST))];
        $id = (string)(2400000 + $serial * 3);
        $name = $f[0] . ' ' . $l[0];
        $mail = $f[1] . '.' . $l[1] . substr($id, -3) . '@aiu.edu.eg';

        $stIns->execute([$id, $name, ar_norm($name), $mail, $secId, $k]);
        $rosters[$secId][] = $id;
    }
}
echo "Students: $serial\n";

/* ------------------------------------------------ اللابات والروبريك */
for ($i = 1; $i <= LABS_N; $i++) {
    q('INSERT INTO labs (id, title, state_override) VALUES (?,?,NULL)', [$i, "Lab $i"]);
    $items = $RUBRICS[$i] ?? $DEFAULT_RUBRIC;
    foreach ($items as $s => $it) {
        q('INSERT INTO lab_rubric (lab_id, code, text, points, sort) VALUES (?,?,?,?,?)',
          [$i, $it[0], $it[1], $it[2], $s]);
    }
}
echo "Labs: " . LABS_N . "\n";

/* الجدول: Lab 1 بدأ من 3 أسابيع، فاللاب الحالي هو الرابع */
set_setting('schedule', [
    'startDate' => gmdate('Y-m-d', time() - 21 * 86400),
    'weekDays'  => 7,
    'auto'      => true,
]);

/* ------------------------------------------------ الدرجات */
$rubricOf = [];
foreach (all('SELECT lab_id, code, points FROM lab_rubric ORDER BY lab_id, sort') as $r) {
    $rubricOf[(int)$r['lab_id']][] = [$r['code'], (int)$r['points']];
}

// نفس التقسيم اللي الـ API بيحسبه
function assigned(int $labId, array $roster, array $tas, string $sid): int {
    $i = array_search($sid, $roster, true);
    $per = (int)ceil(count($roster) / count($tas));
    return $tas[((int)floor($i / $per) + $labId - 1) % count($tas)];
}

$cover = [1, .85, .6, 0, .95, .3, 0, .7, 1, 0, .45, .15];
$srand = rng(777);
$mk = $db->prepare('INSERT INTO marks
    (student_id, lab_id, section_id, status, grade, items, note, reason, after_close, ta_id, created_at, updated_at)
    VALUES (?,?,?,?,?,?,"",?,?,?,?,?)');

$marks = 0;
for ($lab = 1; $lab <= CURRENT_LAB; $lab++) {
    for ($i = 0; $i < SECTIONS_N; $i++) {
        $secId  = 'S' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT);
        $roster = $rosters[$secId];
        $tas    = [$taIds[$i % 8], $taIds[($i + 3) % 8]];
        $ratio  = $lab < CURRENT_LAB ? 1.0 : $cover[$i];
        $take   = (int)round(count($roster) * $ratio);

        for ($k = 0; $k < $take; $k++) {
            $sid = $roster[$k];
            $r = $srand();
            $status = $r > 0.93 ? 'absent' : ($r > 0.88 ? 'late' : 'present');

            $items = []; $grade = 0;
            if ($status !== 'absent') {
                foreach ($rubricOf[$lab] as [$code, $pts]) {
                    if ($srand() < 0.82) { $items[] = $code; $grade += $pts; }
                }
            }

            $at = gmdate('Y-m-d H:i:s',
                time() - (LABS_N - $lab) * 7 * 86400 + $k * 95 - (int)floor($srand() * 3600));

            $mk->execute([$sid, $lab, $secId, $status, $grade,
                          json_encode($items, JSON_UNESCAPED_UNICODE), '', 0,
                          assigned($lab, $roster, $tas, $sid), $at, $at]);
            $marks++;
        }
    }
}
echo "Grades: $marks\n";

/* تعديلات بعد الإقفال — عشان السجل يبان */
$why = ['الطالب سلّم متأخر بعذر مرضي معتمد',
        'خطأ مني في إدخال الدرجة أول مرة',
        'إعادة تصحيح بعد شكوى الطالب'];
$old = all('SELECT id FROM marks WHERE lab_id < ? ORDER BY id LIMIT 3 OFFSET 40', [CURRENT_LAB]);
foreach ($old as $k => $m) {
    q('UPDATE marks SET reason = ?, after_close = 1, updated_at = ? WHERE id = ?',
      [$why[$k % 3], gmdate('Y-m-d H:i:s', time() - ($k + 1) * 5400), $m['id']]);
}

/* ------------------------------------------------ طلبات معلّقة */
$lab4Max = array_sum(array_column($rubricOf[CURRENT_LAB], 1));
$cands = all('SELECT m.*, s.section_id FROM marks m JOIN students s ON s.id = m.student_id
              WHERE m.lab_id = ? AND m.status = "present" AND m.grade <= ?
              ORDER BY m.id LIMIT 3 OFFSET 5', [CURRENT_LAB, $lab4Max - 3]);

$reqWhy = ['الطالب حضر معايا في Section 5 نفس الأسبوع وسلّم شغل أحسن — محتاج أرفع درجته.',
           'الطالب أعاد اللاب معايا وكمّل الجزء الناقص.',
           'حضر عندي في السكشن التاني وعمل التاسك كامل قدامي.'];

foreach ($cands as $k => $m) {
    $other = null;
    foreach ($taIds as $t) if ($t !== (int)$m['ta_id']) { $other = $t; break; }
    q('INSERT INTO change_requests
         (student_id, lab_id, section_id, status, grade, items, reason, ta_id, prev_grade, prev_ta_id, created_at)
       VALUES (?,?,?,"present",?,?,?,?,?,?,?)',
      [$m['student_id'], CURRENT_LAB, $m['section_id'],
       min($lab4Max, (int)$m['grade'] + 2),
       json_encode(array_column($rubricOf[CURRENT_LAB], 0), JSON_UNESCAPED_UNICODE),
       $reqWhy[$k % 3], $other, (int)$m['grade'], (int)$m['ta_id'],
       gmdate('Y-m-d H:i:s', time() - ($k + 1) * 3300)]);
}
echo "Pending change requests: " . count($cands) . "\n";

echo "\nDone. Sign in as main, or ta1 through ta8 — password: " . PASSWORD . "\n";
echo "Change every password before deploying this anywhere.\n";
