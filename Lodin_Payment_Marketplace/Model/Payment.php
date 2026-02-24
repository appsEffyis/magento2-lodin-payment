<?php
namespace Lodin\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Api\Data\CartInterface;

class Payment extends AbstractMethod
{
    const CODE = 'lodin';
    
    protected $_code = self::CODE;
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = false;
    protected $_canUseCheckout = true;
    protected $_canUseInternal = false;
    protected $_isInitializeNeeded = true;
    
    /**
     * Check if payment method is available
     */
    public function isAvailable(CartInterface $quote = null)
    {
        return parent::isAvailable($quote) && $this->getConfigData('active');
    }
    
    /**
     * Initialize payment - redirect to Lodin
     */
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
    }
    
    /**
     * Get checkout redirect URL
     */
    public function getOrderPlaceRedirectUrl()
    {
        return $this->_urlBuilder->getUrl('lodin/payment/redirect', ['_secure' => true]);
    }
}
