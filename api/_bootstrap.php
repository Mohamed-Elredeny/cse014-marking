<?php
/**
 * Marking — shared foundation for every endpoint
 *  - PDO connection
 *  - session and authorisation
 *  - uniform JSON responses
 *  - weekly schedule and student distribution
 *  - administrative audit trail
 */

declare(strict_types=1);

// The message catalogue has no dependencies, so it loads first and can
// describe a missing configuration file in the reader's language.
require __DIR__ . '/_lang.php';

// ------------------------------------------------------------- configuration
$CONFIG_PATH = __DIR__ . '/../config.php';
if (!file_exists($CONFIG_PATH)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['ok' => false, 'error' => L('err.noConfig')], JSON_UNESCAPED_UNICODE));
}
$CONFIG = require $CONFIG_PATH;

// Responses are JSON, so warnings must go to the log rather than the browser.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../db/php-errors.log');

// Debug output is opt-in: an absent setting must never expose internals.
$DEBUG = (bool)($CONFIG['debug'] ?? false);

// ------------------------------------------------------------------- session
if (PHP_SAPI !== 'cli') {

    security_headers();

    // Some hosts hand out a save_path that does not exist — fall back to tmp.
    $sp = (string)ini_get('session.save_path');
    $sp = preg_replace('/^\d+;/', '', $sp);
    if ($sp === '' || !is_dir($sp) || !is_writable($sp)) {
        $tmp = sys_get_temp_dir();
        if (is_dir($tmp) && is_writable($tmp)) session_save_path($tmp);
    }

    // A lab session runs for hours; PHP's 24-minute default would sign the
    // teaching assistant out mid-marking.
    $SESSION_HOURS = (int)($CONFIG['session_hours'] ?? 10);
    @ini_set('session.gc_maxlifetime', (string)($SESSION_HOURS * 3600));
    @ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        'lifetime' => $SESSION_HOURS * 3600,
        // Scoped to this installation, not the whole domain.
        'path'     => app_base_path(),
        'httponly' => true,
        'secure'   => (bool)($CONFIG['secure_cookies'] ?? false),
        'samesite' => 'Lax',
    ]);
    session_name('MARKINGSID');
    session_start();
}

/**
 * The URL path this installation is served from, with a trailing slash.
 *
 * The application is often deployed inside a larger site — for example
 * /courses/cse014/ under a faculty domain. Cookies are scoped to this path so
 * that the session never travels to the parent site or to a sibling
 * application, and so a sibling can never overwrite ours.
 *
 * Derived from the application directory relative to the document root, which
 * is correct whether the entry point sits in api/ or tools/. A reverse proxy
 * that rewrites paths can override it with 'base_path' in config.php.
 */
function app_base_path(): string
{
    global $CONFIG;
    static $base = null;
    if ($base !== null) return $base;

    $set = trim((string)($CONFIG['base_path'] ?? ''));
    if ($set !== '') return $base = '/' . trim($set, '/') . '/';

    $root = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $app  = realpath(dirname(__DIR__));          // api/ -> the application root

    if ($root && $app && str_starts_with($app, $root)) {
        $rel = trim(str_replace('\\', '/', substr($app, strlen($root))), '/');
        return $base = $rel === '' ? '/' : '/' . $rel . '/';
    }

    // Fallback: infer from the request when the document root is unhelpful.
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    $dir = preg_replace('#/(api|tools|db)$#', '', $dir);
    return $base = rtrim($dir, '/') . '/';
}

/**
 * Baseline response headers.
 *
 * The pages use inline <script> blocks and Google Fonts, so the policy has to
 * permit both; everything else is locked to this origin.
 */
function security_headers(): void
{
    if (headers_sent()) return;

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "form-action 'self'; "
        . "base-uri 'self'; "
        . "frame-ancestors 'none'"
    );
}

// ------------------------------------------------------------------ response
function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(array $data = []): never { json_out(['ok' => true] + $data); }

function fail(string $msg, int $code = 400, array $extra = []): never
{
    json_out(['ok' => false, 'error' => $msg] + $extra, $code);
}

