# Changelog

All notable changes are documented in this file, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) conventions.

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

[1.0.0]: https://github.com/ashrafic/bagisto-usps-shipping/releases/tag/v1.0.0
