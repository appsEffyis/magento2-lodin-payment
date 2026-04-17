<?php
namespace Lodin\Payment\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Lodin\Payment\Helper\Data as LodinData;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class Webhook extends Action implements CsrfAwareActionInterface
{
    private LoggerInterface $logger;
    private Json $json;
    private OrderFactory $orderFactory;
    private OrderRepositoryInterface $orderRepository;
    private ?LodinData $lodinData;
    private ?ResourceConnection $resource;

    public function __construct(
        Context $context,
        LoggerInterface $logger,
        Json $json,
        OrderFactory $orderFactory,
        OrderRepositoryInterface $orderRepository,
        ?LodinData $lodinData = null,
        ?ResourceConnection $resource = null
    ) {
        parent::__construct($context);
        $this->logger = $logger;
        $this->json = $json;
        $this->orderFactory = $orderFactory;
        $this->orderRepository = $orderRepository;
        $this->lodinData = $lodinData;
        $this->resource = $resource;
    }

    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $request = $this->getRequest();
        $rawBody = (string) $request->getContent();

        // Toujours logger en premier : si cette ligne n’apparaît jamais après un paiement,
        // Lodin (ou le réseau) n’atteint pas Magento — aucun correctif PHP ne changera le statut.
        $this->logger->info('Lodin Webhook: inbound', [
            'method' => $request->getMethod(),
            'content_type' => (string) $request->getHeader('Content-Type'),
            'content_length' => strlen($rawBody),
            'client_ip' => $request->getClientIp(),
            'body_preview' => $this->snippet($rawBody),
        ]);

