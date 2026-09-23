<?php
/** api/auth.php?do=login|logout|me */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

/** How far back the throttle looks, in minutes. */
const THROTTLE_WINDOW_MIN = 15;

/** Failures allowed for one username from one address before it is refused. */
const THROTTLE_PER_ACCOUNT = 8;

/**
 * Failures allowed from one address across all accounts.
 * Teaching assistants often share a single NAT address in the lab, so this
 * ceiling is deliberately generous; the per-account limit does the real work.
 */
const THROTTLE_PER_IP = 40;

$do = $_GET['do'] ?? 'me';

switch ($do) {

    case 'login': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(L('err.postOnly'), 405);

        $username = strtolower(trim((string)inp('username', '')));
        $password = (string)inp('password', '');
        if ($username === '' || $password === '') fail(L('err.credsRequired'));

        $ip = client_ip();
        throttle_check($username, $ip);

        $u = one('SELECT id, username, name, role, password_hash, active
                  FROM users WHERE username = ?', [$username]);

        if (!$u || !$u['active'] || !password_verify($password, $u['password_hash'])) {
            log_login($username, $u['id'] ?? null, false, $ip);
            fail(L('err.badCreds'), 401);
        }

        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];

        // Record the language they are actually reading, so their notification
        // emails are composed in it without any administration.
        q('UPDATE users SET last_login = NOW(), lang = ? WHERE id = ?', [lang(), $u['id']]);
        log_login($username, (int)$u['id'], true, $ip);

        ok(['user' => [
            'id'   => (int)$u['id'],
            'name' => $u['name'],
            'role' => $u['role'],
            'csrf' => csrf_token(),
        ]]);
    }

    case 'logout': {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                      $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        ok();
    }

    case 'me': {
        $u = me();
        if (!$u) json_out(['ok' => true, 'user' => null]);
        ok(['user' => [
            'id'   => (int)$u['id'],
            'name' => $u['name'],
            'role' => $u['role'],
            'csrf' => csrf_token(),
        ]]);
    }
}

fail(L('err.unknownAction'), 404);


/* ------------------------------------------------------------------ helpers */

/**
 * Refuses the attempt when recent failures exceed either limit.
 *
 * The counts come from `login_log`, so the limits survive a cleared cookie or
 * a fresh session — which the previous session-scoped counter did not.
 */
function throttle_check(string $username, string $ip): void
{
    $cutoff = gmdate('Y-m-d H:i:s', time() - THROTTLE_WINDOW_MIN * 60);

    $perAccount = (int)one(
        'SELECT COUNT(*) n FROM login_log
         WHERE ok = 0 AND username = ? AND ip = ? AND at > ?',
        [mb_substr($username, 0, 50), $ip, $cutoff]
    )['n'];

    if ($perAccount >= THROTTLE_PER_ACCOUNT) {
        fail(L('err.tooManyTries', ['mins' => THROTTLE_WINDOW_MIN]), 429);
    }

    if ($ip === '') return;

    $perIp = (int)one(
        'SELECT COUNT(*) n FROM login_log WHERE ok = 0 AND ip = ? AND at > ?',
        [$ip, $cutoff]
    )['n'];

    if ($perIp >= THROTTLE_PER_IP) {
        fail(L('err.tooManyTries', ['mins' => THROTTLE_WINDOW_MIN]), 429);
    }
}

/** Who signed in, when, and from where — failed attempts included. */
function log_login(string $username, ?int $userId, bool $okFlag, string $ip): void
{
    try {
        q('INSERT INTO login_log (user_id, username, ok, ip, agent) VALUES (?,?,?,?,?)',
          [$userId, mb_substr($username, 0, 50), $okFlag ? 1 : 0, $ip,
           mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    } catch (Throwable $e) {
        // Logging must never block a sign-in.
        error_log('[marking] login_log: ' . $e->getMessage());
    }
}
