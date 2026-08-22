<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\Order;

use PrestaShop\Module\Unipayment\Configuration\ConfigurationRepository;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationCache;
use PrestaShop\Module\Unipayment\Configuration\ShopConfigurationFlags;

/**
 * Leasing detail rows for admin, emails, and the Process 2 thank-you page.
 */
final class OrderLeasingDetailsPresenter
{
    /** @var FinancingSnapshotRepository */
    private $snapshots;
    /** @var OrderBankStatusRepository */
    private $bankStatuses;
    /** @var LeasingOrderEmailPresenter */
    private $rows;
    /** @var ConfigurationRepository */
    private $configuration;
    /** @var ShopConfigurationCache */
    private $cache;

    public function __construct(
        ?FinancingSnapshotRepository $snapshots = null,
        ?OrderBankStatusRepository $bankStatuses = null,
        ?LeasingOrderEmailPresenter $rows = null,
        ?ConfigurationRepository $configuration = null,
        ?ShopConfigurationCache $cache = null
    ) {
        $this->snapshots = $snapshots ?? new FinancingSnapshotRepository();
        $this->bankStatuses = $bankStatuses ?? new OrderBankStatusRepository();
        $this->rows = $rows ?? new LeasingOrderEmailPresenter();
        $this->configuration = $configuration ?? new ConfigurationRepository();
        $this->cache = $cache ?? new ShopConfigurationCache();
    }

    /**
     * @return array<string, string>
     */
    public function rowsForOrder(int $idOrder): array
    {
        $snapshot = $this->snapshots->findByOrderId($idOrder);
        if ($snapshot === null) {
            return [];
        }

        $shop = $this->shop();
        $leasingRows = $this->rows->adminRowsFromSnapshot($snapshot, $shop);
        if ($leasingRows === []) {
            return [];
        }

        $bankStatus = $this->bankStatuses->findByOrderId($idOrder);

        return $this->rows->applyBankStatusLabel(
            $leasingRows,
            (string) ($bankStatus['status_label'] ?? '')
        );
    }

    /**
     * Woo thank-you parity: Process 2 orders only.
     *
     * @return array<string, string>
     */
    public function thankYouRows(int $idOrder): array
    {
        $bankStatus = $this->bankStatuses->findByOrderId($idOrder);
        $statusId = (string) ($bankStatus['status_id'] ?? '');
        if ($statusId !== '' && $statusId !== BankStatus::SENT_PROCESS2) {
            return [];
        }

        if ($statusId === '' && !ShopConfigurationFlags::isProcess2($this->shop())) {
            return [];
        }

        return $this->rowsForOrder($idOrder);
    }

    /**
     * @return array<string, mixed>
     */
    private function shop(): array
    {
        $unicid = $this->configuration->getUnicid();
        if ($unicid === '') {
            return [];
        }

        return $this->cache->getFresh($unicid) ?? [];
    }
}
