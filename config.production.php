<?php
/**
 * Production configuration — rename to config.php on the server.
 *
 * Do NOT upload your local config.php: it points at 127.0.0.1 and has
 * debug enabled. Fill the values marked TODO and leave the rest as they are.
 */
return [
    'db' => [
        'host'    => 'localhost',        // TODO most shared hosts use localhost
        'port'    => 3306,
        'name'    => '',                 // TODO database name from the host panel
        'user'    => '',                 // TODO database user
        'pass'    => '',                 // TODO database password
        'charset' => 'utf8mb4',
    ],

    // Where this installation is served from, e.g. 'courses/cse014'.
    // Leave empty to detect it automatically — only set this when a reverse
    // proxy rewrites the path so the server cannot work it out itself.
    // It scopes the session cookie to this folder instead of the whole domain.
    'base_path' => '',

    // TODO set to true once the site is served over HTTPS. Leaving this false
    // on an HTTPS site lets the session cookie travel over plain HTTP.
    'secure_cookies' => true,

    // A lab runs for hours; this keeps assistants signed in while they mark.
    'session_hours' => 10,

    // Must stay false on a live server — true would expose database errors
    // and file paths in API responses.
    'debug' => false,

    /* ------------------------------------------------------------------
       Email notifications.
       Queued here and delivered by tools/cron.php, so saving a grade never
       waits on the mail server.
       ------------------------------------------------------------------ */
    'mail' => [
        'enabled'  => false,                     // TODO true once SMTP below is filled
        'method'   => 'smtp',
        'from'     => '',                        // TODO the sending address
        'fromName' => 'CSE014 Lab Assessment',
        'siteUrl'  => '',                        // TODO https://your-host/marking — used in email links

        'host'   => '',                          // TODO smtp host
        'port'   => 587,
        'user'   => '',                          // TODO smtp username
        'pass'   => '',                          // TODO smtp password
        'secure' => 'tls',                       // tls (587), ssl (465), or none
    ],

    // ------------------------------------------------------------------
    // Scheduled tasks.
    // Prefer a real cron entry, running every five minutes:
    //     */5 * * * * php /var/www/html/courses/cse014/tools/cron.php
    // Only set a token if the host offers no CLI cron and the task must be
    // triggered over HTTP instead.
    //
    // (Line comments here on purpose: the */5 above would close a /* */ block.)
    // ------------------------------------------------------------------
    'cron' => [
        'token' => '',                           // TODO long random string, or leave empty
    ],

    'backup' => [
        'enabled'     => true,
        'dir'         => __DIR__ . '/db/backups',
        'keep'        => 14,
        'every_hours' => 24,
    ],
];
