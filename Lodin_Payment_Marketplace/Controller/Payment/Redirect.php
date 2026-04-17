<?php
namespace Lodin\Payment\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Checkout\Model\Session;
use Lodin\Payment\Helper\Data as LodinHelper;
use Psr\Log\LoggerInterface;

class Redirect extends Action
{
    protected $checkoutSession;
    protected $lodinHelper;
    protected $logger;
    
    public function __construct(
        Context $context,
        Session $checkoutSession,
        LodinHelper $lodinHelper,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->lodinHelper = $lodinHelper;
        $this->logger = $logger;
    }
    
    public function execute()
    {
        try {
            $order = $this->checkoutSession->getLastRealOrder();
            
            if (!$order->getId()) {
                throw new \Exception('No order found');
            }
            
            $this->logger->info('Lodin: Generating payment link for order #' . $order->getIncrementId());
            
            $paymentLink = $this->lodinHelper->generatePaymentLink($order);

            // Si le test curl vers le webhook fonctionne mais pas le vrai paiement : comparer cette URL
            // avec le back-office Lodin / les logs « inbound » (souvent Lodin n’appelle pas une URL HTTP+IP).
            $this->logger->info('Lodin RTP URLs sent to API (must match webhook target for real payments)', [
                'order_increment' => $order->getIncrementId(),
                'returnUrl' => $this->lodinHelper->getLastRtpReturnUrl(),
                'callbackUrl_notificationUrl' => $this->lodinHelper->getLastRtpWebhookUrl(),
            ]);

            $this->logger->info('Lodin: Payment link generated: ' . $paymentLink);
            
            return $this->resultRedirectFactory->create()->setUrl($paymentLink);
            
        } catch (\Exception $e) {
            $this->logger->error('Lodin: Error generating payment link: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Payment error: %1', $e->getMessage()));
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
    }
}
