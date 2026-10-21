# Changelog

All notable changes are documented in this file, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) conventions.

## [1.1.0] - 2026-10-21

### Added

- Shipping labels from the admin order view through the USPS Labels v3 API: a **Create USPS Label** button picks a domestic mail class and creates one 4×6 PDF label per packed box (70 lb USPS cap), stored on the private disk
- Label payment through the Payments v3 API: a payment authorization token is fetched per batch against your CRID, Mailer ID and EPS account, so postage charges to the Enterprise Payment account
- Void/refund from the admin: `DELETE /labels/v3/label/{tracking}` cancels unused labels
- Multi-package orders create one label per box, each persisted with its own tracking number; the first label's tracking number lands on the order's shipment
- `usps_shipments` table captures tracking number, mail class, postage, estimated delivery and the raw response (label image excluded)
- Sales → USPS Shipments admin page with download and void actions, menu and ACL entries
- Automatic nonstandard classification fallback on label creation, mirroring the rate flow's error 030011 retry

### Changed

- Admin configuration gains Customer Registration ID (CRID), Mailer ID (MID) and EPS Account Number fields, required for label creation only — rating keeps working without them

## [1.0.0] - 2026-10-15

### Added

- Live USPS commercial rates at checkout through the Prices v3 OAuth API: domestic and international base rates, quoted per allowed mail class
- Per-product package dimensions with a configurable default box; USPS's mandatory dimension requirement surfaced as a clear configuration error instead of silent rate loss
- Automatic nonstandard classification fallback: a machinable rejection (error 030011) is retried once as NONSTANDARD
- Weight-unit aware quoting (kg stores converted to pounds, one-ounce floor, quantity multiplication)
- Handling fees (fixed or percentage) and a configurable rate cache TTL
- `usps:test` artisan smoke command for credentials and sample rates
- Admin configuration under Configure → Sales → Shipping Methods → USPS Shipping with allowed-methods selection spanning domestic and international mail classes
- 26 testbench-based Pest tests and CI (PHP 8.3 × Laravel 11/12)

[1.1.0]: https://github.com/ashrafic/bagisto-usps-shipping/releases/tag/v1.1.0
[1.0.0]: https://github.com/ashrafic/bagisto-usps-shipping/releases/tag/v1.0.0