/** Any unexpected exception still returns clean JSON. */
set_exception_handler(function (Throwable $e) {
    global $DEBUG;
    error_log('[marking] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => $DEBUG ? $e->getMessage() : L('err.server'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// ------------------------------------------------------------------ database
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    global $CONFIG, $DEBUG;
    $c = $CONFIG['db'];
    $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";

    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[marking] db: ' . $e->getMessage());
        fail($DEBUG ? 'DB: ' . $e->getMessage() : L('err.db'), 500);
    }

    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function one(string $sql, array $args = []): ?array
{
    $r = q($sql, $args)->fetch();
    return $r === false ? null : $r;
}

function all(string $sql, array $args = []): array { return q($sql, $args)->fetchAll(); }

/** Placeholders for a known count: in_list(3) → "?,?,?" */
function in_list(int $n): string { return implode(',', array_fill(0, max($n, 1), '?')); }

// -------------------------------------------------------------------- input
function body(): array
{
    static $b = null;
    if ($b !== null) return $b;
    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);
    $b = is_array($j) ? $j : $_POST;
    return $b;
}

function inp(string $k, $def = null)
{
    $b = body();
    if (array_key_exists($k, $b)) return $b[$k];
    return $_GET[$k] ?? $def;
}

function want(string $k): string
{
    $v = inp($k);
    if ($v === null || $v === '') fail(L('err.missing', ['field' => $k]));
    return (string)$v;
}

/** The caller's address, honouring a single proxy hop. */
function client_ip(): string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return mb_substr(trim(explode(',', (string)$ip)[0]), 0, 45);
}

// ------------------------------------------------------------ authorisation
function me(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    static $u = null;
    if ($u === null) {
        $u = one('SELECT id, username, name, role FROM users WHERE id = ? AND active = 1',
            [$_SESSION['uid']]) ?: null;
    }
    return $u;
}

function require_login(): array
{
    $u = me();
    if (!$u) fail(L('err.needLogin'), 401);
    return $u;
}

function require_main(): array
{
    $u = require_login();
    if ($u['role'] !== 'main') fail(L('err.mainOnly'), 403);
    return $u;
}

/** Every POST carries a token in the X-CSRF header. */
function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(L('err.postOnly'), 405);
    $sent = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!$sent || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        fail(L('err.sessionExpired'), 419);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

// ------------------------------------------------------------- audit trail
/**
 * Records an administrative action.
 *
 * Grade changes have their own trail in `marks.reason`; this covers everything
 * else a Main TA can do — opening and closing labs, editing rubrics, moving
 * students between assistants, and account administration.
 *
 * `actor_name` is denormalised so the entry stays readable even if the account
 * is later removed. `target` keeps the machine identifier and `target_type`
 * lets the interface render it in the reader's language.
 */
function audit(string $action, string $target = '', string $targetType = '',
               string $targetLabel = '', string $detail = ''): void
{
    $u = me();
    try {
        q('INSERT INTO audit_log
             (actor_id, actor_name, action, target, target_type, target_label, detail, ip)
           VALUES (?,?,?,?,?,?,?,?)',
          [
              $u ? (int)$u['id'] : 0,
              $u ? mb_substr($u['name'], 0, 120) : '—',
              mb_substr($action, 0, 40),
              mb_substr($target, 0, 60),
              mb_substr($targetType, 0, 20),
              mb_substr($targetLabel, 0, 120),
              mb_substr($detail, 0, 255),
              client_ip(),
          ]);
    } catch (Throwable $e) {
        // The trail must never block the action it is recording.
        error_log('[marking] audit: ' . $e->getMessage());
    }
}

// --------------------------------------------------------- stored settings
function setting(string $k, $default = null)
{
    $r = one('SELECT v FROM settings WHERE k = ?', [$k]);
    if (!$r) return $default;
    $j = json_decode($r['v'], true);
    return $j === null ? $r['v'] : $j;
}

function set_setting(string $k, $v): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
        [$k, is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)]);
}

// ---------------------------------------------------------- weekly schedule
function schedule_get(): array
{
    $s = setting('schedule', []);
    return [
        'startDate' => $s['startDate'] ?? gmdate('Y-m-d'),
        'weekDays'  => (int)($s['weekDays'] ?? 7),
        'auto'      => (bool)($s['auto'] ?? true),
    ];
}

/** The lab's window, in seconds (UTC). */
function lab_window(array $sch, int $labId): array
{
    $from = strtotime($sch['startDate'] . ' 00:00:00 UTC') + ($labId - 1) * 7 * 86400;
    return ['from' => $from, 'to' => $from + $sch['weekDays'] * 86400];
}

