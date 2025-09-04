# Installation Steps
1) Download zipped file (do NOT unzip it)
2) Login to Shopware Admin panel
3) Click on Settings in the left Extensions->My extensions
4) Click Upload extension and choose the zip file you previously downloaded
5) Install the extension

#Activation of Extension
1) Go to the Shopware Root Directory
2) Run command php bin/console plugin:install --activate tax-jar-shopware-6
3) Run database migration command php bin/console database:migrate tax-jar-shopware-6 --all, to run the migration script so all required tables and records will get created

#Configuration of Extension
1) Go to Admin->Settings->Shop
2) Click on Tax service provider settings
3) Provide all configuration setting and click on Save Button
4) Clear Cache

#Mapping of TaxJar with Existing Tax Rate
1) Go to Admin->Setting->Shop->Tax
2) Click on Tax Rate for which we want to map Tax Jar as Tax Provider
3) On Tax Rate Admin Edit Page, Select TaxJar from Service Provider dropdown
4) Save the Tax Rate

#Review TaxJar Log
1) Go to Admin->Setting->Shop->Tax service provider setting
2) Click on TaxJar Log button
3) Tax Calculation and Order Transaction Logs can be seen on TaxJar Log Page and can be differentiated based on Request Type Field.
