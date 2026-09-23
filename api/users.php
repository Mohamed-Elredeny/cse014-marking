<?php
/** api/users.php?do=list|save|password|active|sections|loginLog */
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$me  = require_main();
$uid = (int)$me['id'];
$do  = $_GET['do'] ?? 'list';

/** Minimum length for a password set by hand. */
const PASSWORD_MIN = 8;

function user_row(array $u, array $secs, array $counts): array
{
    return [
        'id'        => (int)$u['id'],
        'username'  => $u['username'],
        'name'      => $u['name'],
        'email'     => $u['email'],
        'role'      => $u['role'],
        'active'    => (bool)$u['active'],
        'notify'    => (bool)$u['notify'],
        'lastLogin' => ms($u['last_login']),
        'sections'  => $secs[(int)$u['id']] ?? [],
        'marks'     => (int)($counts[(int)$u['id']] ?? 0),
    ];
}

switch ($do) {

    /* ------------------------------ the list ------------------------------ */
    case 'list': {
        $users = all('SELECT id, username, name, email, role, active, notify, last_login
                      FROM users ORDER BY role DESC, username');

        $secs = [];
        foreach (all('SELECT t.ta_id, s.id, s.name FROM section_tas t
                      JOIN sections s ON s.id = t.section_id
                      ORDER BY s.sort, s.id') as $r) {
            $secs[(int)$r['ta_id']][] = ['id' => $r['id'], 'name' => $r['name']];
        }

        $counts = array_column(
            all('SELECT ta_id, COUNT(*) n FROM marks GROUP BY ta_id'), 'n', 'ta_id');

        ok([
            'users'    => array_map(fn($u) => user_row($u, $secs, $counts), $users),
            'sections' => all('SELECT id, name, day, time FROM sections ORDER BY sort, id'),
            'meId'     => $uid,
        ]);
    }

    /* --------------------------- add or update --------------------------- */
    case 'save': {
        require_post();

        $id       = (int)inp('id', 0);
        $username = strtolower(trim((string)inp('username', '')));
        $name     = trim((string)inp('name', ''));
        $email    = trim((string)inp('email', ''));
        $role     = (string)inp('role', 'ta');
        $notify   = inp('notify', true) ? 1 : 0;

        if ($name === '') fail(L('err.nameRequired'));
        if (!in_array($role, ['ta', 'main'], true)) fail(L('err.badRole'));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail(L('err.badEmail'));

        if ($id) {
            $u = one('SELECT id, username, role FROM users WHERE id = ?', [$id]);
            if (!$u) fail(L('err.userNotFound'));

            // Do not let a Main TA lock themselves out of the dashboard.
            if ($id === $uid && $role !== 'main') fail(L('err.cantDemoteSelf'));
            if ($u['role'] === 'main' && $role !== 'main' && last_main($id)) {
                fail(L('err.needOneMain'));
            }

            q('UPDATE users SET name = ?, email = ?, role = ?, notify = ? WHERE id = ?',
              [$name, $email, $role, $notify, $id]);

            audit('user.update', (string)$id, 'user', $u['username'],
                  'role ' . $u['role'] . ' → ' . $role);

            ok(['id' => $id]);
        }

        if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) fail(L('err.badUsername'));
        if (one('SELECT id FROM users WHERE username = ?', [$username])) fail(L('err.usernameTaken'));

        $pw = (string)inp('password', '');
        if ($pw === '') $pw = generate_password();
        elseif (strlen($pw) < PASSWORD_MIN) fail(L('err.pwShort'));

        q('INSERT INTO users (username, name, email, role, notify, password_hash) VALUES (?,?,?,?,?,?)',
          [$username, $name, $email, $role, $notify, password_hash($pw, PASSWORD_DEFAULT)]);

        $newId = (int)db()->lastInsertId();
        audit('user.create', (string)$newId, 'user', $username, 'role ' . $role);

        ok(['id' => $newId, 'password' => $pw]);
    }

    /* ------------------------------ password ------------------------------ */
    case 'password': {
        require_post();

        $id = (int)want('id');
        $u = one('SELECT id, username FROM users WHERE id = ?', [$id]);
        if (!$u) fail(L('err.userNotFound'));

        $pw = (string)inp('password', '');
        if ($pw === '') $pw = generate_password();
        elseif (strlen($pw) < PASSWORD_MIN) fail(L('err.pwShort'));

        q('UPDATE users SET password_hash = ? WHERE id = ?',
          [password_hash($pw, PASSWORD_DEFAULT), $id]);

        audit('user.password', (string)$id, 'user', $u['username']);

        ok(['password' => $pw]);
    }

    /* -------------------------- activate / suspend -------------------------- */
    case 'active': {
        require_post();

        $id     = (int)want('id');
        $active = inp('active', true) ? 1 : 0;

        if ($id === $uid && !$active) fail(L('err.cantSuspendSelf'));

        $u = one('SELECT username, role FROM users WHERE id = ?', [$id]);
        if (!$u) fail(L('err.userNotFound'));
        if (!$active && $u['role'] === 'main' && last_main($id)) fail(L('err.needOneMainActive'));

        q('UPDATE users SET active = ? WHERE id = ?', [$active, $id]);

        audit($active ? 'user.activate' : 'user.deactivate', (string)$id, 'user', $u['username']);

        ok();
    }

    /* ------------------------ the assistant's sections ------------------------ */
    case 'sections': {
        require_post();

        $id  = (int)want('id');
        $ids = inp('sections', []);

        $u = one('SELECT id, username FROM users WHERE id = ?', [$id]);
        if (!$u) fail(L('err.userNotFound'));
        if (!is_array($ids)) fail(L('err.badFormat'));

        $valid = array_column(all('SELECT id FROM sections'), 'id');
        $ids = array_values(array_unique(array_filter($ids, fn($s) => in_array($s, $valid, true))));

        db()->beginTransaction();
        try {
            q('DELETE FROM section_tas WHERE ta_id = ?', [$id]);
            // Order within a section drives the distribution — append at the end.
            $ins = db()->prepare('INSERT INTO section_tas (section_id, ta_id, sort)
                                  SELECT ?, ?, COALESCE(MAX(sort) + 1, 0) FROM section_tas WHERE section_id = ?');
            foreach ($ids as $s) $ins->execute([$s, $id, $s]);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            error_log('[marking] user sections: ' . $e->getMessage());
            fail(L('err.saveFailed'), 500);
        }

        audit('user.sections', (string)$id, 'user', $u['username'],
              $ids ? implode(', ', $ids) : '—');

        ok(['count' => count($ids)]);
    }

    /* ----------------------------- sign-in log ----------------------------- */
    case 'loginLog': {
        $limit = max(1, min(200, (int)inp('limit', 40)));
        $only  = (string)inp('only', '');          // fail = failed attempts only

        $where = $only === 'fail' ? 'WHERE l.ok = 0' : '';
        $rows = all("SELECT l.*, u.name FROM login_log l
                     LEFT JOIN users u ON u.id = l.user_id
                     $where ORDER BY l.id DESC LIMIT $limit");

        ok(['items' => array_map(fn($r) => [
            'id'       => (int)$r['id'],
            'username' => $r['username'],
            'name'     => $r['name'],
            'ok'       => (bool)$r['ok'],
            'ip'       => $r['ip'],
            'agent'    => short_agent($r['agent']),
            'at'       => ms($r['at']),
        ], $rows)]);
    }
}

fail(L('err.unknownAction'), 404);


/* ------------------------------------------------------------------ helpers */

function last_main(int $exceptId): bool
{
    $n = (int)one('SELECT COUNT(*) n FROM users WHERE role = "main" AND active = 1 AND id <> ?',
                  [$exceptId])['n'];
    return $n === 0;
}

/**
 * A temporary password that is read aloud or copied once.
 * The alphabet omits characters that are easy to confuse (0/O, 1/l/I).
 */
function generate_password(int $len = 12): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, $max)];
    return $out;
}

/** Condenses the user-agent string into something readable. */
function short_agent(string $a): string
{
    if ($a === '') return '—';

    $os = L('dev.unknown');
    foreach (['Android' => 'dev.android', 'iPhone' => 'dev.iphone', 'iPad' => 'dev.ipad',
              'Windows' => 'dev.windows', 'Macintosh' => 'dev.mac', 'Linux' => 'dev.linux'] as $needle => $key) {
        if (stripos($a, $needle) !== false) { $os = L($key); break; }
    }

    $browser = '';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome',
              'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $label) {
        if (stripos($a, $needle) !== false) { $browser = $label; break; }
    }

    return trim($os . ($browser ? ' · ' . $browser : ''));
}
