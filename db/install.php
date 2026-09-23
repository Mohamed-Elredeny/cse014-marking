<?php
/**
 * db/install.php — creates the Main TA account on a fresh installation.
 *
 *   php db/install.php <username> "<full name>" <password>
 *
 * If the account already exists, its name and password are updated.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }

require __DIR__ . '/../api/_bootstrap.php';

const MIN_PASSWORD = 8;

$username = strtolower(trim((string)($argv[1] ?? '')));
$name     = trim((string)($argv[2] ?? ''));
$password = (string)($argv[3] ?? '');

if ($username === '' || $name === '' || strlen($password) < MIN_PASSWORD) {
    fwrite(STDERR, "Usage: php db/install.php <username> \"<full name>\" <password>\n");
    fwrite(STDERR, 'The password must be at least ' . MIN_PASSWORD . " characters.\n");
    exit(1);
}

// Confirm the tables exist before touching anything.
try {
    db()->query('SELECT 1 FROM users LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "The tables are missing — run db/schema.sql first.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = one('SELECT id FROM users WHERE username = ?', [$username]);

if ($existing) {
    q('UPDATE users SET name = ?, password_hash = ?, role = "main", active = 1 WHERE id = ?',
      [$name, $hash, $existing['id']]);
    echo "Updated the account: $username\n";
} else {
    q('INSERT INTO users (username, name, password_hash, role) VALUES (?,?,?,"main")',
      [$username, $name, $hash]);
    echo "Created the account: $username\n";
}

/* The labs must exist before the marking screens will work. The rubric below
   is only a starting point — it is edited from the dashboard, under
   Term → Lab rubric. */
$n = (int)one('SELECT COUNT(*) n FROM labs')['n'];
if ($n === 0) {
    $default = [
        ['r1', 'يُترجم البرنامج ويعمل دون أخطاء', 3],
        ['r2', 'المخرجات صحيحة في جميع حالات الاختبار', 3],
        ['r3', 'اتُّبع الأسلوب المطلوب في المعمل', 2],
        ['r4', 'تسمية المتغيرات والتنسيق والتعليقات', 1],
        ['r5', 'أجاب عن سؤال المعيد وأظهر فهمًا لبرنامجه', 1],
    ];

    for ($i = 1; $i <= 12; $i++) {
        q('INSERT INTO labs (id, title) VALUES (?,?)', [$i, "Lab $i"]);
        foreach ($default as $s => $it) {
            q('INSERT INTO lab_rubric (lab_id, code, text, points, sort) VALUES (?,?,?,?,?)',
              [$i, $it[0], $it[1], $it[2], $s]);
        }
    }
    echo "Created 12 labs with a default rubric totalling 10 marks.\n";
}

if (!setting('schedule')) {
    set_setting('schedule', ['startDate' => gmdate('Y-m-d'), 'weekDays' => 7, 'auto' => true]);
}

echo "\nDone. Sign in as $username, then import sections and students\n";
echo "from tools/import.php.\n";
