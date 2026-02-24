<?php
namespace Lodin\Payment\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Checkout\Model\Session;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

class Callback extends Action
{
    protected $checkoutSession;
    protected $orderFactory;
    protected $logger;
    
    public function __construct(
        Context $context,
        Session $checkoutSession,
        OrderFactory $orderFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
    }
    
    public function execute()
    {
        try {
            // Get order from session or from request parameter
            $orderId = $this->getRequest()->getParam('order_id');
            
            if ($orderId) {
                $order = $this->orderFactory->create()->loadByIncrementId($orderId);
            } else {
                $order = $this->checkoutSession->getLastRealOrder();
            }
            
            if (!$order->getId()) {
                $this->logger->warning('Lodin Callback: No order found');
                return $this->resultRedirectFactory->create()->setPath('checkout/cart');
            }
            
            $this->logger->info('Lodin Callback: Order #' . $order->getIncrementId() . ' - Redirecting to success page');
            
            // Restore quote if needed
            $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
            $this->checkoutSession->setLastQuoteId($order->getQuoteId());
            $this->checkoutSession->setLastOrderId($order->getId());
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
            
            // Redirect to success page
            return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
            
        } catch (\Exception $e) {
            $this->logger->error('Lodin Callback Error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('An error occurred. Please contact support.'));
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
    }
}
