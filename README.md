[![Packagist Version](https://img.shields.io/packagist/v/solution25/tax-jar.svg)](https://packagist.org/packages/solution25/tax-jar)
[![Packagist Downloads](https://img.shields.io/packagist/dt/solution25/tax-jar.svg)](https://packagist.org/packages/solution25/tax-jar)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](https://github.com/solution25/taxjar-shopware-6-solution25/blob/main/LICENSE)

# TaxJar Integration for Shopware 6

## Introduction

The **TaxJar Plugin** for Shopware 6 simplifies and automates sales tax calculations for merchants. It ensures compliance with US and international tax regulations while integrating seamlessly into your Shopware environment.

Merchants can define custom tax rules, prioritize their execution order, and automatically sync tax transactions with TaxJar for accurate reporting.

---

## Key Features

### Automated Tax Management

* Calculate sales tax in real-time at checkout
* Calculate taxes on order updates
* Compatible with Shopware commercial return management
* Recalculate tax on partial refunds and returns
* Calculate tax on Admin orders
* Two flows for commit transactions when **payment status changes** or **shipping status changes**
* Fully refund tax when payment status changes to refunded
* Supported modes: **Production Mode** and **Sandbox Mode**
* Enable/Disable Debug Mode
* Include/exclude gift cards in tax calculation
* Use product gross price for calculation
* Include shipping cost in calculation
* Option to select **TaxJar Rate** for specific products
* Options to select transaction ID on TaxJar send: **Order Number** or **Order ID**
* Option to exempt (multiple select) specific customer groups from tax
* TaxJar Customer Configuration **Activate/Deactivate** feature if all your customers are already registered on TaxJar
* Customer custom fields to create/update TaxJar customer exemption configuration
* Nexus Region support
* TaxJar Log to track tax calculation requests and transaction logs

### Flexible Rule Creation

* Define tax rates by **country**, **state**, or **ZIP code range**
* Assign custom **tax identifiers** for better tracking
* Set **priority levels** and control **execution order** of rules
* Custom rule for Shopware default tax returned on fallback tax rate and for specific selected states
* Shipping fallback tax rate configurable

### TaxJar Integration

* Directly connect with **TaxJar** for transaction tracking and reporting
* Sync sales data for compliance and audit readiness

### International Support

* Handles **US tax calculation** and **international tax scenarios**
* Works out of the box with multiple currencies

### Lightweight Setup

* Minimal configuration required
* Easy integration with Shopware 6 admin panel

---

## Compatibility

  * ✅ Shopware **6.6.x**
  
  | Shopware | Branch | Plugin version |
  |---|---|---|
  | 6.6.x | `main` | 1.4.1 |
  | 6.7.x | `main-6.7` | 2.1.1 |


---

## Installation & Activation

### GitHub

1. Clone the plugin into your Shopware plugins directory:

```bash
git clone https://github.com/solution25com/taxjar-shopware-6-solution25.git
```

### Packagist

```bash
composer require solution25/tax-jar
```

### Install the Plugin in Shopware 6

1. Log in to your Shopware 6 Administration panel
2. Navigate to **Extensions > My Extensions**
3. Locate the newly cloned plugin and click **Install**

### Activate the Plugin

1. After installation, click **Activate** to enable the plugin
2. In your Shopware Admin, go to **Settings > System > Plugins**
3. Upload or install the "TaxJar" plugin
4. Once installed, toggle the plugin to activate it

### Verify Installation

1. After activation, you will see TaxJar in the list of installed plugins
2. The plugin name, version, and installation date should appear

---

## Plugin Configuration

1. Go to **Settings > Shop > Tax Service Provider Settings**
2. Enter your TaxJar configuration values
3. Click **Save**
4. Clear cache (`bin/console cache:clear` or Admin UI)

---

## Mapping TaxJar with Existing Tax Rates

1. Go to **Settings > Shop > Tax**
2. Open the Tax Rate you wish to configure
3. In the **Service Provider** dropdown, select **TaxJar**
4. Save changes

---

## Creating New TaxJar Tax (Optional but Recommended)

1. Go to **Settings > Shop > Tax**
2. Create a new tax and add name (e.g., TaxJar)
3. Set tax rate to 0%
4. Mark as default
5. In the **Tax Provider** dropdown, select **TaxJar**
6. Navigate to all your products and set TaxJar as the tax rate
7. Save changes

---

## Configure Shipping Method for TaxJar Tax Calculation

1. Go to **Settings > Shop > Shipping**
2. Select the shipping method that you want to use for TaxJar tax calculation
3. Under **Tax calculation**, select dropdown to **Fixed** and **Rate** → **TaxJar**
4. Save changes

---

## Reviewing TaxJar Logs

1. Navigate to **Settings > Shop > Tax Service Provider Settings**
2. Click on **TaxJar Log**
3. View calculation requests and transaction logs, categorized by **Request Type**

---

## Nexus Module

1. Navigate to **Orders > Nexus Module**
2. A list of all nexus regions will be shown
3. If Nexus is not configured, it will display a link that navigates to the TaxJar Dashboard to configure

---

## TaxJar Calculation Data for Integrations

The complete TaxJar `/v2/taxes` response used to tax a cart is exposed to other plugins, so reporting and reconciliation can work with the same data TaxJar returned.

### Where the data lives

| Location | Key | Written |
| --- | --- | --- |
| Cart extension | `taxjar_calculation` | Every cart calculation in which a tax rule mapped to TaxJar is involved |
| Order custom field | `taxjar_calculation` | On order placement, and overwritten on every order recalculation (admin order edits, adding products, promotions) |
| Order custom field | `taxjar_refund_calculations` | On every partial refund (Shopware Commercial return management) |

The key names are available as constants on `solu1TaxJar\Core\TaxJar\TaxJarCalculation`.

```php
use solu1TaxJar\Core\TaxJar\TaxJarCalculation;

$calculation = $cart->getExtension(TaxJarCalculation::EXTENSION_NAME)?->all();

$calculation = $order->getCustomFields()[TaxJarCalculation::ORDER_CUSTOM_FIELD] ?? null;

$refunds = $order->getCustomFields()[TaxJarCalculation::ORDER_REFUND_CUSTOM_FIELD] ?? [];
```

### `taxjar_calculation`

```json
{
    "version": 1,
    "status": "success",
    "calculatedAt": "2026-09-30T11:36:24+00:00",
    "sandbox": false,
    "calculations": [
        {
            "taxId": "019f83783709722f821d101a06bc0a51",
            "status": "success",
            "reason": null,
            "source": "api",
            "addressFallback": false,
            "request": { "from_country": "US", "to_country": "US", "to_zip": "90002", "amount": 800, "shipping": 10, "line_items": [] },
            "response": { "tax": { "amount_to_collect": 58.73, "rate": 0.0725, "has_nexus": true, "jurisdictions": {}, "breakdown": { "line_items": [] } } },
            "error": null
        }
    ]
}
```

| Field | Description |
| --- | --- |
| `version` | Schema version of this structure. |
| `status` | Overall result: `success`, `partial`, `failed`, `skipped`, `bypassed` or `address_mismatch`. |
| `reason` | Present only when `status` is `skipped` with reason `not_applicable`: the order was recalculated but no tax rule of its line items is mapped to TaxJar any more. `calculations` is empty in that case. |
| `calculatedAt` | ISO 8601 time the cart was calculated. |
| `sandbox` | `true` when the calculation used the TaxJar sandbox, `null` when TaxJar was not called. |
| `calculations` | One entry per Shopware tax rule mapped to TaxJar. Products are grouped by tax rule and each group is sent to TaxJar in its own `/v2/taxes` request, so a cart with products of two mapped tax rules has two entries. |

Each entry in `calculations`:

| Field | Description |
| --- | --- |
| `taxId` | Shopware tax rule id of the product group. |
| `status` | `success`, `failed`, `skipped`, `bypassed` or `address_mismatch`. |
| `reason` | Why the entry is not `success`: `inactive` (TaxJar disabled for the sales channel), `missing_customer_or_shipping_address`, `bypass_rule`, `api_error`, `unusable_response`, `zip_state_mismatch` or `exception`. |
| `source` | `api` when the response was fetched from TaxJar while handling the current request, `cache` when it was stored by an earlier request and read from the plugin's calculation cache. Shopware calculates a cart several times per request, so later passes reuse the response fetched by the first one and still report `api`. A cached response is the unmodified response of the earlier identical request. |
| `addressFallback` | `true` when TaxJar rejected the ZIP/state combination and the response comes from the retry without ZIP, city and street. |
| `request` | The exact payload sent to `/v2/taxes` for this response. |
| `response` | The unmodified `/v2/taxes` response body (`{"tax": {...}}`), or `null` when no usable response was received. |
| `error` | The TaxJar error body or exception details for failed entries, otherwise `null`. |

`response.tax.breakdown.line_items[].id` is the Shopware **product id** (the line item's `referencedId`).

The tax charged on the cart and order is the sum of `response.tax.breakdown.line_items[].tax_collectable` plus `response.tax.breakdown.shipping.tax_collectable` when shipping is included in the calculation. TaxJar rounds each line separately and rounds `amount_to_collect` on the total, so the two can differ by 0.01. Use the line item values when reconciling against the order.

Status rules:

- Tax amounts on the cart and order are only taken from TaxJar for `success` entries. For `failed`, `skipped` and `bypassed` entries the native Shopware tax applies, exactly as before this data was exposed.
- `partial` means at least one group succeeded while another did not.
- On order placement the field is only written when a tax rule mapped to TaxJar is involved. On recalculation it is always written, so the order never keeps a response that no longer applies.

### `taxjar_refund_calculations`

An object keyed by the Shopware return id. Each value has the same entry fields as above (`status`, `reason`, `source`, `request`, `response`, `error`) plus `version`, `returnId`, `calculatedAt` and `sandbox`. `request` is the payload the plugin sent to `/v2/taxes` for the returned line items. A full refund (payment status `refunded`) does not calculate tax with TaxJar. It reverses the order, so `taxjar_calculation` applies to it.

The fields are not registered as administration custom fields. They are read and written through the API and DAL like any other order custom field.

---

