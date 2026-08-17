<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PrestaShop\Module\Unipayment\Configuration\ConfigurationValidator;

$validator = new ConfigurationValidator();
$validUnicid = '123e4567-e89b-12d3-a456-426614174000';

$cases = [
    'accepts initial valid credentials' => [
        [],
        $validator->validate($validUnicid, 'secret', false),
    ],
    'requires initial credentials' => [
        [ConfigurationValidator::ERROR_UNICID_REQUIRED, ConfigurationValidator::ERROR_SECRET_REQUIRED],
        $validator->validate('', '', false),
    ],
    'keeps an existing secret when input is empty' => [
        [],
        $validator->validate($validUnicid, '', true),
    ],
    'rejects an invalid unicid' => [
        [ConfigurationValidator::ERROR_UNICID_INVALID],
        $validator->validate('not-a-uuid', 'secret', false),
    ],
    'rejects an oversized secret' => [
        [ConfigurationValidator::ERROR_SECRET_TOO_LONG],
        $validator->validate($validUnicid, str_repeat('x', 65), false),
    ],
];

foreach ($cases as $name => [$expected, $actual]) {
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("FAIL: %s\nExpected: %s\nActual: %s\n", $name, json_encode($expected), json_encode($actual)));
        exit(1);
    }
}

fwrite(STDOUT, sprintf("OK (%d configuration validation cases)\n", count($cases)));
