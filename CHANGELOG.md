# Changelog

All notable changes to this project will be documented in this file.

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
