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
* Report each completed return to TaxJar by its return id, from the order or through the Admin API
* Calculate tax on Admin orders
* Two flows for commit transactions when **payment status changes** or **shipping status changes**
* Fully refund tax when payment status changes to refunded, minus the partial refunds already reported
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

| Shopware | Branch | Plugin version |
|---|---|---|
| 6.6.x | `main` | 1.x |
| 6.7.x | `main-6.7` | 2.x |

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

## Reporting Returns to TaxJar

With Shopware Commercial return management, returns are reported to TaxJar as refund transactions. Each return is reported under its own transaction id, `{order transaction id}_partial_refund_{returnId}`, with `_2`, `_3` and so on for items added to the return after it was first reported. The `taxjar_reported_refunds` order custom field records what was sent, so nothing is reported twice.

The setting **Send partial refunds to TaxJar** decides when this happens:

| Option | Returns are sent |
| --- | --- |
| On payment status change (default) | When the payment status changes to Refunded (partially): every return of the order that is not reported yet. |
| Manually from the TaxJar refunds card | Only from the TaxJar refunds card on the order. |
| When the return status changes to Done | Each return on its own, when its status changes to Done, without its cancelled items. |

### Tax on partial refunds

The setting **Tax on partial refunds**, in the **Extra configuration** card at the end of the plugin settings, decides where the tax of a returned item comes from:

| Option | Tax reported for the returned items |
| --- | --- |
| Calculate with TaxJar (default, recommended by TaxJar) | The plugin calculates it with TaxJar (`POST /v2/taxes`) when the refund is sent, as TaxJar's article [Handling refunds with API](https://support.taxjar.com/article/351-handling-refunds-with-api) suggests. The calculation follows TaxJar's current rates and rules, which can change between checkout and the refund. It is stored in `taxjar_refund_calculations`. |
| Use the tax charged at checkout | No extra TaxJar request. Each returned item gets its share of the tax charged on its order line at checkout, which the order transaction reported as the line's `sales_tax`: by returned quantity, rounded so that all returns of a line add up to exactly that tax, and reduced by the same ratio when less than the full price is refunded (the difference is sent as the line's `discount`). Shipping tax is pro-rated the same way when shipping is part of the tax calculation. Nothing is stored in `taxjar_refund_calculations`, because nothing is calculated. |

In both modes, a refund of an exempt order (customer exemption or exempt customer group) reports no sales tax and carries the order's `exemption_type`, as the order transaction did. No `/v2/taxes` request is made for it, because that request doesn't carry the exemption and would return normal tax.

### Reporting one return

A completed return can be reported by its id in every mode. The administration does this with the **Send return to TaxJar** button on the return in the TaxJar refunds card. Other systems, for example a middleware that confirms the refund, call the Admin API action:

```
POST /api/_action/taxjar/return/{returnId}/refund
```

It needs the `order:update` permission and no request body. Only a return with the status **Done** is sent, and its cancelled items are left out. Calling it again for a return that is already reported sends nothing, so it is safe to retry.

| Response | Meaning |
| --- | --- |
| `200` with `"status": "sent"` | The pending items of the return were sent. `transactionId` is the TaxJar refund transaction. |
| `200` with `"status": "already_reported"` | TaxJar already had this refund. It is now recorded on the order. |
| `200` with `"status": "nothing_pending"` | Every item of the return is already reported, or cancelled. |
| `502` with `"status": "failed"` | TaxJar did not accept the refund. Nothing is recorded, so the call can be repeated. Details are in the TaxJar log. |
| `400` with `message` | The return id is invalid or unknown, the return is not Done, the order was already fully refunded, or Commercial is not active. |

```json
{
    "orderId": "0190e0d6c0f8711e9f5ac7be0e0f2d51",
    "returnId": "0190e0d7a1b2730c8e4f9d6a5b3c2e10",
    "returnNumber": "10001",
    "status": "sent",
    "transactionId": "SW10026_partial_refund_0190e0d7a1b2730c8e4f9d6a5b3c2e10"
}
```

After a full refund (payment status Refunded) no return is sent anymore, because the full refund already reports what is left of the order.

---

## TaxJar Calculation Data for Integrations

The complete TaxJar `/v2/taxes` response used to tax a cart is exposed to other plugins, so reporting and reconciliation can work with the same data TaxJar returned.

### Where the data lives

| Location | Key | Written |
| --- | --- | --- |
| Cart extension | `taxjar_calculation` | Every cart calculation in which a tax rule mapped to TaxJar is involved |
| Order custom field | `taxjar_calculation` | On order placement, and overwritten on every order recalculation (admin order edits, adding products, promotions) |
| Order custom field | `taxjar_refund_calculations` | On every partial refund whose tax is calculated with TaxJar (Shopware Commercial return management) |

The two order custom fields are written only while **Store TaxJar calculation data on the order** is enabled in the **Extra configuration** card of the plugin settings. It is enabled by default and can be set per sales channel. When it is disabled:

- nothing is written to `taxjar_calculation` or `taxjar_refund_calculations`. When an order is recalculated, `taxjar_calculation` is set to `null`, so an earlier value can't go stale;
- the `taxjar_calculation` cart extension is still available. Read it in `CartConvertedEvent` to store the data in your own entity. Admin order edits run on an order version, so give that entity a `ReferenceVersionField` to `order`;
- tax calculation, TaxJar transaction reporting and `TaxJarApiCallEvent` are unchanged, and `taxJarTransactionId` and `taxjar_reported_refunds` are still written, because they keep TaxJar reporting idempotent.

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

