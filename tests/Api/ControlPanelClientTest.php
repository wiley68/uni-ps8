<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('_NEW_COOKIE_KEY_', 'test-key');

final class Configuration
{
    /** @var array<string, mixed> */
    public static $values = [];

    public static function updateValue(string $key, mixed $value): bool
    {
        self::$values[$key] = $value;

        return true;
    }

    /** @return mixed */
    public static function get(string $key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        return self::$values[$key] ?? $default;
    }

    public static function deleteByName(string $key): bool
    {
        unset(self::$values[$key]);

        return true;
    }
}

final class PhpEncryption
{
    public function __construct(string $key)
    {
    }

    public function encrypt(string $plaintext): string
    {
        return base64_encode(strrev($plaintext));
    }

    public function decrypt(string $ciphertext)
    {
        $decoded = base64_decode($ciphertext, true);

        return is_string($decoded) ? strrev($decoded) : false;
    }
}

require_once dirname(__DIR__, 2) . '/src/Configuration/ConfigurationRepository.php';
require_once dirname(__DIR__, 2) . '/src/Security/TokenRepository.php';
require_once dirname(__DIR__, 2) . '/src/Api/HttpResponse.php';
require_once dirname(__DIR__, 2) . '/src/Api/HttpTransportInterface.php';
require_once dirname(__DIR__, 2) . '/src/Api/ShopConfigurationProviderInterface.php';
require_once dirname(__DIR__, 2) . '/src/Api/Exception/ControlPanelException.php';
require_once dirname(__DIR__, 2) . '/src/Api/Exception/AuthenticationException.php';
require_once dirname(__DIR__, 2) . '/src/Api/Exception/HttpException.php';
require_once dirname(__DIR__, 2) . '/src/Api/Exception/MalformedJsonException.php';
require_once dirname(__DIR__, 2) . '/src/Api/Exception/InvalidPayloadException.php';
require_once dirname(__DIR__, 2) . '/src/Api/ModuleApiResponse.php';
require_once dirname(__DIR__, 2) . '/src/Api/ControlPanelClient.php';

use PrestaShop\Module\Unipayment\Api\ControlPanelClient;
use PrestaShop\Module\Unipayment\Api\Exception\HttpException;
use PrestaShop\Module\Unipayment\Api\Exception\InvalidPayloadException;
use PrestaShop\Module\Unipayment\Api\Exception\MalformedJsonException;
use PrestaShop\Module\Unipayment\Api\HttpResponse;
use PrestaShop\Module\Unipayment\Api\HttpTransportInterface;
use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Security\TokenRepository;

final class FakeTransport implements HttpTransportInterface
{
    /** @var HttpResponse[] */
    public $responses = [];

    /** @var array<int, array<string, mixed>> */
    public $requests = [];

    public function request(string $method, string $url, array $headers, ?array $payload): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'payload');
        $response = array_shift($this->responses);
        if (!$response instanceof HttpResponse) {
            throw new RuntimeException('No fake response queued.');
        }

        return $response;
    }
}

function jsonResponse(int $status, array $payload): HttpResponse
{
    return new HttpResponse($status, json_encode($payload, JSON_THROW_ON_ERROR));
}

function assertPhase2(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$unicid = '123e4567-e89b-12d3-a456-426614174000';
$configuration = new ConfigurationRepository();
$configuration->save(true, $unicid, 'test-secret');
$tokens = new TokenRepository();
$transport = new FakeTransport();
$now = 1700000000;
$client = new ControlPanelClient(
    $configuration,
    $tokens,
    $transport,
    'https://shop.example',
    'https://cp.example/api/v1',
    static function () use (&$now): int {
        return $now;
    }
);

$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'error' => null,
    'message' => 'ok',
    'data' => [
        'access_token' => 'token-one',
        'token_type' => 'Bearer',
        'expires_in' => 86400,
        'shop' => ['id' => 10, 'name' => 'https://shop.example', 'unicid' => $unicid],
    ],
]);
$client->login();
assertPhase2($transport->requests[0]['url'] === 'https://cp.example/api/v1/auth/login', 'login endpoint mismatch');
assertPhase2($transport->requests[0]['payload']['name'] === 'https://shop.example', 'login shop name mismatch');
assertPhase2($tokens->getAccessToken() === 'token-one', 'login token was not stored');
assertPhase2(Configuration::$values[TokenRepository::ACCESS_TOKEN] !== 'token-one', 'token was stored in plain text');
assertPhase2($tokens->getExpiresAt() === $now + 86400, 'token expiration mismatch');

