<?php
namespace Lodin\Payment\Controller\Payment;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

/**
 * Customer return URL after Lodin payment.
 * Redirects to Magento success page or back to cart depending on status params.
 */
class ReturnAction extends Action
{
    private Session $checkoutSession;
    private OrderFactory $orderFactory;
    private LoggerInterface $logger;

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
            // Lodin may send different param names depending on integration.
            $status = strtolower((string) ($this->getRequest()->getParam('status')
                ?? $this->getRequest()->getParam('paymentStatus')
                ?? $this->getRequest()->getParam('state')
                ?? $this->getRequest()->getParam('result')
                ?? ''));

            $eventType = strtolower((string) ($this->getRequest()->getParam('eventType')
                ?? $this->getRequest()->getParam('event')
                ?? $this->getRequest()->getParam('type')
                ?? ''));

            $orderId = (string) ($this->getRequest()->getParam('order_id')
                ?? $this->getRequest()->getParam('increment_id')
                ?? $this->getRequest()->getParam('invoiceId')
                ?? $this->getRequest()->getParam('invoice_id')
                ?? '');
            $key = (string) ($this->getRequest()->getParam('key') ?? '');

            $this->logger->info('Lodin Return: received', [
                'status' => $status,
                'eventType' => $eventType,
                'order_id' => $orderId,
                'params' => $this->getRequest()->getParams(),
            ]);

            $success = str_contains($eventType, 'payment.succeeded')
                || str_contains($eventType, 'payment.completed')
                || in_array($status, ['succeeded', 'completed', 'paid', 'success', 'ok'], true);

            // PrestaShop-style return: validate a token then redirect to success page.
            $order = null;
            if ($orderId !== '') {
                $order = $this->orderFactory->create()->loadByIncrementId($orderId);
            }

            if ($order && $order->getId()) {
                // Validate protect_code if provided.
                if ($key !== '' && $order->getProtectCode() !== $key) {
                    $this->messageManager->addErrorMessage(__('Invalid payment return key.'));
                    return $this->resultRedirectFactory->create()->setPath('checkout/cart');
                }

                // If gateway indicates success OR webhook already moved the order, redirect to success.
                $isAlreadySuccessful = in_array($order->getState(), [\Magento\Sales\Model\Order::STATE_PROCESSING, \Magento\Sales\Model\Order::STATE_COMPLETE], true);
                if ($success || $isAlreadySuccessful) {
                    $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
                    $this->checkoutSession->setLastQuoteId($order->getQuoteId());
                    $this->checkoutSession->setLastOrderId($order->getId());
                    $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
                    return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
                }
            }

            // Failure / cancel path: return to cart and restore quote.
            $this->messageManager->addErrorMessage(__('Payment failed or cancelled. Please try again.'));
            try {
                $this->checkoutSession->restoreQuote();
            } catch (\Throwable $e) {
                // ignore restore failures, still redirect to cart
            }
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        } catch (\Throwable $e) {
            $this->logger->error('Lodin Return error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('An error occurred. Please try again.'));
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
    }
}

