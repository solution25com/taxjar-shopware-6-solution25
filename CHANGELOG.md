# Changelog

All notable changes to this project will be documented in this file.

## [v2.2.0] - 2026-10-13

### This release includes
- added the `TaxJarApiCallEvent`, dispatched after every TaxJar API request made for a cart, an order, a refund, a return or a customer, with the operation, endpoint, request payload, response status and body, duration, sandbox flag and the related order, return and customer ids, so other extensions can store TaxJar requests and responses wherever they need, for example in their own table
- the event never contains the API token, and an exception thrown by a listener is logged without affecting the tax calculation or the TaxJar transaction
- added the setting "Store TaxJar calculation data on the order" (enabled by default, per sales channel). When disabled, the `taxjar_calculation` and `taxjar_refund_calculations` order custom fields are no longer written, and `taxjar_calculation` is set to `null` when an order is recalculated. The `taxjar_calculation` cart extension and the event stay available
- fixed a full refund after partial refunds reporting the whole order to TaxJar again. It now reports only what is left: the plugin subtracts the amount, shipping, sales tax and line item quantities of the partial refunds already reported, so the order nets to zero in TaxJar. Each partial refund is kept on the order (`taxjar_reported_refunds`) as TaxJar returned it when accepting it. Partial refunds sent by earlier versions are looked up in TaxJar, except in sandbox mode, where TaxJar answers every lookup with the same sample refund. When nothing is left, no refund is sent. When a partial refund cannot be read from TaxJar, the full refund is not sent and an error is logged, because sending the whole order would count the partial refunds twice
- added reporting of one return by its return id. The Admin API action `POST /api/_action/taxjar/return/{returnId}/refund` and the new "Send return to TaxJar" button on each return in the TaxJar refunds card report a single return. Only a return with the status Done is sent, its cancelled items are left out, and calling it again for a reported return sends nothing. The action answers `502` when TaxJar does not accept the refund, so the caller can retry
- added the option "When the return status changes to Done" to the setting "Send partial refunds to TaxJar". In this mode each return is reported on its own when it is completed, and the payment status Refunded (partially) no longer sends returns
- added the setting "Tax on partial refunds" (per sales channel). The default, "Calculate with TaxJar", keeps calculating the tax of returned items with TaxJar (`/v2/taxes`), the way TaxJar recommends because tax rates and rules can change between checkout and the refund. "Use the tax charged at checkout" reports each returned item's share of the tax charged on its order line at checkout instead, without an extra TaxJar request: by returned quantity, rounded so that all returns of a line add up to exactly the tax collected, and reduced when less than the full price is refunded. In this mode `taxjar_refund_calculations` is not written
- fixed partial refunds of exempt orders reporting sales tax that was never collected. The order transaction of an exempt customer or customer group reports no tax, but the refund asked `/v2/taxes` without the exemption and reported normal tax. Refunds of exempt orders now report no tax and carry the order's `exemption_type`, in both "Tax on partial refunds" modes
- fixed TaxJar lookups (`GET /v2/transactions/refunds/{id}`) always failing with `403`: the request carried an empty JSON body, which TaxJar's CloudFront rejects for GET requests. This also affected the check for refunds already reported by earlier versions and the recovery after a duplicate refund
- fixed the `source` of the `taxjar_calculation` data reporting `cache` when the response had just been fetched from TaxJar, for example right after clearing a customer's TaxJar cache. Shopware calculates a cart several times per request, and the passes after the TaxJar call read the response back from the cache. They now report `api`, as on Shopware 6.6 (1.4.0)
- tax amounts charged on carts and orders are unchanged. Apart from the full refund above and the new options, the data sent to TaxJar is unchanged


## [v2.1.1] - 2026-10-08

### This release includes
- fixed partial refunds so every return of an order is reported to TaxJar, not only the first one, and the return services only load when Shopware Commercial is active
- added a TaxJar refunds card on the order to send pending and later-added return items, view the TaxJar response per item, and a setting to send partial refunds on payment status change (default) or manually

## [v2.1.0] - 2026-09-30

