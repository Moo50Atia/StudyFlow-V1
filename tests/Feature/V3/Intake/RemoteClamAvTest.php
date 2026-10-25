<?php

use App\Services\V3\Intake\ClamAvAdapter;

test('remote ClamAV streams container-local files and retains fail-closed exit mapping', function () {
    $dir = sys_get_temp_dir().'/clam-stream-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $binary = $dir.'/clamdscan';
    file_put_contents($binary, "#!/bin/sh\nfor arg in \"\$@\"; do if [ \"\$arg\" = --version ]; then exit 0; fi; done\nprintf '%s\\n' \"\$@\" > '$dir/arguments'\nexit \"\$(cat '$dir/exit')\"\n");
    chmod($binary, 0700);
    putenv('V3_CLAMAV_CONFIG=/etc/clamav/product.conf');
    try {
        $adapter = new ClamAvAdapter($binary);
        foreach ([0 => null, 1 => 'malware_detected', 2 => 'inspection_unavailable'] as $code => $reason) {
            file_put_contents($dir.'/exit', (string) $code);
            $result = $adapter->scan('/app/storage/private.pdf');
            expect($result['valid'])->toBe($code === 0);
            if ($reason) expect($result['reason_code'])->toBe($reason);
        }
        $arguments = file_get_contents($dir.'/arguments');
        expect($arguments)->toContain('--stream')->toContain('--config-file=/etc/clamav/product.conf')->not->toContain('--fdpass');
    } finally {
        putenv('V3_CLAMAV_CONFIG');
        foreach (glob($dir.'/*') as $file) unlink($file);
        rmdir($dir);
    }
});
