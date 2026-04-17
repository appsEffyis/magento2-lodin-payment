<?php
namespace Lodin\Payment\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    const XML_PATH_ACTIVE = 'payment/lodin/active';
    const XML_PATH_CLIENT_ID = 'payment/lodin/client_id';
    const XML_PATH_CLIENT_SECRET = 'payment/lodin/client_secret';
    const RTP_API_URL = 'https://api-preprod.lodinpay.com/merchant-service/extensions/pay/rtp';
    
    protected $encryptor;

    /** @var string Dernières URLs envoyées à l’API RTP (diagnostic : comparer avec ce que Lodin appelle vraiment). */
    private string $lastRtpWebhookUrl = '';

    private string $lastRtpReturnUrl = '';

    public function __construct(
        Context $context,
        EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
        $this->encryptor = $encryptor;
    }
    
    public function isEnabled($storeId = null)
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ACTIVE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
    
    public function getClientId($storeId = null)
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_CLIENT_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
    
    public function getClientSecret($storeId = null)
    {
        $encrypted = $this->scopeConfig->getValue(
            self::XML_PATH_CLIENT_SECRET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $encrypted ? $this->encryptor->decrypt($encrypted) : '';
    }
    
    public function getApiUrl()
    {
        return self::RTP_API_URL;
    }

    public function getLastRtpWebhookUrl(): string
    {
        return $this->lastRtpWebhookUrl;
    }

    public function getLastRtpReturnUrl(): string
    {
        return $this->lastRtpReturnUrl;
    }

    /**
     * Absolute storefront URL of the Lodin webhook (same rules as RTP payload callbackUrl).
     */
    public function getWebhookAbsoluteUrl($storeId): string
    {
        $storeId = (int) $storeId;
        $unsecureBase = (string) $this->scopeConfig->getValue(
            'web/unsecure/base_url',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $webhookUseHttps = ($unsecureBase !== '' && strpos($unsecureBase, 'https://') === 0);
        return $this->_urlBuilder->getUrl('lodin/payment/webhook', [
            '_secure' => $webhookUseHttps,
            '_scope' => $storeId,
            '_scope_to_url' => true,
        ]);
    }

    /**
     * Generate signature for API request
     */
    public function generateSignature($payload, $secret)
    {
        $rawHmac = hash_hmac('sha256', $payload, $secret, true);
        $base64 = base64_encode($rawHmac);
        $urlSafe = strtr($base64, ['+' => '-', '/' => '_']);
        return rtrim($urlSafe, '=');
    }

    /**
     * Generate payment link via Lodin API
     */
    public function generatePaymentLink($order)
    {
        $clientId = $this->getClientId($order->getStoreId());
        $clientSecret = $this->getClientSecret($order->getStoreId());
        
        if (!$clientId || !$clientSecret) {
            throw new \Exception('Lodin configuration missing');
        }
        
        // Always generate a non-empty invoiceId (gateway requires cartId not null).
        // Prefer a stable Magento identifier when available.
        $payment = $order->getPayment();
        // "Gateway invoice id" = identifier we send to Lodin during initialization.
        $existingGatewayInvoiceId = $payment ? (string) $payment->getAdditionalInformation('lodin_gateway_invoice_id') : '';
        if ($existingGatewayInvoiceId === '' && $payment) {
            // Backward compatibility with older installs.
            $existingGatewayInvoiceId = (string) $payment->getAdditionalInformation('lodin_invoice_id');
        }

        $orderRef = (string) $order->getIncrementId();
        if ($orderRef === '') {
            $orderRef = (string) $order->getReservedOrderId();
        }
        if ($orderRef === '') {
            $orderRef = (string) $order->getId();
        }
        if ($orderRef === '') {
            $orderRef = (string) time();
        }

        // Make invoiceId deterministic (no random/time component), and without prefix.
        $invoiceId = $existingGatewayInvoiceId !== '' ? $existingGatewayInvoiceId : $orderRef;
        $amount = number_format($order->getGrandTotal(), 2, '.', '');

        // Persist invoiceId as soon as we "pass to gateway" (before API call).
        // This matches the PrestaShop-style behavior: invoiceId exists even before payment.
        if ($payment && $existingGatewayInvoiceId === '') {
            $payment->setAdditionalInformation('lodin_gateway_invoice_id', $invoiceId);
            // Backward compatibility key used elsewhere in this module.
            $payment->setAdditionalInformation('lodin_invoice_id', $invoiceId);
            // Persist cartId explicitly in DB (must match invoiceId).
            $payment->setAdditionalInformation('lodin_cart_id', $invoiceId);
            $order->save();
        }

        // Customer-facing URLs (path-style).
        // - returnUrl → navigateur après paiement → /lodin/payment/result/order_id/.../key/...
        // - merchantCallbackUrl → 2e URL navigateur (contrat encadrant) → /lodin/payment/callback/...
        //
        // IMPORTANT: sur l’API Lodin RTP, callbackUrl est utilisé pour les notifications **serveur → Magento**
        // (webhook JSON). Si on y met une URL navigateur, le statut commande ne passe jamais en « Processing ».
        $incrementId = (string) $order->getIncrementId();
        $protect = (string) $order->getProtectCode();
        $returnPath = 'lodin/payment/result/order_id/' . rawurlencode($incrementId)
            . '/key/' . rawurlencode($protect);
        $returnUrl = $this->_urlBuilder->getUrl('', [
            '_secure' => false,
            '_direct' => $returnPath,
        ]);
        $merchantCallbackPath = 'lodin/payment/callback/order_id/' . rawurlencode($incrementId)
            . '/key/' . rawurlencode($protect);
        $merchantCallbackUrl = $this->_urlBuilder->getUrl('', [
            '_secure' => false,
            '_direct' => $merchantCallbackPath,
        ]);

        // Webhook (server-to-server): scheme must match what Lodin can actually call (same host as la boutique).
        // Erreur fréquente: web/secure/base_url en https:// alors que le site n’écoute qu’en HTTP → webhook jamais reçu.
        $storeId = $order->getStoreId();
        $webhookUrl = $this->getWebhookAbsoluteUrl($storeId);

        $this->lastRtpReturnUrl = $returnUrl;
        $this->lastRtpWebhookUrl = $webhookUrl;

        // Generate signature
        // Signature payload (OLD): clientId + timestamp + amount + invoiceId
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $payload = $clientId . $timestamp . $amount . $invoiceId;
        $signature = $this->generateSignature($payload, $clientSecret);
        
        // Prepare request
        $headers = [
            'Content-Type: application/json',
            'X-Client-Id: ' . $clientId,
            'X-Timestamp: ' . $timestamp,
            'X-Signature: ' . $signature,
            'X-Extension-Code: MAGENTO',
        ];

        $body = [
            // Send as number (backend DTO shows numeric amount).
            'amount' => (float) $amount,
            'invoiceId' => $invoiceId,
            // Merchant reference (Magento increment_id).
            // We standardize on cardId as the merchant order reference.
            'cardId' => (string) $order->getIncrementId(),
            // Lodin webhook payloads in the field send merchant reference as cartId.
            // Send both to avoid depending on which field Lodin chooses.
            'cartId' => (string) $order->getIncrementId(),
            'paymentType' => 'INST',
            'description' => 'Magento Order #' . $order->getIncrementId(),
            'returnUrl' => $returnUrl,
            // Lodin appelle cette URL en POST (JSON) pour mettre à jour la commande — ne pas remplacer par une URL navigateur.
            'callbackUrl' => $webhookUrl,
            // Certains backends utilisent notificationUrl au lieu de callbackUrl pour le POST serveur.
            'notificationUrl' => $webhookUrl,
            // URL navigateur supplémentaire (affichage / redirection côté boutique). Ignorée si l’API ne la connaît pas.
            'merchantCallbackUrl' => $merchantCallbackUrl,
        ];
        
        // Make API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getApiUrl());
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $paymentLink = $data['url'] ?? null;

            // Persist gateway ids returned by Lodin initialization (if present).
            // This is critical to resolve FAILED webhooks that don't include cartId/cardId.
            if (is_array($data) && $order->getPayment()) {
                $payment = $order->getPayment();
                foreach (['transactionId', 'transaction_id', 'id', 'invoiceId', 'invoice_id'] as $k) {
                    if (isset($data[$k]) && is_scalar($data[$k]) && trim((string) $data[$k]) !== '') {
                        $payment->setAdditionalInformation('lodin_init_' . $k, (string) $data[$k]);
                    }
                }
                if ($paymentLink) {
                    $payment->setAdditionalInformation('lodin_payment_url', (string) $paymentLink);
                }
                $order->save();
            }
            
            if ($paymentLink) {
                return $paymentLink;
            }
        }
        
        throw new \Exception('API error: ' . $response);
    }
}
