<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository;

$cutoff = SmartUcfDebugLogRepository::retentionCutoff(
    new DateTimeImmutable('2026-08-18 12:00:00', new DateTimeZone('UTC'))
);
if ($cutoff !== '2026-05-18 12:00:00') {
    fwrite(STDERR, "FAIL: debug retention is not three calendar months\n");
    exit(1);
}

fwrite(STDOUT, "OK (three-month debug journal pruning)\n");
