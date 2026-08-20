<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Checkout\ValidatedPaymentRequest;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;

final class LeasingOrderEmailPresenter
{
    /** @var SensitiveDataCipher */
    private $cipher;

    public function __construct(?SensitiveDataCipher $cipher = null)
    {
        $this->cipher = $cipher ?? new SensitiveDataCipher();
    }

    /**
     * Extra template vars for PrestaShop validateOrder() / order_conf injection.
     *
     * @param array<string, mixed> $shop
     *
     * @return array<string, string>
     */
    public function mailExtraVarsFromRequest(ValidatedPaymentRequest $request, array $shop): array
    {
        return $this->mailExtraVarsFromRows($this->rowsFromRequest($request, $shop));
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $shop
     *
     * @return array<string, string>
     */
    public function mailExtraVarsFromSnapshot(array $snapshot, array $shop): array
    {
        return $this->mailExtraVarsFromRows($this->rowsFromSnapshot($snapshot, $shop));
    }

    /**
     * @param array<string, string> $rows
     *
     * @return array<string, string>
     */
    private function mailExtraVarsFromRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        return [
            '{unipayment_leasing_html}' => $this->renderHtml($rows),
            '{unipayment_leasing_txt}' => $this->renderText($rows),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $shop
     *
     * @return array<string, string>
     */
    public function rowsFromSnapshot(array $snapshot, array $shop): array
    {
        $process2 = ShopConfigurationFlags::isProcess2($shop);
        $statusLabel = trim((string) ($snapshot['status_label'] ?? ''));
        if ($statusLabel === '') {
            $statusLabel = $process2 ? 'Sent to bank - Process 2' : 'Sent to bank - Process 1';
        }

        $customerExtras = [];
        if ($process2) {
            $customerExtras = $this->decryptSensitive($snapshot);
        }

        return $this->buildRows(
            $statusLabel,
            (int) ($snapshot['months'] ?? 0),
            (string) ($snapshot['kop_code'] ?? ''),
            (float) ($snapshot['first_installment'] ?? 0),
            (float) ($snapshot['financed_amount'] ?? 0),
            (float) ($snapshot['monthly_installment'] ?? 0),
            (float) ($snapshot['total_payable'] ?? 0),
            (float) ($snapshot['glp'] ?? 0),
            (float) ($snapshot['gpr'] ?? 0),
            $process2,
            $customerExtras
        );
    }

    /**
     * Woo admin meta-box parity: first row follows the live bank status (list column / CP push).
     *
     * @param array<string, string> $rows
     *
     * @return array<string, string>
     */
    public function applyBankStatusLabel(array $rows, string $statusLabel): array
    {
        $statusLabel = trim($statusLabel);
        if ($statusLabel === '' || $rows === []) {
            return $rows;
        }

        $rows['Bank status'] = $statusLabel;

        return $rows;
    }

    /**
     * @param array<string, mixed> $shop
     *
     * @return array<string, string>
     */
    public function rowsFromRequest(ValidatedPaymentRequest $request, array $shop): array
    {
        $process2 = ShopConfigurationFlags::isProcess2($shop);
        $statusLabel = $process2 ? 'Sent to bank - Process 2' : 'Sent to bank - Process 1';
        $calculation = $request->calculation;

        return $this->buildRows(
            $statusLabel,
            $calculation->scheme->months,
            $calculation->scheme->kopCode,
            $calculation->firstInstallment->amount,
            $calculation->financedAmount,
            $calculation->monthlyInstallment,
            $calculation->totalPayable,
            $calculation->glp,
            $calculation->gpr,
            $process2,
            [
                'egn' => (string) ($request->customer['egn'] ?? ''),
                'phone2' => (string) ($request->customer['phone2'] ?? ''),
            ]
        );
    }

    /**
     * @param array<string, string> $rows
     */
    public function renderHtml(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $html = '<h2>UniCredit leasing</h2>';
        $html .= '<div style="margin-bottom: 40px;">';
        $html .= '<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: \'Helvetica Neue\', Helvetica, Roboto, Arial, sans-serif;" border="1">';
        $html .= '<tbody>';

        foreach ($rows as $label => $value) {
            $html .= '<tr>';
            $html .= '<th class="td" scope="row" style="text-align: left; vertical-align: middle; border: 1px solid #eee; padding: 12px;">'
                . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</th>';
            $html .= '<td class="td" style="text-align: left; vertical-align: middle; border: 1px solid #eee; padding: 12px;">'
                . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    /**
     * @param array<string, string> $rows
     */
    public function renderText(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $text = "\nUniCredit leasing\n\n";
        foreach ($rows as $label => $value) {
            $text .= $label . ': ' . $value . "\n";
        }
        $text .= "\n";

        return $text;
    }

    /**
     * @param array<string, string> $customerExtras
     *
     * @return array<string, string>
     */
    private function buildRows(
        string $statusLabel,
        int $months,
        string $kopCode,
        float $firstInstallment,
        float $financedAmount,
        float $monthlyInstallment,
        float $totalPayable,
        float $glp,
        float $gpr,
        bool $process2,
        array $customerExtras
    ): array {
        if ($statusLabel === '') {
            return [];
        }

        $rows = [
            'Bank status' => $statusLabel,
        ];

        if ($months > 0) {
            $rows['Term (months)'] = (string) $months;
        }

        if ($kopCode !== '') {
            $rows['KOP'] = $kopCode;
        }

        $rows['Down payment'] = number_format($firstInstallment, 2, '.', '');
        $rows['Loan amount'] = number_format($financedAmount, 2, '.', '');
        $rows['Monthly installment'] = number_format($monthlyInstallment, 2, '.', '');
        $rows['Total amount due'] = number_format($totalPayable, 2, '.', '');
        $rows['AIR / APR'] = number_format($glp, 2, '.', '') . '% / ' . number_format($gpr, 2, '.', '') . '%';

        if ($process2) {
            $egn = trim((string) ($customerExtras['egn'] ?? ''));
            if ($egn !== '') {
                $rows['EGN'] = $egn;
            }

            $phone2 = trim((string) ($customerExtras['phone2'] ?? ''));
            if ($phone2 !== '') {
                $rows['Secondary phone'] = $phone2;
            }

            $rows['Message'] = self::process2ConfirmationMessage();
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, string>
     */
    private function decryptSensitive(array $snapshot): array
    {
        $payload = (string) ($snapshot['sensitive_payload'] ?? '');
        if ($payload === '') {
            return [];
        }

        try {
            $decrypted = $this->cipher->decrypt($payload);
        } catch (\Throwable $exception) {
            return [];
        }

        if (!is_array($decrypted)) {
            return [];
        }

        return [
            'egn' => (string) ($decrypted['egn'] ?? ''),
            'phone2' => (string) ($decrypted['phone2'] ?? ''),
        ];
    }

    public static function process2ConfirmationMessage(): string
    {
        return 'Expect a contact to confirm the application you submitted.';
    }
}
