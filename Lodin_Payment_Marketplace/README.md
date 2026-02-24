# Lodin RTP Payment Gateway for Magento 2

Accept instant payments securely with Lodin. This extension integrates the Lodin payment gateway into your Magento 2 store, allowing customers to make real-time payments with automatic order management.

## Features

- Secure instant payments via Lodin
- Automatic order status updates via webhooks
- HMAC-SHA256 signature verification
- Easy 2-minute setup
- Multi-currency support
- Complete transaction history
- Test and production environments

## Requirements

- Magento 2.4.0 or higher
- PHP 8.1 or higher
- SSL certificate (HTTPS)
- Lodin merchant account

## Installation

### Via Composer

```bash
composer require lodin/magento2-payment
php bin/magento module:enable Lodin_Payment
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:flush
```

### Manual Installation

1. Download the extension
2. Extract to `app/code/Lodin/Payment/`
3. Run the following commands:

```bash
php bin/magento module:enable Lodin_Payment
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:flush
```

## Configuration

1. Log in to your Magento admin panel
2. Go to **Stores** > **Configuration** > **Sales** > **Payment Methods**
3. Find **Lodin RTP Payment** and expand the section
4. Set **Enabled** to **Yes**
5. Enter your **Client ID** and **Client Secret** from your Lodin merchant dashboard
6. Save the configuration
7. Clear the cache

### Webhook Configuration

To enable automatic order updates, configure the webhook in your Lodin dashboard:

1. Log in to your Lodin merchant dashboard
2. Go to **Settings** > **Webhooks**
3. Add webhook URL: `https://your-domain.com/lodin/payment/webhook`
4. Select events: payment.succeeded, payment.completed, payment.failed, payment.declined
5. Save

## How It Works

1. Customer selects Lodin RTP Payment at checkout
2. Customer is redirected to secure Lodin payment page
3. Customer completes payment
4. Customer is automatically redirected back to store
5. Order status is updated automatically via webhook

## Support

- Email: support@lodinpay.com
- Documentation: https://docs.lodinpay.com
- GitHub: https://github.com/lodin/magento2-payment

## License

Open Software License (OSL 3.0)

## Changelog

### Version 1.0.0
- Initial release
- Instant payment integration
- Webhook support for automatic order updates
- HMAC-SHA256 security
- Multi-currency support
