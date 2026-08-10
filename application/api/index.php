<?php

/**
 * Serverless entry point.
 *
 * The hosted demo runs on Vercel's PHP runtime rather than a container, because
 * container deploys are not available on the plan. That changes one thing that
 * matters: THE FILESYSTEM IS READ-ONLY except for /tmp.
 *
 * Laravel expects to write to storage/ — compiled Blade templates, the cache,
 * logs. So storage is moved to /tmp here, before the framework boots, and the
 * directories it expects are created. /tmp survives for the life of the
 * container, so this cost is paid once per cold start, not once per request.
 *
 * Sessions and logs should not be written at all on a host like this: set
 * SESSION_DRIVER=cookie and LOG_CHANNEL=stderr. See VERCEL-SETTINGS.md.
 */

$root = dirname(__DIR__);
$storage = '/tmp/storage';

// The tree Laravel expects to find. Created every cold start; cheap, and it
// fails loudly here rather than deep inside a view render.
foreach ([
    $storage.'/app/public',
    $storage.'/framework/cache/data',
    $storage.'/framework/sessions',
    $storage.'/framework/views',
    $storage.'/logs',
] as $dir) {
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

// Blade templates compiled into the deployment are read-only where they sit, so
// copy them across once. Without this the first request after every cold start
// recompiles each template it touches, which is the slowest thing the page
// does. Only missing files are copied, so a warm container skips the lot.
$baked = $root.'/storage/framework/views';

if (is_dir($baked)) {
    foreach (glob($baked.'/*.php') ?: [] as $file) {
        $to = $storage.'/framework/views/'.basename($file);

        if (! file_exists($to)) {
            @copy($file, $to);
        }
    }
}

// Read by bootstrap/app.php, which points the application at it.
putenv('APP_STORAGE_PATH='.$storage);
$_ENV['APP_STORAGE_PATH'] = $storage;
$_SERVER['APP_STORAGE_PATH'] = $storage;

require $root.'/public/index.php';
