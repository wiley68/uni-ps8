<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

final class LeasingEmailNotifier
{
    /** @var FinancingSnapshotRepository */
    private $snapshots;

    /** @var LeasingOrderEmailPresenter */
    private $presenter;

    public function __construct(?FinancingSnapshotRepository $snapshots = null, ?LeasingOrderEmailPresenter $presenter = null)
    {
        $this->snapshots = $snapshots ?? new FinancingSnapshotRepository();
        $this->presenter = $presenter ?? new LeasingOrderEmailPresenter();
    }

    /**
     * Sends leasing-information email to customer and admin — once per attempt.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $shop
     */
    public function notify(array $snapshot, int $attemptId, array $shop = []): void
    {
        $this->ensureColumn();

        $current = $this->snapshots->findByAttempt($attemptId);
        if ($current !== null && !empty($current['leasing_email_sent'])) {
            return;
        }

        $customer = is_array($snapshot['customer_json'] ?? null) ? $snapshot['customer_json'] : [];
        $customerEmail = trim((string) ($customer['email'] ?? ''));
        $adminEmail = trim((string) \Configuration::get('PS_SHOP_EMAIL'));
        if ($customerEmail === '' && $adminEmail === '') {
            return;
        }

        $rows = $this->presenter->rowsFromSnapshot($snapshot, $shop);
        if ($rows === []) {
            return;
        }

        $subject = 'УниКредит лизинг - поръчка ' . (string) ($snapshot['order_reference'] ?? '');
        $textBody = $this->presenter->renderText($rows);
        $htmlBody = $this->presenter->renderHtml($rows);

        $to = array_unique(array_filter([$customerEmail, $adminEmail]));
        $languageId = (int) \Configuration::get('PS_LANG_DEFAULT');
        if ($languageId <= 0) {
            $languageId = 1;
        }
        $fromName = (string) \Configuration::get('PS_SHOP_NAME');
        $fromEmail = (string) \Configuration::get('PS_SHOP_EMAIL');
        $moduleMailsDir = _PS_MODULE_DIR_ . 'unipayment/mails';

        foreach ($to as $email) {
            try {
                \Mail::Send(
                    $languageId,
                    'ordersend',
                    $subject,
                    [
                        '{message}' => trim($textBody),
                        '{message_html}' => $htmlBody,
                    ],
                    $email,
                    null,
                    $fromEmail,
                    $fromName,
                    null,
                    null,
                    $moduleMailsDir
                );
            } catch (\Throwable $exception) {
                \PrestaShopLogger::addLog(
                    'UniPayment leasing email could not be sent: ' . $exception->getMessage(),
                    2
                );
            }
        }

        try {
            $this->snapshots->update($attemptId, ['leasing_email_sent' => 1]);
        } catch (\Throwable $exception) {
            \PrestaShopLogger::addLog(
                'UniPayment leasing email marker could not be stored: ' . $exception->getMessage(),
                2
            );
        }
    }

    private static $columnEnsured = false;

    private function ensureColumn(): void
    {
        if (self::$columnEnsured) {
            return;
        }
        self::$columnEnsured = true;
        $table = _DB_PREFIX_ . FinancingSnapshotRepository::TABLE;
        $rows = \Db::getInstance()->executeS("SHOW COLUMNS FROM `{$table}` LIKE 'leasing_email_sent'");
        $row = is_array($rows) && $rows !== [] ? $rows[0] : null;
        if (!$row) {
            \Db::getInstance()->execute("ALTER TABLE `{$table}` ADD `leasing_email_sent` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0");
            $rows = \Db::getInstance()->executeS("SHOW COLUMNS FROM `{$table}` LIKE 'leasing_email_sent'");
            $row = is_array($rows) && $rows !== [] ? $rows[0] : null;
        }
        self::$columnEnsured = is_array($row);
    }
}
