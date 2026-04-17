<?php
namespace Lodin\Payment\Gateway\Config;

class Config extends \Magento\Payment\Gateway\Config\Config
{
    const CODE = 'lodin';
    const KEY_ACTIVE = 'active';
    const KEY_CLIENT_ID = 'client_id';
    const KEY_CLIENT_SECRET = 'client_secret';

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        $methodCode = self::CODE,
        $pathPattern = \Magento\Payment\Gateway\Config\Config::DEFAULT_PATH_PATTERN
    ) {
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
    }

    public function isActive($storeId = null)
    {
        return (bool) $this->getValue(self::KEY_ACTIVE, $storeId);
    }

    public function getClientId($storeId = null)
    {
        return $this->getValue(self::KEY_CLIENT_ID, $storeId);
    }

    public function getClientSecret($storeId = null)
    {
        return $this->getValue(self::KEY_CLIENT_SECRET, $storeId);
    }
}
