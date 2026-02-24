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
        
        $invoiceId = 'ORDER-' . $order->getIncrementId() . '-' . time();
        $amount = number_format($order->getGrandTotal(), 2, '.', '');
        
        // Generate signature
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
        
        // Get return URL - callback to our controller
        $returnUrl = $this->_urlBuilder->getUrl('lodin/payment/callback', [
            '_secure' => true,
            'order_id' => $order->getIncrementId()
        ]);
        
        $body = [
            'amount' => (float) $amount,
            'invoiceId' => $invoiceId,
            'paymentType' => 'INST',
            'description' => 'Magento Order #' . $order->getIncrementId(),
            'returnUrl' => $returnUrl,
            'callbackUrl' => $returnUrl,
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
            
            if ($paymentLink) {
                // Save invoice ID to order for webhook matching
                $order->setData('lodin_invoice_id', $invoiceId);
                $order->save();
                
                return $paymentLink;
            }
        }
        
        throw new \Exception('API error: ' . $response);
    }
}
