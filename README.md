# USPS Shipping for Bagisto

[![CI](https://github.com/ashrafic/bagisto-usps-shipping/actions/workflows/ci.yml/badge.svg)](https://github.com/ashrafic/bagisto-usps-shipping/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![Bagisto 2.x](https://img.shields.io/badge/Bagisto-2.x-green.svg)](https://github.com/bagisto/bagisto)

Live USPS commercial rates at checkout for [Bagisto](https://github.com/bagisto/bagisto)
2.x — built on the USPS Prices v3 OAuth API, per-product package dimensions, and
per-mail-class quoting that survives partial API rejections. Labels and refunds
flow through the USPS Labels v3 API against your Enterprise Payment account.

> **Verified against the live USPS production API.** Request shapes, classification
> fallback, and weight units are confirmed against `api.usps.com` — the same endpoints
> your store uses in production.

## Features

**Checkout**
- Live commercial rates for every allowed mail class (Ground Advantage, Priority Mail, Priority Mail Express, Media Mail, Library Mail, international classes)
- Per-mail-class requests: a service USPS cannot price on a lane is skipped instead of losing the whole quote
- Nonstandard classification handled automatically — a machinable rejection is retried as `NONSTANDARD` with the same accepted schema
- Package dimensions per product: products with Length × Width × Height are quoted in their own box, everything else in the configured default box
- Domestic and international quoting from one carrier, routed by destination
- Weight unit aware: quotes kg-based stores correctly into the pounds the API expects
- Handling fee: fixed amount or percentage
- Rate caching with configurable TTL to protect your API quota
- Never breaks checkout — on any USPS failure the carrier hides itself and logs the cause

**Admin**
- Create USPS labels from the order view — one button, pick the mail class, one label per packed box (70 lb cap), PDF stored on the private disk
- Sales → USPS Shipments: every label with its tracking number, postage cost, estimated delivery, download, and void/refund
- Configure → Sales → Shipping Methods → USPS Shipping: credentials, allowed methods, default box, fees, cache, debug
- Clear configuration errors: missing dimensions and credential problems are logged with the exact setting to fix

**Developer experience**
- `php artisan usps:test` — smoke-test credentials and print sample rates
- 42 Pest tests (testbench-based, no Bagisto install required), Pint-clean, CI on PHP 8.3 × Laravel 11/12

## Requirements

| | |
|---|---|
| PHP | 8.3+ |
| Bagisto | 2.2 or newer |
| USPS credentials | Free — register an app at [developers.usps.com](https://developers.usps.com), enable the **Prices v3** and **International Prices v3** APIs, and copy the Consumer Key / Consumer Secret |
| Label printing | Enable the **Labels v3** and **Payments v3** APIs on the same app, plus your **CRID**, **MID** and **EPS Account Number** from the [Business Customer Gateway](https://business.usps.com) — every label's postage is charged to that EPS account |

## Installation

```bash
composer require ashrafic/bagisto-usps-shipping
php artisan optimize:clear
```

The carrier registers itself through Laravel package discovery — no service provider
registration or `composer.json` edits needed.

## Configuration

Admin → **Configure → Sales → Shipping Methods → USPS Shipping**

1. **Status** — on.
2. **Client ID / Client Secret** — the Consumer Key / Consumer Secret from your USPS
   developer app (the portal's names for the same credentials).
3. **Allowed Methods** — the mail classes to offer. Leave empty for USPS Ground
   Advantage + Priority Mail.
4. **Package Length / Width / Height** — your default shipping box, in inches.
   **Required**: USPS refuses to price a parcel without a box size, so without these
   the USPS options will not appear at checkout.
5. **Customer Registration ID (CRID) / Mailer ID (MID) / EPS Account Number** —
   only needed for label printing. Get them from the USPS Business Customer Gateway;
   every created label's postage is charged to the EPS account.

Also complete **Configure → Sales → Shipping Settings → Origin** (country and ZIP):
USPS prices every quote from that origin.

### Printing labels

Open a paid order in the admin and press **Create USPS Label**. Pick the mail class —
the list follows your Allowed Methods setting — and the label is created, stored as a
PDF on the private disk, and the tracking number is written to the order's shipment.
Orders above 70 lb are split into one label per box. **Sales → USPS Shipments** lists
every label with download and void/refund actions.

Labels are currently **domestic only**; international orders need customs forms and
are rejected with a clear message.

### How pricing works

- **Weight is the main driver.** Every shippable product needs a Weight; quantities
  multiply into it. Kilogram stores are converted automatically.
- **Small parcels are priced by weight alone.** Boxes under 1 cubic foot (1,728 in³)
  skip dimensional weight entirely.
- **Bigger boxes cost more.** Above 1 cubic foot USPS charges by dimensional weight —
  keep the default box honest and set per-product dimensions on oversized items.
- **Carts are quoted per item.** Each item is priced separately and summed; only
  services every item can use are offered.

## Testing

```bash
composer install
vendor/bin/pest
```

or run the linter and suite together, as CI does:

```bash
vendor/bin/pint --test && vendor/bin/pest
```

## License

[MIT](LICENSE)
