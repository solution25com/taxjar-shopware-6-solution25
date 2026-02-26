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
