<?php
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::select('SELECT 1');
$running = false;
foreach (glob('/proc/[0-9]*/cmdline') as $path) {
    $cmd = @file_get_contents($path);
    if ($cmd && str_contains($cmd, "artisan\0queue:work\0")) $running = true;
}
exit($running ? 0 : 1);
