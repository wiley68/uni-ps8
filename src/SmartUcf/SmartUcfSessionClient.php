<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;

/**
 * HTTP client for SmartUCF sucfOnlineSessionStart.
 * Follows the Woo reference (Mtuc_Smartucf_Api_Client).
 */
final class SmartUcfSessionClient
{
    private const SSL_PASSWD = '1234';

    /** @var SmartUcfPayloadBuilder */
    private $payloadBuilder;

    /** @var string */
    private $keysDir;

    public function __construct(SmartUcfPayloadBuilder $payloadBuilder, ?string $keysDir = null)
    {
        $this->payloadBuilder = $payloadBuilder;
        $this->keysDir = $keysDir ?? dirname(__DIR__, 2) . '/keys';
    }

    /**
     * Creates a SmartUCF session and returns the session ID + redirect URL.
     *
     * @param array<string, mixed> $shop     Cached shop configuration
     * @param array<string, mixed> $snapshot Financing snapshot row
     *
     * @return array{session_id: string, redirect_url: string, http_code: int, raw_request: string, raw_response: string}
     */
    public function createSession(array $shop, array $snapshot): array
    {
        $serviceUrl = $this->serviceUrl($shop);
        $applicationUrl = $this->applicationUrl($shop);

        if ($serviceUrl === '' || $serviceUrl === 'sucfOnlineSessionStart') {
            throw new SmartUcfSessionException('The SmartUCF service URL is not configured.', false);
        }

        if ($applicationUrl === '') {
            throw new SmartUcfSessionException('The SmartUCF application URL is not configured.', false);
        }

        $payload = $this->payloadBuilder->build($shop, $snapshot);
        $useCert = ShopConfigurationFlags::usesSmartUcfCertificate($shop);

        if ($useCert) {
            $keyPath = $this->keysDir . '/avalon_private_key.pem';
            $certPath = $this->keysDir . '/avalon_cert.pem';
            if (!is_readable($keyPath) || !is_readable($certPath)) {
                throw new SmartUcfSessionException('SmartUCF SSL key or certificate is missing or unreadable.', false);
            }
        }

        $url = rtrim($serviceUrl, '/') . '/sucfOnlineSessionStart';
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'cache-control: no-cache',
            ],
        ];

        if ($useCert) {
            $options[CURLOPT_SSLKEY] = $this->keysDir . '/avalon_private_key.pem';
            $options[CURLOPT_SSLKEYPASSWD] = self::SSL_PASSWD;
            $options[CURLOPT_SSLCERT] = $this->keysDir . '/avalon_cert.pem';
            $options[CURLOPT_SSLCERTPASSWD] = self::SSL_PASSWD;
            $options[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error !== '') {
            throw new SmartUcfSessionException(
                'SmartUCF connection failed: ' . $error,
                true,
                '',
                $httpCode
            );
        }

        $decoded = is_string($response) ? json_decode($response, false) : null;
        $sessionId = isset($decoded->sucfOnlineSessionID) ? trim((string) $decoded->sucfOnlineSessionID) : '';
        $rawResponse = is_string($response) ? $response : '';

        if ($sessionId === '') {
            throw new SmartUcfSessionException(
                'SmartUCF did not return a session identifier.',
                $httpCode >= 500,
                $rawResponse,
                $httpCode
            );
        }

        $redirectUrl = $this->applicationRedirectUrl($shop, $sessionId);
        if ($redirectUrl === '' || $redirectUrl === '/') {
            throw new SmartUcfSessionException('The SmartUCF application URL is not configured.', false);
        }

        return [
            'session_id' => $sessionId,
            'redirect_url' => $redirectUrl,
            'http_code' => $httpCode,
            'raw_request' => $jsonPayload,
            'raw_response' => is_string($response) ? $response : '',
        ];
    }

    /** @param array<string, mixed> $shop */
    private function serviceUrl(array $shop): string
    {
        return ShopConfigurationFlags::isTestEnvironment($shop)
            ? trim((string) ($shop['uni_test_service'] ?? ''))
            : trim((string) ($shop['uni_production_service'] ?? ''));
    }

    /** @param array<string, mixed> $shop */
    private function applicationUrl(array $shop): string
    {
        return ShopConfigurationFlags::isTestEnvironment($shop)
            ? trim((string) ($shop['uni_test_application'] ?? ''))
            : trim((string) ($shop['uni_production_application'] ?? ''));
    }

    /** @param array<string, mixed> $shop */
    private function applicationRedirectUrl(array $shop, string $sessionId): string
    {
        $base = $this->applicationUrl($shop);

        return rtrim($base, '/') . '/' . ltrim($sessionId, '/');
    }
}
