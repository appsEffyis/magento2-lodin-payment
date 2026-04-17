<?php
namespace Lodin\Payment\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

class Failed extends Action
{
    private PageFactory $pageFactory;
    private OrderFactory $orderFactory;
    private LoggerInterface $logger;
    private CheckoutSession $checkoutSession;

    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        OrderFactory $orderFactory,
        LoggerInterface $logger,
        CheckoutSession $checkoutSession
    ) {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
        $this->checkoutSession = $checkoutSession;
    }

    public function execute()
    {
        $orderId = (string) ($this->getRequest()->getParam('order_id')
            ?? $this->getRequest()->getParam('increment_id')
            ?? '');
        $key = (string) ($this->getRequest()->getParam('key') ?? '');

        $this->logger->info('Lodin Failed: received', [
            'order_id' => $orderId,
            'params' => $this->getRequest()->getParams(),
        ]);

        // Public pages must not be enumerable by increment_id alone.
        if ($orderId === '' || $key === '') {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        $order = $this->orderFactory->create()->loadByIncrementId($orderId);
        if (!$order->getId()) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        if ($order->getProtectCode() !== $key) {
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }

        // Only show explicit failure page if the order is actually failed/cancelled/pending payment.
        $state = (string) $order->getState();
        $allowed = in_array(
            $state,
            [
                Order::STATE_PENDING_PAYMENT,
                Order::STATE_CANCELED,
                Order::STATE_CLOSED,
            ],
            true
        );

        if (!$allowed) {
            // If payment succeeded (webhook moved order), never show failure page.
            $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
            $this->checkoutSession->setLastQuoteId($order->getQuoteId());
            $this->checkoutSession->setLastOrderId($order->getId());
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
            return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
        }

        return $this->pageFactory->create();
    }
}

