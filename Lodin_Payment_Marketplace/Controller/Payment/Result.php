<?php
namespace Lodin\Payment\Controller\Payment;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Lodin\Payment\Model\ResultWebhookRelay;
use Psr\Log\LoggerInterface;

/**
 * Customer return URL after Lodin payment.
 *
 * Magento best practice:
 * - Webhook updates order state (authoritative)
 * - Return URL only redirects customer to success/cart
 */
class Result extends Action
{
    private Session $checkoutSession;
    private OrderFactory $orderFactory;
    private LoggerInterface $logger;
    private UrlInterface $urlBuilder;
    private PageFactory $pageFactory;
    private ResultWebhookRelay $resultWebhookRelay;

    public function __construct(
        Context $context,
        Session $checkoutSession,
        OrderFactory $orderFactory,
        LoggerInterface $logger,
        UrlInterface $urlBuilder,
        PageFactory $pageFactory,
        ResultWebhookRelay $resultWebhookRelay
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
        $this->urlBuilder = $urlBuilder;
        $this->pageFactory = $pageFactory;
        $this->resultWebhookRelay = $resultWebhookRelay;
    }

    public function execute()
    {
        try {
            $orderId = (string) ($this->getRequest()->getParam('order_id')
                ?? $this->getRequest()->getParam('increment_id')
                ?? '');
            $key = (string) ($this->getRequest()->getParam('key') ?? '');

            $this->logger->info('Lodin Result: received', [
                'order_id' => $orderId,
                'params' => $this->getRequest()->getParams(),
            ]);

            // Require a valid order_id + key in return URL.
            // Avoid MessageManager here: it can throw if customer session is not initialized for this request.
            if ($orderId === '' || $key === '') {
                return $this->resultRedirectFactory->create()->setPath('checkout/cart');
            }

            $order = null;
            if ($orderId !== '') {
                $order = $this->orderFactory->create()->loadByIncrementId($orderId);
            }

            if (!$order || !$order->getId()) {
                return $this->resultRedirectFactory->create()->setPath('checkout/cart');
            }

            if ($order->getProtectCode() !== $key) {
                return $this->resultRedirectFactory->create()->setPath('checkout/cart');
            }

            // If Lodin never POSTs the webhook (HTTP/IP, sandbox, etc.), finalize via signed server-side relay.
            $attemptRelay = (int) $this->getRequest()->getParam('attempt');
            if ((string) $order->getState() === Order::STATE_PENDING_PAYMENT
                && $attemptRelay >= 2
                && $attemptRelay <= 28
                && $this->shouldRunReturnRelay($attemptRelay)
            ) {
                $this->resultWebhookRelay->relayPendingOrder($order);
                $order = $this->orderFactory->create()->load((int) $order->getId());
            }

            $state = (string) $order->getState();
            $isSuccessful = in_array(
                $state,
                [Order::STATE_PROCESSING, Order::STATE_COMPLETE],
                true
            );

            if ($isSuccessful) {
                $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
                $this->checkoutSession->setLastQuoteId($order->getQuoteId());
                $this->checkoutSession->setLastOrderId($order->getId());
                $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
                $successUrl = $this->urlBuilder->getUrl('checkout/onepage/success', [
                    '_secure' => (bool) $this->getRequest()->isSecure(),
                ]);
                $page = $this->pageFactory->create();
                $page->getConfig()->getTitle()->set(__('Payment confirmed'));
                $block = $page->getLayout()->getBlock('lodin.payment.result');
                if ($block) {
                    $block->setData('mode', 'success');
                    $block->setData('redirect_url', $successUrl);
                    $block->setData('order_increment_id', $orderId);
                    $block->setData('delay_seconds', 2);
                }
                return $page;
            }

            if ($state === Order::STATE_CANCELED) {
                return $this->resultRedirectFactory->create()->setPath('lodin/payment/failed', [
                    'order_id' => $orderId,
                    'key' => $key,
                ]);
            }

            // Still pending payment: wait for webhook to update state, then auto-refresh this page.
            $attempt = (int) $this->getRequest()->getParam('attempt');
            if ($attempt >= 30) {
                return $this->resultRedirectFactory->create()->setPath('customer/account');
            }

            $nextUrl = $this->urlBuilder->getUrl('lodin/payment/result', [
                '_secure' => (bool) $this->getRequest()->isSecure(),
                'order_id' => $orderId,
                'key' => $key,
                'attempt' => $attempt + 1,
            ]);

            $page = $this->pageFactory->create();
            $page->getConfig()->getTitle()->set(__('Confirming payment'));
            $block = $page->getLayout()->getBlock('lodin.payment.result');
            if ($block) {
                $block->setData('mode', 'pending');
                $block->setData('poll_url', $nextUrl);
                $block->setData('order_increment_id', $orderId);
                $block->setData('delay_seconds', 2);
            }
            return $page;
        } catch (\Throwable $e) {
            $this->logger->error('Lodin Result error: ' . $e->getMessage(), ['exception' => $e]);
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
    }

    /**
     * Throttle internal webhook calls while the customer waits on the return page.
     */
    private function shouldRunReturnRelay(int $attempt): bool
    {
        if ($attempt <= 8) {
            return true;
        }
        return $attempt % 4 === 0;
    }
}