function lab_state(array $sch, array $lab): string
{
    if (!empty($lab['state_override'])) return $lab['state_override'];
    if (!$sch['auto']) return 'closed';
    $w = lab_window($sch, (int)$lab['id']);
    $now = time();
    return ($now >= $w['from'] && $now < $w['to']) ? 'open' : 'closed';
}

/** Every lab with its rubric and derived state. */
function labs_all(): array
{
    $sch  = schedule_get();
    $labs = all('SELECT id, title, state_override FROM labs ORDER BY id');
    $rub  = all('SELECT lab_id, code, text, points FROM lab_rubric ORDER BY lab_id, sort, id');

    $byLab = [];
    foreach ($rub as $r) {
        $byLab[(int)$r['lab_id']][] = [
            'id'     => $r['code'],
            'text'   => $r['text'],
            'points' => (int)$r['points'],
        ];
    }

    $out = [];
    foreach ($labs as $l) {
        $id = (int)$l['id'];
        $items = $byLab[$id] ?? [];
        $max = array_sum(array_column($items, 'points'));
        $w = lab_window($sch, $id);
        $out[] = [
            'id'         => $id,
            'title'      => $l['title'],
            'rubric'     => $items,
            'max'        => $max ?: 10,
            'state'      => lab_state($sch, $l),
            'overridden' => $l['state_override'] !== null,
            'window'     => ['from' => $w['from'] * 1000, 'to' => $w['to'] * 1000],
        ];
    }
    return $out;
}

function lab_get(int $id): ?array
{
    foreach (labs_all() as $l) if ($l['id'] === $id) return $l;
    return null;
}

// ------------------------------------------------------ student distribution
/**
 * Consecutive blocks that rotate each lab, so a student does not meet the same
 * assistant every week. Returns [student_id => ta_id] for a whole section.
 */
function section_assignment(int $labId, string $sectionId): array
{
    $tas = array_column(
        all('SELECT ta_id FROM section_tas WHERE section_id = ? ORDER BY sort, ta_id', [$sectionId]),
        'ta_id'
    );
    $roster = array_column(
        all('SELECT id FROM students WHERE section_id = ? ORDER BY sort, id', [$sectionId]),
        'id'
    );

    $map = [];
    $n = count($tas);
    if ($n && $roster) {
        $per = (int)ceil(count($roster) / $n);
        foreach ($roster as $i => $sid) {
            $block = intdiv($i, $per);
            $map[$sid] = (int)$tas[($block + $labId - 1) % $n];
        }
    }

    // Manual overrides win over the computed distribution.
    foreach (all('SELECT a.student_id, a.ta_id FROM lab_assignments a
                  JOIN students s ON s.id = a.student_id
                  WHERE a.lab_id = ? AND s.section_id = ?', [$labId, $sectionId]) as $o) {
        $map[$o['student_id']] = (int)$o['ta_id'];
    }
    return $map;
}

/** The assistant responsible for one student. */
function student_assigned_ta(int $labId, string $studentId, string $sectionId): ?int
{
    $map = section_assignment($labId, $sectionId);
    return $map[$studentId] ?? null;
}

// ------------------------------------------------------------ presentation
function ms($dt): ?int { return $dt ? strtotime($dt . ' UTC') * 1000 : null; }

function user_names(array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) return [];
    $rows = all('SELECT id, name FROM users WHERE id IN (' . in_list(count($ids)) . ')', $ids);
    return array_column($rows, 'name', 'id');
}

/** Arabic normalisation for search — mirrors students.name_norm. */
function ar_norm(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
                      'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);
}

function mark_row(array $m, array $names = []): array
{
    return [
        'id'         => (int)$m['id'],
        'studentId'  => $m['student_id'],
        'labId'      => (int)$m['lab_id'],
        'sectionId'  => $m['section_id'],
        'status'     => $m['status'],
        'grade'      => (int)$m['grade'],
        'items'      => json_decode($m['items'] ?: '[]', true) ?: [],
        'note'       => $m['note'] ?? '',
        'reason'     => $m['reason'] ?? '',
        'afterClose' => (bool)($m['after_close'] ?? false),
        'byMain'     => (bool)($m['by_main'] ?? false),
        'viaRequest' => $m['via_request'] ? (int)$m['via_request'] : null,
        'taId'       => (int)$m['ta_id'],
        'taName'     => $names[(int)$m['ta_id']] ?? '—',
        'at'         => ms($m['updated_at'] ?? $m['created_at']),
    ];
}