Status rules:

- Tax amounts on the cart and order are only taken from TaxJar for `success` entries. For `failed`, `skipped` and `bypassed` entries the native Shopware tax applies, exactly as before this data was exposed.
- `partial` means at least one group succeeded while another did not.
- On order placement the field is only written when a tax rule mapped to TaxJar is involved. On recalculation it is always written, so the order never keeps a response that no longer applies.

### `taxjar_refund_calculations`

An object keyed by the Shopware return id. Each value has the same entry fields as above (`status`, `reason`, `source`, `request`, `response`, `error`) plus `version`, `returnId`, `calculatedAt` and `sandbox`. `request` is the payload the plugin sent to `/v2/taxes` for the returned line items. It is written only when **Tax on partial refunds** is set to calculate with TaxJar (see [Tax on partial refunds](#tax-on-partial-refunds)). A full refund (payment status `refunded`) does not calculate tax with TaxJar. It reverses the order, so `taxjar_calculation` applies to it. When partial refunds were reported before, the full refund reports only what is left: the plugin subtracts their amount, shipping, sales tax and line item quantities, so the order nets to zero in TaxJar. It uses each partial refund as TaxJar returned it when accepting it, which is kept in `taxjar_reported_refunds`. Partial refunds reported by earlier versions are looked up in TaxJar instead, except in sandbox mode, where TaxJar answers every lookup with the same sample refund.

The fields are not registered as administration custom fields. They are read and written through the API and DAL like any other order custom field.

### `TaxJarApiCallEvent`

`solu1TaxJar\Core\TaxJar\Event\TaxJarApiCallEvent` is dispatched after every request the plugin sends to TaxJar for a cart, an order, a refund, a return or a customer. It is dispatched for successful calls, TaxJar errors and calls that got no response (timeouts, connection errors). Use it to store the requests and responses where you need them, for example in your own table.

| `getOperation()` | Request |
| --- | --- |
| `tax_calculation` | `POST /v2/taxes` during cart calculation, including admin order recalculation |
| `tax_calculation_address_fallback` | The retry of a cart calculation without ZIP, city and street after TaxJar rejected the ZIP/state combination |
| `order_create` | `POST /v2/transactions/orders` when the order is committed |
| `order_cancel` | `DELETE /v2/transactions/orders/{id}` when the payment is cancelled |
| `order_delete` | `DELETE /v2/transactions/orders/{id}` when the order is deleted |
| `refund` | `POST /v2/transactions/refunds` for a full refund |
| `partial_refund_calculation` | `POST /v2/taxes` for the items of a return |
| `partial_refund` | `POST /v2/transactions/refunds` for a return |
| `partial_refund_lookup` | `GET /v2/transactions/refunds/{id}` to check whether a return was already reported, and before a full refund to read partial refunds reported by earlier versions |
| `customer_create`, `customer_update` | `POST` or `PUT /v2/customers` when a customer's exemption is saved in the administration |

| Getter | Content |
| --- | --- |
| `getOperation()` | One of the `OPERATION_*` constants above |
| `getMethod()`, `getEndpoint()` | HTTP method and full URL |
| `getRequest()` | The payload sent as the JSON body |
| `getStatusCode()` | HTTP status, or `null` when TaxJar did not answer |
| `getResponseBody()` / `getResponseData()` | The unmodified response body / the body decoded as JSON, `null` without a response |
| `getError()` | Transport or HTTP error message, `null` on success |
| `isSuccess()` | `true` for a 2xx status |
| `isSandbox()` | `true` when the sandbox API was used |
| `getRequestedAt()`, `getDurationMs()` | When the request was sent and how long it took |
| `getSalesChannelId()`, `getOrderId()`, `getReturnId()`, `getCustomerId()` | Shopware ids related to the request, `null` when not applicable |
| `getContext()` | The Shopware context of the operation |

```php
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use solu1TaxJar\Core\TaxJar\Event\TaxJarApiCallEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class TaxJarApiCallLogger implements EventSubscriberInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [TaxJarApiCallEvent::class => 'onTaxJarApiCall'];
    }

    public function onTaxJarApiCall(TaxJarApiCallEvent $event): void
    {
        $this->connection->insert('my_taxjar_api_log', [
            'id' => Uuid::randomBytes(),
            'operation' => $event->getOperation(),
            'order_id' => $event->getOrderId() ? Uuid::fromHexToBytes($event->getOrderId()) : null,
            'return_id' => $event->getReturnId() ? Uuid::fromHexToBytes($event->getReturnId()) : null,
            'status_code' => $event->getStatusCode(),
            'request' => json_encode($event->getRequest()),
            'response' => $event->getResponseBody(),
            'created_at' => $event->getRequestedAt()->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }
}
```

- The event runs synchronously, also during cart calculation. Keep listeners fast, and hand heavy work to the message queue.
- An exception thrown by a listener is logged and does not change the tax calculation or the TaxJar transaction. The listener's own data is then missing, so log or handle errors inside your listener.
- Calculations answered from the plugin's cache do not call TaxJar and do not dispatch the event. The `taxjar_calculation` cart extension shows them with `source: cache`.
- The event never contains request headers or the API token. Requests and responses do contain customer addresses and names, so your storage needs its own retention.
- The nexus region list and the connection test in the plugin settings do not dispatch the event.

---
