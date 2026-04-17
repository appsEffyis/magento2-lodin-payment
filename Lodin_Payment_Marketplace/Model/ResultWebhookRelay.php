<?php
declare(strict_types=1);

namespace Lodin\Payment\Model;

use Lodin\Payment\Helper\Data as LodinData;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * When Lodin's servers never reach our HTTP webhook (common with public IP / HTTP-only shops),
 * the customer still lands on the return URL with a valid protect_code. We POST once in a while
 * to our own webhook with an HMAC the merchant secret — not forgeable without the Lodin client secret.
 */
class ResultWebhookRelay
{
    private LodinData $lodinData;
    private LoggerInterface $logger;

    public function __construct(
        LodinData $lodinData,
        LoggerInterface $logger
    ) {
        $this->lodinData = $lodinData;
        $this->logger = $logger;
    }

    public function relayPendingOrder(Order $order): void
    {
        if ((string) $order->getState() !== \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
            return;
        }
        $payment = $order->getPayment();
        if (!$payment || $payment->getMethod() !== 'lodin') {
            return;
        }
        $storeId = (int) $order->getStoreId();
        $secret = $this->lodinData->getClientSecret($storeId);
        if ($secret === '') {
            return;
        }
        $incrementId = (string) $order->getIncrementId();
        $gatewayInvoice = (string) ($payment->getAdditionalInformation('lodin_gateway_invoice_id')
            ?: $payment->getAdditionalInformation('lodin_invoice_id')
            ?: $incrementId);
        $signature = hash_hmac('sha256', $incrementId . '|' . (string) $order->getProtectCode(), $secret);
        $payload = [
            'eventType' => 'payment.succeeded',
            'cardId' => $incrementId,
            'invoiceId' => $gatewayInvoice,
            'source' => 'magento_result_poll',
            'sync_signature' => $signature,
        ];
        $url = $this->lodinData->getWebhookAbsoluteUrl($storeId);
        $body = (string) json_encode($payload);

        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->logger->info('Lodin ResultWebhookRelay: posted internal sync', [
            'order' => $incrementId,
            'http' => $code,
            'response' => is_string($response) ? substr($response, 0, 200) : '',
        ]);
    }
}
