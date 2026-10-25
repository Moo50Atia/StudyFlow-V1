<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['app.key', 'services.v3.bridge_hmac_key', 'services.v3.callback_hmac_key'] as $key) {
    if (! config($key)) {
        fwrite(STDERR, "Required existing secret is missing: {$key}\n"); exit(1);
    }
}
$rawKey = config('app.key');
$keyBytes = str_starts_with($rawKey, 'base64:') ? base64_decode(substr($rawKey, 7), true) : $rawKey;
if ($keyBytes === false || strlen($keyBytes) !== 32) {
    fwrite(STDERR, "Existing APP_KEY is invalid for AES-256-CBC; refusing to regenerate it.\n"); exit(1);
}
foreach (['services.v3.bridge_hmac_key', 'services.v3.callback_hmac_key', 'services.v3.web_pipeline_hmac_secret'] as $key) {
    if (strlen((string) config($key)) < 32) {
        fwrite(STDERR, "Existing signing secret is too short: {$key}\n"); exit(1);
    }
}
foreach (['app/v3', 'app/private/v3/terminal', 'app/private/v3/temporal', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
    $dir = storage_path($path);
    if (! is_dir($dir) && ! mkdir($dir, 0770, true)) exit(1);
    if (! is_writable($dir)) { fwrite(STDERR, "Storage directory is not writable: {$path}\n"); exit(1); }
}
if (! is_file(config('database.connections.sqlite.database'))) {
    fwrite(STDERR, "Existing Laravel database is missing; refusing to create a replacement.\n"); exit(1);
}
exit(Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]));
