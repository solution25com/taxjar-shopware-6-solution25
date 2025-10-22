# Changelog

All notable changes to this project will be documented in this file.

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
