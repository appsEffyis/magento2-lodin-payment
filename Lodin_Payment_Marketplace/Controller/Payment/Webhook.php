<?php
namespace Lodin\Payment\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Lodin\Payment\Helper\Data as LodinHelper;
use Psr\Log\LoggerInterface;

class Webhook extends Action implements CsrfAwareActionInterface
{
    protected $orderFactory;
    protected $lodinHelper;
    protected $logger;
    
    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        LodinHelper $lodinHelper,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->orderFactory = $orderFactory;
        $this->lodinHelper = $lodinHelper;
        $this->logger = $logger;
    }
    
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }
    
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
    
    public function execute()
    {
        $this->logger->info('Lodin: Webhook received');
        
        if ($this->getRequest()->getMethod() !== 'POST') {
            $this->logger->error('Lodin: Invalid request method');
            return $this->getResponse()->setHttpResponseCode(405)->setBody('Method Not Allowed');
        }
        
        $payload = $this->getRequest()->getContent();
        $this->logger->info('Lodin: Webhook payload: ' . $payload);
        
        if (empty($payload)) {
            $this->logger->error('Lodin: Empty payload');
            return $this->getResponse()->setHttpResponseCode(400)->setBody('Bad Request');
        }
        
        // Verify signature
        $signature = $this->getRequest()->getHeader('X-Webhook-Signature');
        if (!$this->verifySignature($payload, $signature)) {
            $this->logger->error('Lodin: Invalid signature');
            return $this->getResponse()->setHttpResponseCode(401)->setBody('Unauthorized');
        }
        
        $data = json_decode($payload, true);
        if (!$data) {
            $this->logger->error('Lodin: Invalid JSON');
            return $this->getResponse()->setHttpResponseCode(400)->setBody('Invalid JSON');
        }
        
        try {
            $this->handleWebhook($data);
            $this->logger->info('Lodin: Webhook processed successfully');
            return $this->getResponse()->setHttpResponseCode(200)->setBody('OK');
        } catch (\Exception $e) {
            $this->logger->error('Lodin: Webhook error: ' . $e->getMessage());
            return $this->getResponse()->setHttpResponseCode(500)->setBody('Internal Server Error');
        }
    }
    
    protected function verifySignature($payload, $receivedSignature)
    {
        if (!$receivedSignature) {
            return false;
        }
        
        $clientSecret = $this->lodinHelper->getClientSecret();
        if (!$clientSecret) {
            return false;
        }
        
        $expectedSignature = $this->lodinHelper->generateSignature($payload, $clientSecret);
        return hash_equals($expectedSignature, $receivedSignature);
    }
    
    protected function handleWebhook($data)
    {
        $eventType = $data['eventType'] ?? null;
        $invoiceId = $data['invoiceId'] ?? null;
        
        if (!$eventType || !$invoiceId) {
            throw new \Exception('Missing required fields');
        }
        
        $this->logger->info('Lodin: Event type: ' . $eventType . ', Invoice ID: ' . $invoiceId);
        
        $order = $this->findOrderByInvoiceId($invoiceId);
        if (!$order) {
            throw new \Exception('Order not found for invoice: ' . $invoiceId);
        }
        
        $this->logger->info('Lodin: Found order #' . $order->getIncrementId());
        
        switch ($eventType) {
            case 'payment.succeeded':
            case 'payment.completed':
                $this->handlePaymentSuccess($order, $data);
                break;
            case 'payment.failed':
            case 'payment.declined':
                $this->handlePaymentFailure($order, $data);
                break;
        }
    }
    
    protected function findOrderByInvoiceId($invoiceId)
    {
        $collection = $this->orderFactory->create()->getCollection()
            ->addFieldToFilter('lodin_invoice_id', $invoiceId)
            ->setPageSize(1);
        
        return $collection->getFirstItem()->getId() ? $collection->getFirstItem() : null;
    }
    
    protected function handlePaymentSuccess($order, $data)
    {
        if ($order->getState() == Order::STATE_PROCESSING) {
            $this->logger->info('Lodin: Order already processed');
            return;
        }
        
        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
        
        if (isset($data['transactionId'])) {
            $payment = $order->getPayment();
            $payment->setTransactionId($data['transactionId']);
            $payment->setIsTransactionClosed(0);
            $payment->registerAuthorizationNotification($order->getGrandTotal());
        }
        
        $order->addCommentToStatusHistory('Lodin payment completed successfully');
        $order->save();
        
        $this->logger->info('Lodin: Order #' . $order->getIncrementId() . ' marked as paid');
    }
    
    protected function handlePaymentFailure($order, $data)
    {
        $order->setState(Order::STATE_CANCELED);
        $order->setStatus(Order::STATE_CANCELED);
        
        $errorMessage = $data['errorMessage'] ?? 'Payment failed';
        $order->addCommentToStatusHistory('Lodin payment failed: ' . $errorMessage);
        $order->save();
        
        $this->logger->info('Lodin: Order #' . $order->getIncrementId() . ' marked as failed');
    }
}