$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'error' => null,
    'message' => 'ok',
    'data' => ['unicid' => $unicid],
]);
$client->getShop();
assertPhase2($transport->requests[1]['method'] === 'GET', 'getShop method mismatch');
assertPhase2($transport->requests[1]['headers']['Authorization'] === 'Bearer token-one', 'Bearer header mismatch');

$now += 86350;
$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'error' => null,
    'message' => 'ok',
    'data' => [
        'access_token' => 'token-two',
        'token_type' => 'Bearer',
        'expires_in' => 86400,
    ],
]);
$transport->responses[] = jsonResponse(201, [
    'success' => true,
    'error' => null,
    'message' => 'created',
    'data' => [
        'id' => 55,
        'shop_id' => 10,
        'order_id' => '100',
        'unicid' => $unicid,
        'created_at' => '2026-01-01T00:00:00Z',
    ],
]);
$client->createOrder(['order_id' => '100', 'name' => 'Client']);
assertPhase2($transport->requests[2]['url'] === 'https://cp.example/api/v1/auth/refresh', 'proactive refresh endpoint mismatch');
assertPhase2($transport->requests[3]['url'] === 'https://cp.example/api/v1/orders', 'createOrder endpoint mismatch');
assertPhase2($transport->requests[3]['headers']['Authorization'] === 'Bearer token-two', 'refreshed token was not used');

$transport->responses[] = jsonResponse(401, ['success' => false, 'error' => 'token_expired', 'message' => 'expired', 'data' => []]);
$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'error' => null,
    'message' => 'ok',
    'data' => [
        'access_token' => 'token-three',
        'token_type' => 'Bearer',
        'expires_in' => 86400,
        'shop' => ['id' => 10, 'unicid' => $unicid],
    ],
]);
$transport->responses[] = jsonResponse(200, [
    'success' => true,
    'error' => null,
    'message' => 'updated',
    'data' => [
        'id' => 55,
        'shop_id' => 10,
        'order_id' => '100',
        'status_id' => 'cp_sent',
        'status' => 'sent',
        'updated_at' => '2026-01-01T00:00:01Z',
    ],
]);
$client->updateOrderStatus('100', 'sent', 'cp_sent');
assertPhase2($transport->requests[4]['method'] === 'PATCH', 'status method mismatch');
assertPhase2($transport->requests[5]['url'] === 'https://cp.example/api/v1/auth/login', '401 did not trigger re-login');
assertPhase2($transport->requests[6]['headers']['Authorization'] === 'Bearer token-three', '401 retry did not use new token');
assertPhase2($transport->requests[6]['payload']['status_id'] === 'cp_sent', 'status_id contract mismatch');
assertPhase2($transport->requests[6]['payload']['status'] === 'sent', 'status contract mismatch');

$transport->responses[] = new HttpResponse(
    200,
    '{"success":true,"error":null,"message":"ok","data":{}}'
);
$client->logout();
assertPhase2($transport->requests[7]['url'] === 'https://cp.example/api/v1/auth/logout', 'logout endpoint mismatch');
assertPhase2(!$tokens->hasToken(), 'logout did not invalidate the local token');

$transport->responses[] = new HttpResponse(403, '<html>challenge</html>');
try {
    $client->login();
    assertPhase2(false, 'HTML HTTP error was accepted');
} catch (HttpException $exception) {
    assertPhase2($exception->getStatusCode() === 403, 'HTML HTTP error status mismatch');
}

$transport->responses[] = new HttpResponse(200, '<html>not json</html>');
try {
    $client->login();
    assertPhase2(false, 'malformed successful response was accepted');
} catch (MalformedJsonException $exception) {
    assertPhase2(true, 'malformed successful response classification');
}

// Old top-level token layout / non-object data must be rejected.
$transport->responses[] = new HttpResponse(
    200,
    json_encode([
        'success' => true,
        'error' => null,
        'message' => 'ok',
        'access_token' => 'legacy-token',
        'token_type' => 'Bearer',
        'expires_in' => 86400,
        'shop' => ['unicid' => $unicid],
        'data' => [],
    ], JSON_THROW_ON_ERROR)
);
try {
    $client->login();
    assertPhase2(false, 'legacy top-level token / empty-array data accepted');
} catch (InvalidPayloadException $exception) {
    assertPhase2(true, 'legacy login envelope rejected');
}

// HTTP 2xx alone is not success when success=false.
$transport->responses[] = new HttpResponse(
    200,
    '{"success":false,"error":"authentication_failed","message":"no","data":{}}'
);
try {
    $client->login();
    assertPhase2(false, 'success=false accepted as CP success');
} catch (InvalidPayloadException $exception) {
    assertPhase2(true, 'success=false rejected');
}

fwrite(STDOUT, "OK (Control Panel client authentication and request flow)\n");
