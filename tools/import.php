<?php
/**
 * tools/import.php — imports assistants, sections, and students from CSV.
 * Restricted to the Main TA.
 *
 * The page renders server-side in the reader's language; the toggle in the
 * header updates the shared cookie and reloads.
 */
declare(strict_types=1);
require __DIR__ . '/../api/_bootstrap.php';

$me = me();
if (!$me)                   { header('Location: ../index.html'); exit; }
if ($me['role'] !== 'main') { http_response_code(403); exit(L('imp.mainOnly')); }

/** kind => [label key, column list, column names] */
const KINDS = [
    'tas'      => ['imp.kindTas',      'username,name,email,password', ['username','name','email','password']],
    'sections' => ['imp.kindSections', 'id,name,day,time,room,tas',    ['id','name','day','time','room','tas']],
    'students' => ['imp.kindStudents', 'id,name,email,section_id',     ['id','name','email','section_id']],
    'enrol'    => ['imp.kindEnrol',    'ID,Name,Full Course Code,Section,…', []],
];

/** The course this installation marks. Rows for anything else are ignored. */
const COURSE_CODE = 'CSE014';

/* This form replaces student records, so a POST must carry the session's
   token. Without it a page the Main TA merely visits could drive an import. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = (string)($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit(L('err.sessionExpired'));
    }
}

$kind   = $_POST['kind'] ?? 'students';
$raw    = trim((string)($_POST['csv'] ?? ''));
$commit = isset($_POST['commit']);
$wipe   = isset($_POST['wipe']);
$report = null;

if (!isset(KINDS[$kind])) $kind = 'students';

/* An uploaded file takes precedence over pasted text. */
if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $raw = trim((string)file_get_contents($_FILES['file']['tmp_name']));
}

/** Splits the CSV and drops a header row when one is present. */
function parse_csv(string $raw, array $cols): array
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);          // byte-order mark
    $lines = preg_split('/\r\n|\r|\n/', $raw);

    $rows = [];
    foreach ($lines as $i => $line) {
        if (trim($line) === '') continue;
        $cells = array_map('trim', str_getcsv($line));

        // Header row?
        if ($i === 0
            && in_array(mb_strtolower($cells[0]), [$cols[0], 'student_id', 'section_id', 'id', 'username'], true)
            && !is_numeric($cells[0]) && count($cells) > 1
            && in_array(mb_strtolower($cells[1]), ['name', 'الاسم'], true)) {
            continue;
        }
        $rows[] = $cells;
    }
    return $rows;
}

/** "Row 12: Invalid username (x)." */
function row_error(int $line, string $key, array $vars = []): string
{
    return L('imp.row', ['n' => $line]) . ': ' . L($key, $vars);
}

/* ==========================================================================
   Enrolment export
   --------------------------------------------------------------------------
   The university export lists every course on campus, with a title line above
   the real header and one row per student per component. What matters here is
   narrow: CSE014 rows whose Unit Taken is zero, which is the lab component and
   therefore the section a student is actually marked in. The lecture rows
   carry the same students against a different section and are skipped.
   ========================================================================== */

/** Finds the header row and maps the columns this import needs. */
function enrol_header(array $rows): ?array
{
    foreach ($rows as $i => $cells) {
        $map = [];
        foreach ($cells as $j => $name) {
            $key = strtolower(trim((string)$name));
            // 'Descr' appears twice; the first occurrence wins.
            if ($key !== '' && !isset($map[$key])) $map[$key] = $j;
        }
        if (isset($map['id'], $map['name'], $map['full course code'])) {
            return ['row' => $i, 'map' => $map];
        }
        if ($i > 20) break;              // the header is always near the top
    }
    return null;
}

/** 0.5625 -> "13:30". Excel stores a time of day as a fraction. */
function enrol_time($v): string
{
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('/^\d{1,2}:\d{2}/', $v)) return substr($v, 0, 5);
    if (!is_numeric($v)) return '';
    $m = (int)round((float)$v * 24 * 60);
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