### This release includes
- exposed the complete TaxJar `/v2/taxes` response as the `taxjar_calculation` cart extension, with one entry per tax rule mapped to TaxJar, the request that produced it, whether it came from the API or the cache, and whether the ZIP/state fallback was used
- stored the same data on new orders in the `taxjar_calculation` order custom field
- overwrote `taxjar_calculation` with the latest result whenever an order is recalculated, including admin order edits, and marked it `skipped` when TaxJar no longer applies to the recalculated order
- recorded failed, skipped and bypassed calculations with a status and reason instead of leaving the data empty
- stored the TaxJar tax calculation of every partial refund in the `taxjar_refund_calculations` order custom field, keyed by return id
- documented the field names and data structure in the README
- tax amounts charged on carts and orders are unchanged

## [v2.0.0]

### Changed
- Supported Shopware version narrowed to 6.7 (`~6.7.0`). Shopware 6.6 remains supported on the `main` branch in the 1.x release line.
- Removed the Webpack administration build output (`Resources/public/administration/js/` and `css/`), which only applies to Shopware 6.6, including the unused `i-t-g-co-tax` bundle left from an old plugin name. This branch ships the Vite bundle alone.
- Order commit, refund and ship-flow checks use the Shopware 6.7 primary order delivery, falling back to the first delivery when none is set.
- Administration buttons, icons and the nexus error banner use the Meteor components (`mt-button`, `mt-icon`, `mt-banner`). On 6.7 the deprecated `sw-button` wrapper forced every button to the secondary style, and the nexus error alert rendered without its error styling.
- Log list custom columns use Vue 3 slot syntax, and the header arrow icon uses an icon name that exists in the 6.7 icon kit.
- Removed `TaxExtension::getDefinitionClass()`, which Shopware 6.7 no longer declares on `EntityExtension`.


## [v1.3.2] - 2026-09-23
### Fixed
- recover shipping tax for returns on orders placed before the delivery fix


## [v1.3.1] - 2026-09-07
### Fixed
- Log every TaxJar API failure instead of silently dropping batched orders
- Calculate tax on shipping using the order's tax rules instead of Shopware's automatic tax
- A button not showing on Shopware 6.7 due to outdated build files

## [v1.3.0] - 2026-08-24

### This release includes
- Added configurable TaxJar calculation cache TTL with an 8-hour default
- Added Clear TaxJar Cache functionality in plugin settings
- Added support for global and customer-specific TaxJar cache clearing
- Improved TaxJar cache reliability across sales channels and TaxJar configurations
- Improved TTL handling for default, disabled, and invalid values
- Improved Address Mismatch handling to reduce repeated TaxJar API calls for unchanged invalid addresses
- Improved fallback handling for TaxJar Address Mismatch responses
- Improved TaxJar diagnostics while excluding sensitive data
- Fixed stale TaxJar calculation results remaining active without plugin-controlled expiration
- Fixed cached TaxJar results being reused in the wrong sales channel or TaxJar configuration context
- Fixed TTL reset behavior where clearing a saved 0 value could keep the cache disabled unintentionally


## [v1.2.8] - 2026-06-08

### This release includes
- fixed an error that occurred when refreshing the administration page
- corrected the layout of the select form on the "Add new tax" page
- fixed an issue where a newly created tax would not be set as the default on the first save
- fixed an issue where a previously set tax service provider could not be removed

## [v1.2.7] - 2026-05-19

### This release includes
- removed additional “plus 0% VAT” line from checkout when using TaxJar
- ensured only TaxJar-calculated tax rates are displayed during checkout

---

## [v1.2.6] - 2026-03-02

### This release includes
- change customerId with customer number

---

## [v1.2.5] - 2026-02-26

### This release includes
- Added new **TaxJar Customer Configuration** option in plugin settings.
- Introduced support for **TaxJar-registered customers using Shopware Customer ID**.
- When the new option is enabled:
  - The **Shopware customer ID** is always sent as `taxJarCustomerId` in TaxJar tax calculation requests.
  - The **customerId** is also included in **commit transaction** requests.
- When the option is disabled:
  - The system uses the customer’s **custom fields** (customer ID, exemption type, exemption state).
  - If custom fields are empty, no customer ID is sent to TaxJar.
- Improves flexibility for merchants who manage customers directly inside TaxJar.
  
---

## [v1.2.4] - 2026-02-05

### This release includes
- fix tax on return

---

## [v1.2.3] - 2026-02-04

