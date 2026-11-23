<?php

/*
 * Vercel entry point for Curriculum MAP (Laravel on the vercel-php runtime).
 * Vercel's filesystem is read-only except /tmp, so storage and Laravel's
 * bootstrap caches are redirected there. Uploaded files do not persist.
 */

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$setEnv = function (string $key, string $value, bool $override = true): void {
    if (! $override && getenv($key) !== false && getenv($key) !== '') {
        return;
    }
    putenv("$key=$value");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
};

// Writable storage in /tmp
$storage = '/tmp/storage';
foreach (['app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    if (! is_dir("$storage/$dir")) {
        @mkdir("$storage/$dir", 0777, true);
    }
}

// Laravel bootstrap caches must live somewhere writable
foreach (['CONFIG', 'EVENTS', 'PACKAGES', 'ROUTES', 'SERVICES'] as $name) {
    $setEnv("APP_{$name}_CACHE", '/tmp/'.strtolower($name).'.php');
}
$setEnv('VIEW_COMPILED_PATH', "$storage/framework/views");

// Serverless-friendly defaults (can be overridden in Vercel env settings)
$setEnv('LOG_CHANNEL', 'stderr', false);
$setEnv('SESSION_DRIVER', 'cookie', false);
$setEnv('CACHE_STORE', 'array', false);
$setEnv('BASSET_DEV_MODE', 'true', false);

// TLS to the managed MySQL host, using the CA cert committed with the repo
if (is_file($ca = __DIR__.'/../database/ca.pem')) {
    $setEnv('MYSQL_ATTR_SSL_CA', realpath($ca), false);
}

// Requests reach PHP from Vercel's edge, which terminates HTTPS
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->useStoragePath($storage);

// One-time database setup, only while RUN_SETUP=true is set in Vercel
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/__setup' && getenv('RUN_SETUP') === 'true') {
    require __DIR__.'/setup.php';
    exit;
}

$app->handleRequest(Request::capture());
