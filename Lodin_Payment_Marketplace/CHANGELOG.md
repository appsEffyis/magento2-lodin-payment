# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-02-23

### Added
- Initial release of Lodin Payment Gateway for Magento 2
- Integration with Lodin RTP payment API
- Automatic order status updates via webhooks
- HMAC-SHA256 signature verification for security
- Support for instant payments
- Multi-currency support
- Admin configuration panel
- Customer redirect flow (checkout → Lodin → success page)
- Callback controller for return URL handling
- Webhook controller for payment notifications
- Complete transaction logging
- Support for test and production environments

### Security
- Client Secret encryption in database
- HMAC-SHA256 webhook signature verification
- Secure API communication

### Compatibility
- Magento 2.4.0 and higher
- PHP 8.1, 8.2, 8.3
- All standard Magento themes
