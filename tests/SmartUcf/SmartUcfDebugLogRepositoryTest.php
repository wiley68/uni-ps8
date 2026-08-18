<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

define('_DB_PREFIX_', 'ps_');
function pSQL(string $value): string { return addslashes($value); }

final class Db
{
    public $queries = [];
    public function execute($sql): bool { $this->queries[] = $sql; return true; }
    public function insert($table, $data): bool { return true; }
    public function getRow($sql) { return false; }
    public function executeS($sql): array { return []; }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PrestaShop\Module\Unipayment\SmartUcf\SmartUcfDebugLogRepository;

$database = new Db();
$repository = new SmartUcfDebugLogRepository($database);
$repository->prune(new DateTimeImmutable('2026-08-18 12:00:00', new DateTimeZone('UTC')));
$query = (string) end($database->queries);
if (strpos($query, "created_at` < '2026-05-18 12:00:00'") === false) {
    fwrite(STDERR, "FAIL: debug retention is not three calendar months\n");
    exit(1);
}

fwrite(STDOUT, "OK (three-month debug journal pruning)\n");