### This release includes
- Added **partial refund support**, including tax recalculation and initiating partial tax refunds on existing transactions.
- Implemented support for **Shopware discounts and coupons** in tax calculations.
- Added handling of **tax on order returns** using **Shopware Commercial** features.
- Introduced **custom state tax rules**, allowing you to:
  - Include or exclude tax per state
  - Fallback to the default Shopware tax when TaxJar fails or does not return shipping tax  
  (All rules are configurable via plugin settings.)
- Shipping tax is now treated as a **separate tax line** on the storefront and displayed independently.
- Implemented and fully tested **shipping and tax calculations for admin-created orders**.
- Improved overall reliability of tax calculations across storefront and admin workflows.

---

## [v1.2.2] - 2026-02-03

### Changes
- Enhanced tax calculation and refund handling for gift cards and partial refunds.
- Refactored and simplified tax calculation logic in `AddTaxCollector` and `Calculator`.

---

## [v1.2.1] - 2026-01-21

### Fixes
- Improved amount-based tax application based on the TaxJar breakdown.
- If TaxJar returns shipping tax = 0, Shopware shipping tax stays 0.
- If TaxJar returns shipping tax > 0, shipping tax is applied exactly as returned.
- Prevented item tax leaking into shipping (shipping tax is explicitly “locked”).
- Zip/State mismatch handling: Detects TaxJar /taxes zip/state mismatch errors.
- Attempts a state-level fallback where possible.
- If fallback fails, checkout is never blocked, tax proceeds as 0, and the order is flagged for manual review.
- Logging: Zip/state mismatch cases are logged in s25_taxjar_log with a clear type (Tax Calculation - Address Mismatch).

---

## [v1.2.0] - 2025-12-10

### New Features

- Support for Shopware Commercial Return Management
- Tax Calculation for Returns
- Customer Tax Exemption Support
  - Added support for customer tax exemption handling via custom fields.
  - Introduced TaxJar-specific custom fields on the **Customer** entity:
    - Exemption type
    - Exempt regions
  - Exemption data is now included in all TaxJar rate calculation API calls, allowing TaxJar to correctly return **$0 tax** for exempt customers.
  - Enables proper tax-exempt flows aligned with TaxJar’s official API instead of client-side tax removal only.
  - Includes a database migration to add the required custom fields and calculator updates to pass exemption data to TaxJar.
    
---

## [1.1.9] - 2025-12-04

###  Bug Fixes

- Refactor TaxJar integration; remove unused update method, clean up event handling, and improve address handling in transaction processing

---

## [1.1.1] - 2025-10-08

###  Bug Fixes

- **Fixed `TaxExtension` Entity Reference**  
  - Resolved an issue where the `taxExtension` relation incorrectly returned a `taxExtension` entity instead of the intended `tax`.  
  - This fix ensures that the correct provider linkage is loaded, allowing TaxJar to be recognized properly in the checkout process.


---

## [1.1.0] - 2025-10-03

### New Features
- **Admin Configuration – Order Creation Trigger**  
  Added a new dropdown in Admin to control when TaxJar orders are created:  
  - **Order Paid**  
  - **Order Shipped**  
  Orders are now deferred until the selected trigger occurs.

- **Product Tax Code Handling**  
  Updated logic in `TransactionSubscriber.php` to skip sending `productTaxCode` if:  
  - The value is not set  
  - The value equals `"none"` (case-insensitive)  
  Prevents errors during TaxJar order creation.

- **Shipping Tax Inclusion**  
  Extended the shipping tax toggle so that it applies both to **tax calculation** and **order creation**.  
  Admins can now ensure consistent handling of shipping tax in all flows.

- **Custom Tax Code Field**  
  Introduced support for a **custom field** to override the default tax code.  
  If no custom field is provided, the system automatically uses the default tax code.

- **Nexus State Check**  
  Added a GET request to validate connected states. Admins can now view which states are connected to Nexus or link directly to the TaxJar dashboard.

### Improvements

- **API Key Error Handling**  
  Improved error handling to display clear messages when the API key is invalid or requests cannot be processed.

- **Admin Validation – Required Fields**  
  The “Order Creation Trigger” dropdown in Admin must be selected. If left blank, an error message is displayed to guide the user.

---

## [1.0.0] - 2025-09-24

### Initial Release
- First stable release of the TaxJar Shopware 6 Plugin.
- Supports real-time tax calculation for US and international orders.
- Admin configuration for enabling/disabling tax automation.
- Logging of TaxJar API requests and responses for better visibility.
- Basic shipping tax toggle.
