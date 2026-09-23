<?php
/**
 * Copy this file to config.php and fill in your own values.
 * config.php must never be committed to version control.
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'marking',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Where this installation is served from, e.g. 'courses/cse014'.
    // Leave empty to detect it automatically — only set this when a reverse
    // proxy rewrites the path so the server cannot work it out itself.
    // It scopes the session cookie to this folder instead of the whole domain.
    'base_path' => '',

    // Marks the session cookie Secure. Required when the site is served
    // over HTTPS; leave false for plain HTTP during local development.
    'secure_cookies' => false,

    // How long a teaching assistant stays signed in without re-authenticating.
    // A lab runs for hours, so PHP's 24-minute default is far too short.
    'session_hours' => 10,

    // Reveals internal error detail in API responses.
    // Keep this false on any deployed server — turn it on only locally.
    'debug' => false,

    /* ------------------------------------------------------------------
       Email notifications
       Messages are queued and delivered by tools/cron.php, so saving a
       grade never waits on a remote mail server.
       ------------------------------------------------------------------ */
    'mail' => [
        'enabled'  => false,
        'method'   => 'smtp',                          // smtp (recommended) or mail
        'from'     => 'marking@example.com',
        'fromName' => 'CSE014 Lab Assessment',
        'siteUrl'  => 'https://example.com/marking',   // used to build links in emails

        // Used when method = smtp
        'host'   => 'smtp.example.com',
        'port'   => 587,
        'user'   => 'marking@example.com',
        'pass'   => '',
        'secure' => 'tls',                             // tls (587), ssl (465), or none
    ],

    /* ------------------------------------------------------------------
       Scheduled tasks — tools/cron.php
       ------------------------------------------------------------------ */
    'cron' => [
        // Only needed when the host provides no CLI cron and the task must be
        // triggered over HTTP:
        //   https://.../tools/cron.php?token=...
        // Leave empty to restrict the task to the command line.
        'token' => '',
    ],

    /* ------------------------------------------------------------------
       Database backups — written by tools/cron.php
       ------------------------------------------------------------------ */
    'backup' => [
        'enabled'     => true,
        'dir'         => __DIR__ . '/db/backups',
        'keep'        => 14,      // how many copies to retain
        'every_hours' => 24,
    ],
];
