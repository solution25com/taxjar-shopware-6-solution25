[![Packagist Version](https://img.shields.io/packagist/v/solution25/tax-jar.svg)](https://packagist.org/packages/solution25/tax-jar)
[![Packagist Downloads](https://img.shields.io/packagist/dt/solution25/tax-jar.svg)](https://packagist.org/packages/solution25/tax-jar)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](https://github.com/solution25/taxjar-shopware-6-solution25/blob/main/LICENSE)

# TaxJar Integration for Shopware 6

## Introduction

The **TaxJar Plugin** for Shopware 6 simplifies and automates sales tax calculations for merchants. It ensures compliance with US and international tax regulations, while integrating seamlessly into your Shopware environment.

Merchants can define custom tax rules, prioritize their execution order, and automatically sync tax transactions with TaxJar for accurate reporting.

---

## Key Features

### Automated Tax Management

* Calculate sales tax in real-time at checkout.
* Commit transactions automatically when **payment status changes**.

### Flexible Rule Creation

* Define tax rates by **country**, **state**, or **ZIP code range**.
* Assign custom **tax identifiers** for better tracking.
* Set **priority levels** and control **execution order** of rules.

### TaxJar Integration

* Directly connect with **TaxJar** for transaction tracking and reporting.
* Sync sales data for compliance and audit readiness.

### International Support

* Handles **US tax calculation** and **international tax scenarios**.
* Works out of the box with multiple currencies.

### Lightweight Setup

* Minimal configuration required.
* Easy integration with Shopware 6 admin panel.

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

- Log in to your Shopware 6 Administration panel.
- Navigate to Extensions > My Extensions.
- Locate the newly cloned plugin and click Install.

3. **Activate the Plugin**

- After installation, click Activate to enable the plugin.
- In your Shopware Admin, go to Settings > System > Plugins.
- Upload or install the “TaxJar” plugin.
- Once installed, toggle the plugin to activate it.

4. **Verify Installation**

- After activation, you will see TaxJar in the list of installed plugins.
- The plugin name, version, and installation date should appear.

---

## Plugin Configuration

1. Go to **Settings > Shop > Tax Service Provider Settings**.
2. Enter your TaxJar configuration values.
3. Click **Save**.
4. Clear cache (`bin/console cache:clear` or Admin UI).

---

## Mapping TaxJar with Existing Tax Rates

1. Go to **Settings > Shop > Tax**.
2. Open the Tax Rate you wish to configure.
3. In the **Service Provider** dropdown, select **TaxJar**.
4. Save changes.

---

## Reviewing TaxJar Logs

1. Navigate to **Settings > Shop > Tax Service Provider Settings**.
2. Click on **TaxJar Log**.
3. View calculation requests and transaction logs, categorized by **Request Type**.

---

