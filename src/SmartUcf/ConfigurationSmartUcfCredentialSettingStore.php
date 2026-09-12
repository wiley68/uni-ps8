<?php

declare(strict_types=1);

namespace PrestaShop\Module\Unipayment\SmartUcf;

/**
 * PrestaShop Configuration-backed store for encrypted SmartUCF credentials.
 *
 * Reads never use Configuration::get() fallback (shop → group → global).
 * Pair reads use one exact-context SQL query on the master DB connection.
 */
final class ConfigurationSmartUcfCredentialSettingStore implements SmartUcfCredentialSettingStoreInterface
{
    /** @var \Db|object */
    private $database;

    /**
     * @param \Db|object|null $database
     */
    public function __construct($database = null)
    {
        $this->database = $database ?? \Db::getInstance();
    }

    /**
     * @return array{user: ?string, password: ?string}
     */
    public function getPair(int $idShop): array
    {
        $idShop = max(0, $idShop);
        $idShopGroup = $this->resolveShopGroupId($idShop);
        $userKey = SmartUcfCredentialRepository::USER_KEY;
        $passwordKey = SmartUcfCredentialRepository::PASSWORD_KEY;

        $sql = 'SELECT `name`, `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` IN (\''
            . pSQL($userKey) . '\', \'' . pSQL($passwordKey) . '\')'
            . $this->exactContextRestriction($idShop, $idShopGroup);

        $rows = $this->database->executeS($sql);
        $pair = ['user' => null, 'password' => null];
        if (!is_array($rows)) {
            return $pair;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            $value = $row['value'] ?? null;
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            if ($name === $userKey) {
                $pair['user'] = (string) $value;
            } elseif ($name === $passwordKey) {
                $pair['password'] = (string) $value;
            }
        }

        return $pair;
    }

    public function get(int $idShop, string $key): ?string
    {
        $pair = $this->getPair($idShop);
        if ($key === SmartUcfCredentialRepository::USER_KEY) {
            return $pair['user'];
        }
        if ($key === SmartUcfCredentialRepository::PASSWORD_KEY) {
            return $pair['password'];
        }

        return null;
    }

    public function set(int $idShop, string $key, string $value): void
    {
        $idShop = max(0, $idShop);
        $idShopGroup = $idShop > 0 ? $this->resolveShopGroupId($idShop) : null;
        $ok = \Configuration::updateValue(
            $key,
            $value,
            false,
            $idShop > 0 ? $idShopGroup : null,
            $idShop > 0 ? $idShop : null
        );
        if (!$ok) {
            throw new \RuntimeException('Failed to persist encrypted SmartUCF credential.');
        }
    }

    public function delete(int $idShop, string $key): void
    {
        $idShop = max(0, $idShop);
        if ($idShop > 0 && method_exists(\Configuration::class, 'deleteFromGivenContext')) {
            $idShopGroup = $this->resolveShopGroupId($idShop);
            \Configuration::deleteFromGivenContext($key, $idShopGroup, $idShop);

            return;
        }

        \Configuration::deleteByName($key);
    }

    public function deleteByNameAllShops(string $key): void
    {
        \Configuration::deleteByName($key);
    }

    private function resolveShopGroupId(int $idShop): int
    {
        if ($idShop <= 0) {
            return 0;
        }
        if (class_exists('\\Shop') && method_exists('\\Shop', 'getGroupFromShop')) {
            $group = \Shop::getGroupFromShop($idShop, true);
            if ($group !== false && $group !== null) {
                return max(0, (int) $group);
            }
        }
        if (class_exists('\\Shop')) {
            try {
                $shop = new \Shop($idShop);
                if (isset($shop->id_shop_group)) {
                    return max(0, (int) $shop->id_shop_group);
                }
            } catch (\Throwable $exception) {
                // Fall through.
            }
        }

        return 0;
    }

    private function exactContextRestriction(int $idShop, int $idShopGroup): string
    {
        if ($idShop > 0) {
            return ' AND `id_shop` = ' . (int) $idShop
                . ' AND `id_shop_group` = ' . (int) $idShopGroup;
        }

        return ' AND (`id_shop` IS NULL OR `id_shop` = 0)'
            . ' AND (`id_shop_group` IS NULL OR `id_shop_group` = 0)';
    }
}
