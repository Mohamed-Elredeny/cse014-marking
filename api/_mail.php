<?php
/**
 * Marking — outgoing email
 *  - mail_queue() only enqueues, so saving a grade never waits on a remote
 *    mail server.
 *  - mail_flush() drains the queue and is called from tools/cron.php.
 *
 * Two methods: SMTP (recommended) or PHP's mail() function.
 *
 * Each message is composed in the recipient's own language. `users.lang` is
 * set automatically from the interface language each time they sign in, so
 * the preference needs no administration.
 */
declare(strict_types=1);

function mail_config(): array
{
    global $CONFIG;
    $m = $CONFIG['mail'] ?? [];
    return [
        'enabled'  => (bool)($m['enabled'] ?? false),
        'method'   => $m['method']   ?? 'mail',        // mail | smtp
        'from'     => $m['from']     ?? 'noreply@localhost',
        'fromName' => $m['fromName'] ?? 'Marking',
        'host'     => $m['host']     ?? '',
        'port'     => (int)($m['port'] ?? 587),
        'user'     => $m['user']     ?? '',
        'pass'     => $m['pass']     ?? '',
        'secure'   => $m['secure']   ?? 'tls',         // tls | ssl | none
        'siteUrl'  => $m['siteUrl']  ?? '',
    ];
}