/** Reads the CSE014 lab rows out of a parsed enrolment export. */
function enrol_extract(array $rows, array $head): array
{
    $map = $head['map'];
    $get = function (array $r, string $col) use ($map) {
        $j = $map[$col] ?? null;
        return $j === null ? '' : trim((string)($r[$j] ?? ''));
    };

    $days = ['mon' => 'Monday', 'tues' => 'Tuesday', 'wed' => 'Wednesday',
             'thurs' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    $students = [];
    $sections = [];
    $scanned = 0;

    foreach (array_slice($rows, $head['row'] + 1) as $r) {
        if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
        $scanned++;

        if (strtoupper($get($r, 'full course code')) !== COURSE_CODE) continue;

        // Unit Taken 0 marks the lab component; anything else is the lecture.
        $units = $get($r, 'unit taken');
        if ($units !== '' && abs((float)$units) > 0.0001) continue;

        $id  = $get($r, 'id');
        $sec = $get($r, 'section');
        if ($id === '' || $sec === '') continue;

        $day = '';
        foreach ($days as $col => $label) {
            if (strtoupper($get($r, $col)) === 'Y') { $day = $label; break; }
        }

        $students[$id] = [
            'id'         => $id,
            'name'       => $get($r, 'name'),
            'email'      => $get($r, 'email'),
            'section_id' => $sec,
        ];

        if (!isset($sections[$sec])) {
            $sections[$sec] = [
                'id'   => $sec,
                'day'  => $day,
                'time' => enrol_time($get($r, 'mtg start')),
                'room' => $get($r, 'facil id'),
            ];
        }
    }

    return ['students' => array_values($students), 'sections' => $sections, 'scanned' => $scanned];
}

/* ---------------------------------------------------- enrolment export ---- */
if ($raw !== '' && $kind === 'enrol' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $lines = preg_split('/\r\n|\r|\n/', preg_replace('/^\xEF\xBB\xBF/', '', $raw));
    $grid  = array_map('str_getcsv', array_filter($lines, fn($l) => trim($l) !== ''));

    $head = enrol_header($grid);

    // `errors` blocks the import; `notes` and `gone` are reported for review.
    $report = ['labelKey' => 'imp.kindEnrol', 'rows' => 0, 'errors' => [],
               'errorCount' => 0, 'done' => 0, 'made' => [], 'notes' => [], 'gone' => []];

    if (!$head) {
        $report['errors'][] = L('imp.enrolNoHeader');
    } else {
        $found = enrol_extract($grid, $head);

        if (!$found['students']) {
            $report['errors'][] = L('imp.enrolNoRows');
        } else {
            $report['rows'] = count($found['students']);
            $report['notes'][] = L('imp.enrolSummary', [
                'scanned'  => $found['scanned'],
                'found'    => count($found['students']),
                'sections' => count($found['sections']),
            ]);

            // Compare against what is already recorded before changing anything.
            $existing = [];
            foreach (all('SELECT id, section_id FROM students') as $s) $existing[$s['id']] = $s['section_id'];
            $haveSections = array_column(all('SELECT id FROM sections'), 'id');

            $added = $moved = $same = 0;
            foreach ($found['students'] as $s) {
                if (!isset($existing[$s['id']]))                 $added++;
                elseif ($existing[$s['id']] !== $s['section_id']) $moved++;
                else                                              $same++;
            }
            $gone = array_diff(array_keys($existing), array_column($found['students'], 'id'));
            $newSections = array_diff(array_keys($found['sections']), $haveSections);

            $report['notes'][] = L('imp.enrolAdded',  ['n' => $added]);
            $report['notes'][] = L('imp.enrolMoved',  ['n' => $moved]);
            $report['notes'][] = L('imp.enrolSame',   ['n' => $same]);
            if ($newSections) $report['notes'][] = L('imp.enrolSecNew', ['n' => count($newSections)]);
            if ($gone) {
                $report['notes'][] = L('imp.enrolGone', ['n' => count($gone)]);
                $report['notes'][] = L('imp.enrolGoneNote');
                foreach (array_slice($gone, 0, 30) as $g) {
                    $nm = one('SELECT name FROM students WHERE id = ?', [$g])['name'] ?? '';
                    $report['gone'][] = $g . '  ' . $nm;
                }
            }

            if ($commit) {
                db()->beginTransaction();
                try {
                    // Sections first: a student cannot reference one that is absent.
                    $si = 0;
                    foreach ($found['sections'] as $sec) {
                        q('INSERT INTO sections (id, name, day, time, room, sort) VALUES (?,?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE day=VALUES(day), time=VALUES(time), room=VALUES(room)',
                          [$sec['id'], 'Section ' . $sec['id'], $sec['day'], $sec['time'],
                           $sec['room'] ?: '—', $si++]);
                    }

                    // File order is the student order the distribution follows.
                    $order = [];
                    foreach ($found['students'] as $s) {
                        $sid = $s['section_id'];
                        $order[$sid] = ($order[$sid] ?? -1) + 1;
                        q('INSERT INTO students (id, name, name_norm, email, section_id, sort)
                           VALUES (?,?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE name=VALUES(name), name_norm=VALUES(name_norm),
                             email=VALUES(email), section_id=VALUES(section_id), sort=VALUES(sort)',
                          [$s['id'], $s['name'], ar_norm($s['name']), $s['email'], $sid, $order[$sid]]);
                        $report['done']++;
                    }
                    db()->commit();

                    audit('import.run', 'enrolment', '', '',
                          $report['done'] . " students · +$added moved:$moved · "
                          . count($found['sections']) . ' sections');
                } catch (Throwable $e) {
                    db()->rollBack();
                    error_log('[marking] enrolment import: ' . $e->getMessage());
                    $report['errors'][] = L('imp.errStopped', ['v' => $e->getMessage()]);
                    $report['done'] = 0;
                }

                // Sections nobody covers leave their students unassigned.
                $orphan = array_column(all(
                    'SELECT s.id FROM sections s
                     LEFT JOIN section_tas t ON t.section_id = s.id
                     WHERE t.section_id IS NULL'), 'id');
                if ($orphan) {
                    $report['notes'][] = L('imp.enrolSecNoTa', ['list' => implode(', ', $orphan)]);
                }
            }
        }
    }

    $report['errorCount'] = count($report['errors']);
}

/* ------------------------------------------------------- the simple kinds -- */
if ($raw !== '' && $kind !== 'enrol' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    [$labelKey, $sample, $cols] = KINDS[$kind];
    $rows = parse_csv($raw, $cols);

    $errors = [];
    $clean  = [];

    foreach ($rows as $n => $r) {
        $ln = $n + 1;

        if ($kind === 'tas') {
            [$u, $nm, $em, $pw] = array_pad(array_slice($r, 0, 4), 4, '');
            if ($u === '' || $nm === '') {
                $errors[] = row_error($ln, 'imp.errTaRequired'); continue;
            }
            if (!preg_match('/^[a-z0-9._-]{3,50}$/i', $u)) {
                $errors[] = row_error($ln, 'imp.errBadUsername', ['v' => $u]); continue;
            }
            if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $errors[] = row_error($ln, 'imp.errBadEmail', ['v' => $em]); continue;
            }
            $clean[] = ['username' => strtolower($u), 'name' => $nm, 'email' => $em, 'password' => $pw];

        } elseif ($kind === 'sections') {
            [$id, $nm, $day, $time, $room, $tas] = array_pad(array_slice($r, 0, 6), 6, '');
            if ($id === '' || $nm === '') {
                $errors[] = row_error($ln, 'imp.errSecRequired'); continue;
            }
            $clean[] = ['id' => $id, 'name' => $nm, 'day' => $day, 'time' => $time,
                        'room' => $room, 'tas' => array_filter(array_map('trim', explode('|', $tas)))];

        } else {
            [$id, $nm, $mail, $sec] = array_pad(array_slice($r, 0, 4), 4, '');
            if ($id === '' || $nm === '' || $sec === '') {
                $errors[] = row_error($ln, 'imp.errStuRequired'); continue;
            }
            $clean[] = ['id' => $id, 'name' => $nm, 'email' => $mail, 'section_id' => $sec];
        }
    }

    /* Referential checks */
    if ($kind === 'students' && $clean) {
        $have = array_column(all('SELECT id FROM sections'), 'id');
        foreach (array_unique(array_column($clean, 'section_id')) as $s) {
            if (!in_array($s, $have, true)) $errors[] = L('imp.errNoSection', ['v' => $s]);
        }
    }
    if ($kind === 'sections' && $clean) {
        $have = array_column(all('SELECT username FROM users'), 'username');
        foreach ($clean as $c) {
            foreach ($c['tas'] as $u) {
                if (!in_array(strtolower($u), $have, true)) $errors[] = L('imp.errNoTa', ['v' => $u]);
            }
        }
    }

    $report = [
        'labelKey'   => $labelKey,
        'rows'       => count($clean),
        'errors'     => array_slice($errors, 0, 20),
        'errorCount' => count($errors),
        'done'       => 0,
        'made'       => [],
    ];

    if ($commit && !$errors && $clean) {
        db()->beginTransaction();
        try {
            if ($kind === 'tas') {
                foreach ($clean as $c) {
                    $pw = $c['password'] !== '' ? $c['password'] : generated_password();
                    $exists = one('SELECT id FROM users WHERE username = ?', [$c['username']]);

                    if ($exists) {
                        if ($c['password'] !== '') {
                            q('UPDATE users SET name = ?, email = ?, password_hash = ?, active = 1 WHERE id = ?',
                              [$c['name'], $c['email'], password_hash($pw, PASSWORD_DEFAULT), $exists['id']]);
                        } else {
                            q('UPDATE users SET name = ?, email = ?, active = 1 WHERE id = ?',
                              [$c['name'], $c['email'], $exists['id']]);
                        }
                    } else {
                        q('INSERT INTO users (username, name, email, password_hash, role) VALUES (?,?,?,?,"ta")',
                          [$c['username'], $c['name'], $c['email'], password_hash($pw, PASSWORD_DEFAULT)]);
                        $report['made'][] = $c['username'] . ' : ' . $pw;
                    }
                    $report['done']++;
                }

            } elseif ($kind === 'sections') {
                foreach ($clean as $i => $c) {
                    q('INSERT INTO sections (id, name, day, time, room, sort) VALUES (?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE name=VALUES(name), day=VALUES(day),
                         time=VALUES(time), room=VALUES(room), sort=VALUES(sort)',
                      [$c['id'], $c['name'], $c['day'], $c['time'], $c['room'], $i + 1]);

                    q('DELETE FROM section_tas WHERE section_id = ?', [$c['id']]);
                    foreach (array_values($c['tas']) as $s => $u) {
                        $ta = one('SELECT id FROM users WHERE username = ?', [strtolower($u)]);
                        if ($ta) {
                            q('INSERT INTO section_tas (section_id, ta_id, sort) VALUES (?,?,?)',
                              [$c['id'], $ta['id'], $s]);
                        }
                    }
                    $report['done']++;
                }

            } else {
                $secs = array_unique(array_column($clean, 'section_id'));
                if ($wipe) {
                    q('DELETE FROM students WHERE section_id IN (' . in_list(count($secs)) . ')',
                      array_values($secs));
                }
                $order = [];
                foreach ($clean as $c) {
                    $sec = $c['section_id'];
                    $order[$sec] = ($order[$sec] ?? -1) + 1;
                    q('INSERT INTO students (id, name, name_norm, email, section_id, sort) VALUES (?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE name=VALUES(name), name_norm=VALUES(name_norm),
                         email=VALUES(email), section_id=VALUES(section_id), sort=VALUES(sort)',
                      [$c['id'], $c['name'], ar_norm($c['name']), $c['email'], $sec, $order[$sec]]);
                    $report['done']++;
                }
            }
            db()->commit();

            audit('import.run', $kind, '', '', $report['done'] . ' rows'
                  . ($wipe && $kind === 'students' ? ' (replaced)' : ''));

        } catch (Throwable $e) {
            db()->rollBack();
            error_log('[marking] import: ' . $e->getMessage());
            $report['errors'][] = L('imp.errStopped', ['v' => $e->getMessage()]);
            $report['errorCount'] = count($report['errors']);
            $report['done'] = 0;
        }
    }
}

/** Same unambiguous alphabet used by the user administration screen. */
function generated_password(int $len = 12): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, $max)];
    return $out;
}

