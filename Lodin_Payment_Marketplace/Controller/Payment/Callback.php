<?php
namespace Lodin\Payment\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

/**
 * Browser "callback" URL sent to Lodin as callbackUrl (encadrant contract).
 * Delegates to the same flow as {@see Result}: validate order_id + protect key, then result page.
 */
class Callback extends Action
{
    private OrderFactory $orderFactory;
    private LoggerInterface $logger;
    private UrlInterface $urlBuilder;

    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        LoggerInterface $logger,
        UrlInterface $urlBuilder
    ) {
        parent::__construct($context);
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
        $this->urlBuilder = $urlBuilder;
    }

    public function execute()
    {
        try {
            $orderId = (string) ($this->getRequest()->getParam('order_id')
                ?? $this->getRequest()->getParam('increment_id')
                ?? '');
            $key = (string) ($this->getRequest()->getParam('key') ?? '');

            $this->logger->info('Lodin Callback: received', [
                'order_id' => $orderId,
                'params' => $this->getRequest()->getParams(),
            ]);

            if ($orderId === '' || $key === '') {
                return $this->resultRedirectFactory->create()->setPath('checkout/cart');
            }

            $order = $this->orderFactory->create()->loadByIncrementId($orderId);
            if (!$order->getId() || $order->getProtectCode() !== $key) {
                return $this->resultRedirectFactory->create()->setPath('checkout/cart');
            }

            $resultUrl = $this->urlBuilder->getUrl('lodin/payment/result', [
                '_secure' => (bool) $this->getRequest()->isSecure(),
                'order_id' => $orderId,
                'key' => $key,
            ]);

            return $this->resultRedirectFactory->create()->setUrl($resultUrl);
        } catch (\Throwable $e) {
            $this->logger->error('Lodin Callback error: ' . $e->getMessage(), ['exception' => $e]);
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
    }
}
