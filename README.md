# Tax Jar Shopware 6

## Features

- Sellers can define tax rates based on **country, state, or ZIP code ranges**  
- Option to add a **tax identifier** for each tax rate  
- Quick and straightforward creation of **tax rules**  
- Ability to set **priority levels** and control the **execution order** of tax rules**  
- Tax commit now triggers on **payment status change** instead of order creation  

## Key Highlights

- Supports **US tax calculation**  
- Handles **international tax calculation** out of the box  
- Built-in **tax automation** tools  
- Integration with **TaxJar** for transaction tracking  
- Simple and **lightweight setup** with minimal configuration required

## Compatibility
- ✅ Shopware 6.6.x 


# Installation Steps
1) Download zipped file (do NOT unzip it)
2) Login to Shopware Admin panel
3) Click on Settings in the left Extensions->My extensions
4) Click Upload extension and choose the zip file you previously downloaded
5) Install the extension

# Activation of Extension
1) Go to the Shopware Root Directory
2) Run command php bin/console plugin:install --activate taxjar-shopware-6-solution25
3) Run database migration command php bin/console database:migrate taxjar-shopware-6-solution25 --all, to run the migration script so all required tables and records will get created

# Configuration of Extension
1) Go to Admin->Settings->Shop
2) Click on Tax service provider settings
3) Provide all configuration setting and click on Save Button
4) Clear Cache

# Mapping of TaxJar with Existing Tax Rate
1) Go to Admin->Setting->Shop->Tax
2) Click on Tax Rate for which we want to map Tax Jar as Tax Provider
3) On Tax Rate Admin Edit Page, Select TaxJar from Service Provider dropdown
4) Save the Tax Rate

# Review TaxJar Log
1) Go to Admin->Setting->Shop->Tax service provider setting
2) Click on TaxJar Log button
3) Tax Calculation and Order Transaction Logs can be seen on TaxJar Log Page and can be differentiated based on Request Type Field.