/** Enqueues a message. Returns false if notifications are off or the address is unusable. */
function mail_queue(?string $email, string $name, string $subject, string $bodyHtml): bool
{
    $cfg = mail_config();
    if (!$cfg['enabled'] || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    q('INSERT INTO mail_queue (to_email, to_name, subject, body) VALUES (?,?,?,?)',
      [$email, $name, $subject, $bodyHtml]);
    return true;
}

/** A plain template that renders in any mail client, in either direction. */
function mail_template(string $lang, string $title, string $bodyHtml,
                       string $linkText = '', string $linkUrl = ''): string
{
    $dir = $lang === 'ar' ? 'rtl' : 'ltr';
    $align = $lang === 'ar' ? 'right' : 'left';

    $btn = '';
    if ($linkText && $linkUrl) {
        $btn = '<p style="margin:22px 0 0">
                  <a href="' . htmlspecialchars($linkUrl, ENT_QUOTES) . '"
                     style="background:#0C6A5F;color:#fff;text-decoration:none;
                            padding:11px 20px;border-radius:8px;font-weight:700;display:inline-block">'
                . htmlspecialchars($linkText, ENT_QUOTES) . '</a>
                </p>';
    }

    return '<!doctype html><html dir="' . $dir . '" lang="' . $lang . '">'
      . '<body style="margin:0;background:#F2F6F5;font-family:Tahoma,Arial,sans-serif;color:#0E1918">
      <div style="max-width:560px;margin:0 auto;padding:24px 16px">
        <div style="background:#fff;border:1px solid #DCE5E3;border-radius:14px;padding:22px;text-align:' . $align . '">
          <h1 style="margin:0 0 14px;font-size:18px">' . htmlspecialchars($title, ENT_QUOTES) . '</h1>
          <div style="font-size:14px;line-height:1.8">' . $bodyHtml . '</div>' . $btn . '
        </div>
        <p style="font-size:12px;color:#77857F;text-align:center;margin-top:14px">'
          . htmlspecialchars(L('mail.footer', [], $lang)) . '
        </p>
      </div></body></html>';
}

/**
 * Drains the queue.
 * Returns ['sent' => n, 'failed' => n].
 */
function mail_flush(int $limit = 25): array
{
    $cfg = mail_config();
    if (!$cfg['enabled']) return ['sent' => 0, 'failed' => 0, 'skipped' => 'notifications disabled'];

    $rows = all('SELECT * FROM mail_queue WHERE state = "pending" AND tries < 5
                 ORDER BY id LIMIT ' . max(1, min(100, $limit)));

    $sent = $failed = 0;
    foreach ($rows as $m) {
        try {
            if ($cfg['method'] === 'smtp') {
                smtp_send($cfg, $m['to_email'], $m['to_name'], $m['subject'], $m['body']);
            } else {
                php_mail_send($cfg, $m['to_email'], $m['to_name'], $m['subject'], $m['body']);
            }

            q('UPDATE mail_queue SET state = "sent", sent_at = NOW(), tries = tries + 1 WHERE id = ?',
              [$m['id']]);
            $sent++;
        } catch (Throwable $e) {
            $tries = (int)$m['tries'] + 1;
            q('UPDATE mail_queue SET tries = ?, last_error = ?, state = ? WHERE id = ?',
              [$tries, mb_substr($e->getMessage(), 0, 480), $tries >= 5 ? 'failed' : 'pending', $m['id']]);
            $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}

/* ------------------------------------------------------------------- MIME */
function mime_header(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function php_mail_send(array $cfg, string $to, string $toName, string $subject, string $html): void
{
    $headers = implode("\r\n", [
        'From: ' . mime_header($cfg['fromName']) . ' <' . $cfg['from'] . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ]);
    $body = chunk_split(base64_encode($html));
    if (!@mail($to, mime_header($subject), $body, $headers)) {
        throw new RuntimeException('PHP mail() failed — consider using SMTP.');
    }
}

/* ------------------------------------------------------------------- SMTP */
function smtp_send(array $cfg, string $to, string $toName, string $subject, string $html): void
{
    if (!$cfg['host']) throw new RuntimeException('SMTP configuration is incomplete.');

    $host = ($cfg['secure'] === 'ssl' ? 'ssl://' : '') . $cfg['host'];
    $fp = @stream_socket_client("$host:{$cfg['port']}", $errno, $errstr, 20);
    if (!$fp) throw new RuntimeException("Could not connect to SMTP: $errstr");
    stream_set_timeout($fp, 20);

    $read = function () use ($fp) {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $out;
    };

    $say = function (string $cmd, array $expect) use ($fp, $read) {
        if ($cmd !== '') fwrite($fp, $cmd . "\r\n");
        $res = $read();
        $code = (int)substr(ltrim($res), 0, 3);
        if (!in_array($code, $expect, true)) {
            throw new RuntimeException('SMTP ' . $code . ': ' . trim(mb_substr($res, 0, 160)));
        }
        return $res;
    };

    $ehlo = 'EHLO ' . (parse_url($cfg['siteUrl'] ?: 'http://localhost', PHP_URL_HOST) ?: 'localhost');

    try {
        $say('', [220]);
        $say($ehlo, [250]);

        if ($cfg['secure'] === 'tls') {
            $say('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS negotiation failed.');
            }
            $say($ehlo, [250]);
        }

        if ($cfg['user'] !== '') {
            $say('AUTH LOGIN', [334]);
            $say(base64_encode($cfg['user']), [334]);
            $say(base64_encode($cfg['pass']), [235]);
        }

        $say('MAIL FROM:<' . $cfg['from'] . '>', [250]);
        $say('RCPT TO:<' . $to . '>', [250, 251]);
        $say('DATA', [354]);

        $headers = implode("\r\n", [
            'From: ' . mime_header($cfg['fromName']) . ' <' . $cfg['from'] . '>',
            'To: ' . ($toName ? mime_header($toName) . " <$to>" : $to),
            'Subject: ' . mime_header($subject),
            'Date: ' . date('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ]);

        // A leading dot on any line must be doubled.
        $body = preg_replace('/^\./m', '..', chunk_split(base64_encode($html)));
        fwrite($fp, $headers . "\r\n\r\n" . $body . "\r\n.\r\n");
        $say('', [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        @fclose($fp);
    }
}

/* ----------------------------------------------------------------- events */

/** A recipient's preferred language, defaulting to Arabic. */
function recipient_lang(?string $v): string
{
    return in_array($v, LANG_SUPPORTED, true) ? $v : LANG_FALLBACK;
}

/** One labelled line of the message body. */
function mail_line(string $label, string $value): string
{
    return '<p style="margin:0 0 6px"><span style="color:#77857F">'
         . htmlspecialchars($label) . ':</span> <strong>'
         . htmlspecialchars($value) . '</strong></p>';
}

/** A new change request — notify every Main TA. */
function notify_new_request(array $req, string $taName, string $studentName): void
{
    $cfg = mail_config();
    if (!$cfg['enabled']) return;

    $labId = (int)$req['lab_id'];
    $prev  = $req['prev_grade'] === null ? '—' : (int)$req['prev_grade'];

    foreach (all('SELECT name, email, lang FROM users
                  WHERE role = "main" AND active = 1 AND notify = 1') as $u) {

        $lg  = recipient_lang($u['lang'] ?? null);
        $lab = lab_label($labId, $lg);

        $body = '<p>' . htmlspecialchars(L('mail.greeting', ['name' => $u['name']], $lg)) . '</p>'
              . mail_line(L('mail.fromTa',  [], $lg), $taName)
              . mail_line(L('mail.student', [], $lg), $studentName)
              . mail_line(L('mail.change',  [], $lg), $lab . ': ' . $prev . ' → ' . (int)$req['grade'])
              . '<p style="margin:14px 0 4px;color:#77857F">' . htmlspecialchars(L('mail.reason', [], $lg)) . '</p>'
              . '<p style="background:#F8EBD4;color:#8F5406;padding:9px 12px;border-radius:7px;margin:0">'
              . htmlspecialchars($req['reason']) . '</p>';

        mail_queue(
            $u['email'], $u['name'],
            L('mail.newRequestSubj', ['lab' => $lab], $lg),
            mail_template($lg, L('mail.newRequestHead', [], $lg), $body,
                          L('mail.openDashboard', [], $lg),
                          rtrim($cfg['siteUrl'], '/') . '/admin.html')
        );
    }
}

/** A decision was made — notify the assistant who asked. */
function notify_request_decided(array $req, string $studentName): void
{
    $cfg = mail_config();
    if (!$cfg['enabled']) return;

    $ta = one('SELECT name, email, lang FROM users WHERE id = ? AND notify = 1', [$req['ta_id']]);
    if (!$ta) return;

    $lg  = recipient_lang($ta['lang'] ?? null);
    $lab = lab_label((int)$req['lab_id'], $lg);
    $approved = $req['state'] === 'approved';

    $body = '<p>' . htmlspecialchars(L('mail.greeting', ['name' => $ta['name']], $lg)) . '</p>'
          . mail_line(L('mail.student',  [], $lg), $studentName)
          . mail_line(L('mail.section',  [], $lg), $lab)
          . '<p style="margin:10px 0 0"><span style="color:#77857F">'
          . htmlspecialchars(L('mail.decision', [], $lg)) . ':</span> '
          . ($approved
              ? '<strong style="color:#1A7349">' . htmlspecialchars(L('mail.approved', [], $lg)) . '</strong>'
              : '<strong style="color:#9E2F27">' . htmlspecialchars(L('mail.rejected', [], $lg)) . '</strong>')
          . '</p>';

    if ($approved) {
        $body .= mail_line(L('mail.change', [], $lg), (string)(int)$req['grade']);
    } elseif ($req['decision_note'] !== '') {
        $body .= '<p style="margin:14px 0 4px;color:#77857F">'
               . htmlspecialchars(L('mail.rejectReason', [], $lg)) . '</p>'
               . '<p style="background:#F7E1DE;color:#9E2F27;padding:9px 12px;border-radius:7px;margin:0">'
               . htmlspecialchars($req['decision_note']) . '</p>';
    }

    mail_queue(
        $ta['email'], $ta['name'],
        L($approved ? 'mail.decidedSubjOk' : 'mail.decidedSubjNo', ['lab' => $lab], $lg),
        mail_template($lg, L($approved ? 'mail.decidedSubjOk' : 'mail.decidedSubjNo', ['lab' => $lab], $lg),
                      $body, L('mail.openDashboard', [], $lg),
                      rtrim($cfg['siteUrl'], '/') . '/sections.html')
    );
}