$counts = [
    'imp.statTas'      => (int)one('SELECT COUNT(*) n FROM users WHERE role = "ta"')['n'],
    'imp.statSections' => (int)one('SELECT COUNT(*) n FROM sections')['n'],
    'imp.statStudents' => (int)one('SELECT COUNT(*) n FROM students')['n'],
    'imp.statGrades'   => (int)one('SELECT COUNT(*) n FROM marks')['n'],
];

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$LANG = lang();
?>
<!doctype html>
<html lang="<?= $h($LANG) ?>" dir="<?= $LANG === 'ar' ? 'rtl' : 'ltr' ?>" data-server-lang="<?= $h($LANG) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex, nofollow">
<title>CSE014 · <?= $h(L('imp.title')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap">
<link rel="stylesheet" href="../assets/css/app.css">
<script src="../assets/js/i18n.js"></script>
<style>
  .shell{max-width:780px;padding-block:18px 44px}
  .csvbox{
    min-height:200px;font-family:var(--mono);font-size:.78rem;
    direction:ltr;text-align:left;white-space:pre;
  }
  .kinds{
    display:grid;grid-auto-flow:column;grid-auto-columns:1fr;gap:5px;
    background:var(--surface-2);padding:4px;border-radius:var(--radius-sm);
  }
  .kinds label{display:block;position:relative}
  .kinds input{position:absolute;opacity:0;width:0;height:0}
  .kinds span{
    display:block;text-align:center;min-height:40px;line-height:40px;
    border-radius:var(--radius-xs);font-weight:700;font-size:.88rem;
    color:var(--ink-2);cursor:pointer;
  }
  .kinds input:checked + span{background:var(--surface);color:var(--brand);box-shadow:var(--shadow-1)}
  .kinds input:focus-visible + span{outline:2px solid var(--brand);outline-offset:2px}
  code{
    font-family:var(--mono);font-size:.82rem;background:var(--surface-2);
    padding:2px 7px;border-radius:5px;direction:ltr;display:inline-block;
  }
  .made{
    font-family:var(--mono);font-size:.8rem;direction:ltr;text-align:left;line-height:1.8;
    background:var(--surface-2);border-radius:var(--radius-sm);padding:11px 13px;user-select:all;
  }
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar__in">
    <div class="row__main">
      <div class="topbar__title"><?= $h(L('imp.title')) ?></div>
      <div class="topbar__sub"><?= $h($me['name']) ?></div>
    </div>
    <a class="btn btn--ghost" href="../users.html"><?= $h(L('imp.users')) ?></a>
    <a class="btn btn--ghost" href="../admin.html"><?= $h(L('imp.dashboard')) ?></a>
    <button data-lang-switch></button>
  </div>
</header>

<main class="shell stack">

  <div class="stats">
    <?php foreach ($counts as $key => $v): ?>
      <div class="stat">
        <div class="stat__v"><?= $v ?></div>
        <div class="stat__k"><?= $h(L($key)) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($report): ?>
    <div class="banner <?= $report['errorCount'] ? 'banner--bad' : ($report['done'] ? 'banner--ok' : 'banner--info') ?>">
      <?php if ($report['errorCount']): ?>
        <?= $h(L('imp.problems', ['n' => $report['errorCount']])) ?>
      <?php elseif ($report['done']): ?>
        <?= $h(L('imp.recorded', ['n' => $report['done'], 'label' => L($report['labelKey'])])) ?>
      <?php else: ?>
        <?= $h(L('imp.ready', ['n' => $report['rows']])) ?>
      <?php endif; ?>
    </div>

    <?php if ($report['errors']): ?>
      <div class="card"><div class="list">
        <?php foreach ($report['errors'] as $e): ?>
          <div class="row"><div class="row__main">
            <div class="row__name" style="white-space:normal"><?= $h($e) ?></div>
          </div></div>
        <?php endforeach; ?>
      </div></div>
    <?php endif; ?>

    <?php if (!empty($report['notes'])): ?>
      <div class="card stack-sm">
        <?php foreach ($report['notes'] as $n): ?>
          <p class="small"><?= $h($n) ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($report['gone'])): ?>
      <div class="card stack-sm">
        <h2 style="font-size:1rem"><?= $h(L('imp.enrolGone', ['n' => count($report['gone'])])) ?></h2>
        <div class="made"><?= $h(implode("
", $report['gone'])) ?></div>
      </div>
    <?php endif; ?>

    <?php if ($report['made']): ?>
      <div class="card stack-sm">
        <h2 style="font-size:1rem"><?= $h(L('imp.newPasswords')) ?></h2>
        <p class="tiny faint"><?= $h(L('imp.onceOnly')) ?></p>
        <div class="made"><?= $h(implode("\n", $report['made'])) ?></div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <form class="card stack" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
    <div>
      <span class="label"><?= $h(L('imp.what')) ?></span>
      <div class="kinds">
        <?php foreach (KINDS as $k => [$labelKey, $sample, $cols]): ?>
          <label>
            <input type="radio" name="kind" value="<?= $h($k) ?>" <?= $kind === $k ? 'checked' : '' ?>>
            <span><?= $h(L($labelKey)) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="tiny faint" style="margin-top:9px">
        <?php if ($kind === 'enrol'): ?><?= $h(L('imp.enrolHint')) ?><?php else: ?><?= $h(L('imp.columns')) ?> <code><?= $h(KINDS[$kind][1]) ?></code><?php endif; ?>
        <?php if ($kind === 'sections'): ?><br><?= $h(L('imp.sectionsHint')) ?><?php endif; ?>
        <?php if ($kind === 'tas'): ?><br><?= $h(L('imp.tasHint')) ?><?php endif; ?>
        <?php if ($kind !== 'enrol'): ?><br><?= $h(L('imp.headerNote')) ?><?php endif; ?>
      </p>
    </div>

    <div>
      <label class="label" for="file"><?= $h(L('imp.upload')) ?></label>
      <input class="input" type="file" id="file" name="file" accept=".csv,text/csv">
    </div>

    <div>
      <label class="label" for="csv"><?= $h(L('imp.paste')) ?></label>
      <textarea class="textarea csvbox" id="csv" name="csv"
                placeholder="<?= $h(KINDS[$kind][1]) ?>"><?= $h($raw) ?></textarea>
    </div>

    <?php if ($kind === 'students'): ?>
      <label class="switchrow" for="wipe">
        <input type="checkbox" id="wipe" name="wipe" <?= $wipe ? 'checked' : '' ?>>
        <span><?= $h(L('imp.wipe')) ?></span>
      </label>
    <?php endif; ?>

    <div class="rowline" style="flex-wrap:wrap">
      <button class="btn" type="submit"><?= $h(L('imp.check')) ?></button>
      <button class="btn btn--primary" type="submit" name="commit" value="1"><?= $h(L('imp.run')) ?></button>
    </div>
  </form>

  <div class="banner banner--warn"><?= $h(L('imp.warnWipe')) ?></div>

</main>
</body>
</html>
