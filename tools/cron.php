<?php
/**
 * tools/cron.php — scheduled maintenance
 *   1) delivers queued email
 *   2) writes a database backup
 *
 * From the command line (preferred):
 *     php tools/cron.php
 *
 * From the host's cron, every five minutes:
 *     * / 5 * * * *  php /home/user/public_html/marking/tools/cron.php
 *
 * Over HTTP, where no CLI cron exists (set cron.token in config.php):
 *     https://.../tools/cron.php?token=...
 */
declare(strict_types=1);

require __DIR__ . '/../api/_bootstrap.php';
require __DIR__ . '/../api/_mail.php';

$cli = PHP_SAPI === 'cli';

if (!$cli) {
    $token = (string)($CONFIG['cron']['token'] ?? '');
    if ($token === '' || !hash_equals($token, (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

set_time_limit(120);
$say = function (string $m) { echo $m . "\n"; };

/* ------------------------------------------------------------------ email */
try {
    $r = mail_flush(30);
    if (isset($r['skipped'])) $say('Email: skipped (' . $r['skipped'] . ').');
    else                      $say("Email: {$r['sent']} sent, {$r['failed']} failed.");
} catch (Throwable $e) {
    $say('Email: stopped — ' . $e->getMessage());
}

/* ----------------------------------------------------------------- backup */
$bk = $CONFIG['backup'] ?? [];

if (!($bk['enabled'] ?? false)) {
    $say('Backup: disabled.');
} else {
    $dir   = $bk['dir'] ?? (__DIR__ . '/../db/backups');
    $keep  = max(1, (int)($bk['keep'] ?? 14));
    $every = max(1, (int)($bk['every_hours'] ?? 24));
    $last  = (int)(setting('backup_at', 0) ?: 0);

    if (time() - $last < $every * 3600) {
        $say('Backup: not due yet (last one ' . round((time() - $last) / 3600, 1) . ' h ago).');
    } elseif (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        $say('Backup: cannot create the directory ' . $dir);
    } else {
        try {
            $file = rtrim($dir, '/\\') . '/marking-' . gmdate('Ymd-His') . '.sql';
            [$n, $file] = dump_database($file);
            set_setting('backup_at', time());
            $say('Backup: ' . basename($file) . " ({$n} rows, " . round(filesize($file) / 1024) . ' KB)');

            // Retire the oldest copies beyond the retention count.
            $files = glob(rtrim($dir, '/\\') . '/marking-*.sql*') ?: [];
            sort($files);
            $extra = max(0, count($files) - $keep);
            for ($i = 0; $i < $extra; $i++) @unlink($files[$i]);
            if ($extra) $say("Backup: removed $extra older " . ($extra === 1 ? 'copy.' : 'copies.'));
        } catch (Throwable $e) {
            $say('Backup: failed — ' . $e->getMessage());
        }
    }
}

/* -------------------------------------------------- housekeeping: old mail */
q('DELETE FROM mail_queue WHERE state = "sent" AND sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');

$say('Done.');


/* ==========================================================================
   A pure-PHP dump — shared hosts rarely expose mysqldump.
   ========================================================================== */
function dump_database(string $file): array
{
    $gz = function_exists('gzopen');
    if ($gz) $file .= '.gz';

    $fh = $gz ? gzopen($file, 'wb9') : fopen($file, 'wb');
    if (!$fh) throw new RuntimeException('Cannot write ' . $file);
    $put = fn(string $s) => $gz ? gzwrite($fh, $s) : fwrite($fh, $s);

    $put('-- Marking backup — ' . gmdate('c') . "\n");
    $put("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $rows = 0;
    $listing = all('SHOW TABLES');
    $column  = $listing ? array_keys($listing[0])[0] : null;
    $tables  = $column ? array_column($listing, $column) : [];

    foreach ($tables as $t) {
        $create = one("SHOW CREATE TABLE `$t`");
        $put("DROP TABLE IF EXISTS `$t`;\n" . ($create['Create Table'] ?? '') . ";\n\n");

        $st = db()->prepare("SELECT * FROM `$t`");
        $st->execute();

        $batch = [];
        while ($r = $st->fetch()) {
            $vals = array_map(function ($v) {
                if ($v === null) return 'NULL';
                if (is_int($v) || is_float($v)) return (string)$v;
                return db()->quote((string)$v);
            }, array_values($r));
            $batch[] = '(' . implode(',', $vals) . ')';
            $rows++;

            if (count($batch) >= 200) {
                $put("INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) $put("INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n");
        $put("\n");
    }

    $put("SET FOREIGN_KEY_CHECKS=1;\n");
    $gz ? gzclose($fh) : fclose($fh);
    @chmod($file, 0600);
    return [$rows, $file];
}