        try {
            $payload = [];

            if ($rawBody !== '') {
                $trimmed = ltrim($rawBody);
                $looksJson = $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');
                if ($looksJson) {
                    try {
                        $payload = (array) $this->json->unserialize($rawBody);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Lodin Webhook: JSON parse failed', ['exception' => $e]);
                    }
                }
                if ($payload === []) {
                    $parsed = [];
                    parse_str($rawBody, $parsed);
                    if ($parsed !== []) {
                        $payload = $parsed;
                    }
                }
            }

            // Lodin / proxies may send parameters as query string (GET) or form body without JSON.
            if ($payload === []) {
                $params = $request->getParams();
                if ($params !== []) {
                    $payload = $params;
                }
            }

            $data = $payload;
            if (isset($payload['data']) && is_array($payload['data'])) {
                $data = $payload['data'];
            }

            $eventType = $this->firstNonEmptyString([
                $data['eventType'] ?? null,
                $payload['eventType'] ?? null,
                $data['eventName'] ?? null,
                $payload['eventName'] ?? null,
                $data['event'] ?? null,
                $payload['event'] ?? null,
                $data['type'] ?? null,
                $payload['type'] ?? null,
            ]);

            $invoiceId = $this->firstNonEmptyString([
                $data['invoiceId'] ?? null,
                $data['invoice_id'] ?? null,
                $payload['invoiceId'] ?? null,
                $payload['invoice_id'] ?? null,
            ]);

            $transactionId = $this->firstNonEmptyString([
                $data['transactionId'] ?? null,
                $data['transaction_id'] ?? null,
                $payload['transactionId'] ?? null,
                $payload['transaction_id'] ?? null,
            ]);

            $cardId = $this->firstNonEmptyString([
                $data['cardId'] ?? null,
                $data['card_id'] ?? null,
                $payload['cardId'] ?? null,
                $payload['card_id'] ?? null,
            ]);

            $cartId = $this->firstNonEmptyString([
                $data['cartId'] ?? null,
                $data['cart_id'] ?? null,
                $payload['cartId'] ?? null,
                $payload['cart_id'] ?? null,
            ]);

            $merchantRef = $this->firstNonEmptyString([
                $data['merchantOrderId'] ?? null,
                $data['merchant_order_id'] ?? null,
                $payload['merchantOrderId'] ?? null,
                $data['orderIncrementId'] ?? null,
                $data['incrementId'] ?? null,
                $data['orderReference'] ?? null,
                $data['reference'] ?? null,
                $data['externalReference'] ?? null,
                is_array($data['order'] ?? null) ? ($data['order']['incrementId'] ?? $data['order']['increment_id'] ?? null) : null,
            ]);

            // Prefer explicit merchant reference, then cartId/cardId (often Magento increment), then invoiceId.
            // Lodin can send the Magento increment_id as cartId (seen in production payloads).
            $referenceRaw = $this->firstNonEmptyString([$merchantRef, $cartId, $cardId, $invoiceId]);
            $reference = $this->normalizeOrderIncrementId($referenceRaw);

            $this->logger->info('Lodin Webhook: received', [
                'eventType' => $eventType,
                'invoiceId' => $invoiceId,
                'transactionId' => $transactionId,
                'cardId' => $cardId,
                'cartId' => $cartId,
                'merchantRef' => $merchantRef,
                'reference' => $reference,
            ]);

            if ($reference === '') {
                $this->logger->warning('Lodin Webhook: empty reference (cannot resolve order)', [
                    'body_snippet' => $this->snippet($rawBody),
                    'params' => $this->getRequest()->getParams(),
                ]);
                return $result->setData(['ok' => true]);
            }

            $order = $this->orderFactory->create()->loadByIncrementId($reference);
            if (!$order->getId() && $invoiceId !== '' && $this->normalizeOrderIncrementId($invoiceId) !== $reference) {
                $order = $this->orderFactory->create()->loadByIncrementId($this->normalizeOrderIncrementId($invoiceId));
            }
            // If Lodin does not send cartId/cardId on failures, resolve order by invoice/transaction id stored in payment info.
            if (!$order->getId()) {
                $resolvedEntityId = $this->findOrderEntityIdByGatewayIds([$transactionId, $invoiceId]);
                if ($resolvedEntityId) {
                    $order = $this->orderFactory->create()->load((int) $resolvedEntityId);
                }
            }

            if (!$order->getId()) {
                $this->logger->warning('Lodin Webhook: order not found', [
                    'reference' => $reference,
                    'body_snippet' => $this->snippet($rawBody),
                ]);
                return $result->setData(['ok' => true]);
            }

            $payment = $order->getPayment();
            if ($payment) {
                if ($invoiceId !== '') {
                    $payment->setAdditionalInformation('lodin_webhook_invoice_id', $invoiceId);
                }
                if ($cardId !== '') {
                    $payment->setAdditionalInformation('lodin_webhook_card_id', $cardId);
                }
            }

            $event = strtolower($eventType);
            $status = strtolower((string) ($payload['status'] ?? $payload['paymentStatus'] ?? $data['status'] ?? $data['paymentStatus'] ?? ''));
            if ($status === '' && isset($data['payment']) && is_array($data['payment'])) {
                $status = strtolower((string) ($data['payment']['status'] ?? $data['payment']['state'] ?? ''));
            }
            $errorMessage = (string) ($payload['errorMessage'] ?? $data['errorMessage'] ?? $payload['message'] ?? $data['message'] ?? '');

            $boolSuccess = $this->coerceBool($payload['success'] ?? $data['success'] ?? $payload['paymentSuccess'] ?? null);

            $success = $boolSuccess === true
                || str_contains($event, 'payment.succeeded')
                || str_contains($event, 'payment.completed')
                || str_contains($event, 'payment_success')
                || str_contains($event, 'rtp.success')
                || str_contains($event, 'succeeded')
                || str_contains($event, 'completed')
                || in_array($status, [
                    'succeeded', 'completed', 'paid', 'success', 'authorized', 'authorised',
                    'approved', 'ok', 'capture', 'captured', 'settled',
                ], true);

            // Ne pas utiliser str_contains(..., 'failed') seul : chaînes type "not_failed" contiennent "failed".
            $failed = $boolSuccess === false
                || str_contains($event, 'payment.failed')
                || str_contains($event, 'payment.declined')
                || str_contains($event, 'payment_failure')
                || str_contains($event, 'declined')
                || $errorMessage !== ''
                || in_array($status, ['failed', 'declined', 'canceled', 'cancelled', 'error', 'rejected'], true);

            $trustedReturnRelay = $this->isTrustedReturnRelay($order, $payload);
            if ($trustedReturnRelay) {
                $failed = false;
                $success = true;
            }

            if ($failed) {
                if ($order->canCancel()) {
                    $order->cancel();
                } else {
                    $order->setState(Order::STATE_CANCELED);
                    $order->setStatus(Order::STATE_CANCELED);
                }
                $order->addCommentToStatusHistory(__('Lodin payment failed/declined via webhook.'));
            } elseif ($success) {
                $currentState = (string) $order->getState();
                if (!in_array($currentState, [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)) {
                    $order->setState(Order::STATE_PROCESSING);
                    $order->setStatus(Order::STATE_PROCESSING);
                }
                $historyMsg = $trustedReturnRelay
                    ? __('Lodin payment confirmed (return-page sync; merchant secret validated).')
                    : __('Lodin payment confirmed via webhook.');
                $order->addCommentToStatusHistory($historyMsg);
            } else {
                $order->addCommentToStatusHistory(
                    __(
                        'Lodin webhook (no state change). eventType=%1 status=%2 — check var/log for full payload.',
                        $eventType,
                        $status
                    )
                );
                $this->logger->warning('Lodin Webhook: success/fail not recognized; order left unchanged', [
                    'increment_id' => $order->getIncrementId(),
                    'eventType' => $eventType,
                    'status' => $status,
                    'body_snippet' => $this->snippet($rawBody),
                ]);
            }

            $this->orderRepository->save($order);

            return $result->setData(['ok' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('Lodin Webhook error: ' . $e->getMessage(), ['exception' => $e]);
            return $result->setData(['ok' => true]);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @param array<int, mixed> $candidates
     */
    private function firstNonEmptyString(array $candidates): string
    {
        foreach ($candidates as $value) {
            $s = is_scalar($value) ? trim((string) $value) : '';
            if ($s !== '') {
                return $s;
            }
        }
        return '';
    }

    private function normalizeOrderIncrementId(string $reference): string
    {
        $ref = trim($reference);
        if ($ref === '') {
            return '';
        }
        if (preg_match('/^(ORDER|CART)-([^-]+)(?:-\\d+)?$/', $ref, $m)) {
            return (string) $m[2];
        }
        return $ref;
    }

    private function snippet(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return (string) mb_substr($raw, 0, 2000, 'UTF-8');
        }
        return (string) substr($raw, 0, 2000);
    }

    /**
     * @param mixed $value
     */
    /**
     * Internal sync from Result polling when Lodin cloud cannot POST our webhook URL.
     *
     * @param array<string, mixed> $payload
     */
    private function isTrustedReturnRelay(Order $order, array $payload): bool
    {
        if (($payload['source'] ?? '') !== 'magento_result_poll') {
            return false;
        }
        if ((string) $order->getState() !== Order::STATE_PENDING_PAYMENT) {
            return false;
        }
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== 'lodin') {
            return false;
        }
        $lodinData = $this->lodinData ?? ObjectManager::getInstance()->get(LodinData::class);
        $secret = $lodinData->getClientSecret((int) $order->getStoreId());
        if ($secret === '') {
            return false;
        }
        $expected = hash_hmac(
            'sha256',
            (string) $order->getIncrementId() . '|' . (string) $order->getProtectCode(),
            $secret
        );
        $given = (string) ($payload['sync_signature'] ?? '');
        return $given !== '' && hash_equals($expected, $given);
    }

    private function coerceBool($value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            if ((int) $value === 1) {
                return true;
            }
            if ((int) $value === 0) {
                return false;
            }
            return null;
        }
        $s = strtolower(trim((string) $value));
        if (in_array($s, ['1', 'true', 'yes', 'y', 'ok', 'success'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no', 'n', 'failed', 'fail'], true)) {
            return false;
        }
        return null;
    }

    /**
     * Attempt to resolve Magento order by gateway ids saved into sales_order_payment.additional_information.
     *
     * @param array<int, string> $gatewayIds
     */
    private function findOrderEntityIdByGatewayIds(array $gatewayIds): ?int
    {
        $ids = [];
        foreach ($gatewayIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return null;
        }

        $resource = $this->resource ?? ObjectManager::getInstance()->get(ResourceConnection::class);
        $conn = $resource->getConnection();
        $paymentTable = $resource->getTableName('sales_order_payment');
        $orderTable = $resource->getTableName('sales_order');

        // additional_information is stored as serialized data; we use LIKE against known keys we set.
        $whereParts = [];
        $bind = [];
        foreach ($ids as $i => $id) {
            foreach ([
                'lodin_gateway_invoice_id',
                'lodin_invoice_id',
                'lodin_webhook_invoice_id',
                'lodin_webhook_card_id',
                'lodin_init_transactionId',
                'lodin_init_transaction_id',
                'lodin_init_id',
                'lodin_init_invoiceId',
                'lodin_init_invoice_id',
            ] as $k) {
                $whereParts[] = "p.additional_information LIKE :w_{$k}_{$i}";
                // Keep pattern simple; serialized formats vary (json/phpserialize). We just need containment.
                $bind["w_{$k}_{$i}"] = '%' . $k . '%' . $id . '%';
            }
            // also match raw id if stored without key (defensive)
            $whereParts[] = "p.additional_information LIKE :w_raw_{$i}";
            $bind["w_raw_{$i}"] = '%' . $id . '%';
        }

        $sql = "SELECT o.entity_id
FROM {$orderTable} o
JOIN {$paymentTable} p ON p.parent_id = o.entity_id
WHERE (" . implode(' OR ', $whereParts) . ")
ORDER BY o.entity_id DESC
LIMIT 1";

        // Build binds safely (ResourceConnection will prepare statement)
        // Note: we avoided embedding $id directly into SQL.
        $stmt = $conn->query($sql, $bind);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $entityId = (int) ($row['entity_id'] ?? 0);
        return $entityId > 0 ? $entityId : null;
    }
}
