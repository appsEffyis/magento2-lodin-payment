<?php
namespace Lodin\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Framework\UrlInterface;

class Lodin extends AbstractMethod
{
    const PAYMENT_METHOD_CODE = 'lodin';
    
    protected $_code = self::PAYMENT_METHOD_CODE;
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = false;
    protected $_canUseCheckout = true;
    protected $_canUseInternal = false;
    protected $_isInitializeNeeded = true;
    
    protected $urlBuilder;
    
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        UrlInterface $urlBuilder,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->urlBuilder = $urlBuilder;
    }
    
    /**
     * Check if payment method is available
     */
    public function isAvailable(CartInterface $quote = null)
    {
        return parent::isAvailable($quote);
    }
    
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
    }
    
    public function getOrderPlaceRedirectUrl()
    {
        return $this->urlBuilder->getUrl('lodin/payment/redirect', ['_secure' => true]);
    }
}
