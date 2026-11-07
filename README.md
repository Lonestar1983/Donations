# PayPal Donations — Module and Block

**Version:** 1.0.0  
**Author:** Lonestar  
**Platform:** Nuke Evolution Xtreme

A PayPal REST API donations module with a companion donation goal block. Visitors can contribute through PayPal, choose whether their site username appears publicly, and view donation progress and recent supporters.

> **The module includes a built-in installer.** You do not need to import an SQL file for a normal fresh installation. Upload the files, open the Donations administration page, and select **Run installer**. PayPal credentials must still be configured separately.

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Upload and installation](#upload-and-installation)
- [Create a PayPal sandbox app](#create-a-paypal-sandbox-app)
- [Configure PayPal webhooks](#configure-paypal-webhooks)
- [Create the API configuration file](#create-the-api-configuration-file)
- [Donation settings](#donation-settings)
- [Enable the companion block](#enable-the-companion-block)
- [Test the integration](#test-the-integration)
- [Switch to live payments](#switch-to-live-payments)
- [Transactions and public totals](#transactions-and-public-totals)
- [Troubleshooting](#troubleshooting)
- [Updates, translations and removal](#updates-translations-and-removal)
- [Official PayPal references](#official-paypal-references)

## Features

- Built-in database installer and administration dashboard.
- PayPal order creation and capture through server-side REST API calls.
- Separate sandbox and live configuration.
- Configurable currency, minimum and maximum donation amounts.
- Optional guest donations and public username anonymity.
- Donation goal, goal start date and five recent completed donations.
- Companion block linking visitors to the full donation page.
- Verified PayPal webhook processing and administrator status reconciliation.
- Shared donation settings and cached summaries for the module and block.
- Built-in uninstall workflow.

This package implements individual donations. It does not include a recurring subscription setup or an administration action for issuing PayPal refunds.

## Requirements

- A working Nuke Evolution Xtreme installation providing the CMS helpers used by this package, including Evolution options, administrator permissions, CSRF protection and asset loading.
- A PHP version compatible with your CMS, with cURL, JSON, sessions and MySQLi available. The package uses PHP 7.3-era features such as `JSON_THROW_ON_ERROR`; this is a source compatibility floor, not a recommendation to run an obsolete PHP release.
- A working MySQL/MariaDB database. The CMS database user needs permission to create the donation transaction table and read/write module data and Evolution settings.
- Access to database column metadata through `information_schema.COLUMNS`, which the installer uses to inspect existing tables.
- A public HTTPS website with a valid certificate. The site URL configured in the CMS must begin with `https://`, including when using sandbox payments.
- Outbound HTTPS access from PHP to PayPal, and inbound PayPal webhook requests to your site.
- A PayPal Business account for receiving live payments, plus a PayPal Developer app and sandbox accounts for testing.

The block depends on `modules/Donations/includes/functions.php`. Install the module even if you mainly intend to display the block.

## Upload and installation

### 1. Back up your website

Before installing over an existing copy, back up the database, existing Donations files and any private API configuration. Do not overwrite a working `config.php` with an empty example.

### 2. Upload the package

Extract the ZIP and merge its folders into your CMS root, alongside `mainfile.php` and `modules.php`:

| Package folder | Website destination |
| --- | --- |
| `modules/Donations/` | `<CMS root>/modules/Donations/` |
| `blocks/Donations/` | `<CMS root>/blocks/Donations/` |

Preserve folder names and capitalisation. Do not nest the package in another `paypal-api-donations` folder under the CMS root.

### 3. Open Donations administration

Sign in as an administrator authorised to manage **Donations**. Open its dashboard from site administration. With the default administrator filename, the direct address is:

```text
https://your-domain.example/admin.php?op=evo_donations
```

If your administrator entry filename is different, replace `admin.php` accordingly.

### 4. Run the built-in installer

When the donation database is not ready, the dashboard displays **Install Donations**. Select **Run installer** and wait for the success notice.

The installer:

- Uses the CMS database prefix automatically.
- Creates `<prefix>_donations_transactions` with indexes for PayPal order and capture references.
- Stores preferences as the single serialized `donations_settings` option in the existing Evolution options table, normally `<prefix>_evolution`.
- Can copy valid settings from the older `<prefix>_donations_settings` table when present.
- Preserves existing donation transaction records.
- Checks whether the required tables, columns and settings are ready.

The CMS and its core Evolution options table must already exist. This installer installs the donation data structures; it does not install the CMS itself.

If an existing donation table is missing required columns, the installer stops for a database migration rather than automatically altering that table. Invalid existing settings also require review rather than being silently overwritten.

### 5. Check module visibility

Use the CMS Module Manager to discover/register the Donations module if necessary, activate it and select the visitor access level you want. Module visibility and the **Enable donations** setting are separate controls.

The public donation page is:

```text
https://your-domain.example/modules.php?name=Donations
```

Until installation is complete, visitors receive a configuration notice rather than an installer button. Fresh installations start with donations disabled.

## Create a PayPal sandbox app

Start with sandbox so you can test without moving real money. Dashboard labels can change; the linked PayPal documentation provides the current navigation.

1. Sign in at the [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/).
2. Open **Apps & Credentials** and select **Sandbox**.
3. Select **Create App**, give it a recognisable name such as `Evolution Xtreme Donations`, and associate it with your sandbox business account where prompted.
4. Open the app and copy its **Client ID** and **Secret**.
5. Locate the receiving sandbox business account in the dashboard's sandbox accounts area. Keep its test sign-in details available.
6. Sign in to that business account at [sandbox.paypal.com](https://www.sandbox.paypal.com/). In its account settings, open **Business information** and copy the **PayPal Merchant ID**.
7. Keep a separate sandbox personal account available to act as the donor.

Use the receiving business account's merchant ID, not its email address, the donor account ID, or the app's client ID. The module checks that PayPal payments belong to the configured merchant.

## Configure PayPal webhooks

Webhooks notify the site when PayPal payment status changes. They support reconciliation, including payments where a donor does not return to the site after checkout.

### Endpoint addresses

In the same PayPal REST app used for the matching credentials, add a webhook with the appropriate URL:

| Environment | Webhook URL |
| --- | --- |
| Sandbox | `https://your-domain.example/modules/Donations/webhook.php?mode=sandbox` |
| Live | `https://your-domain.example/modules/Donations/webhook.php?mode=live` |

If the CMS is installed in a subdirectory, include it, for example:

```text
https://your-domain.example/community/modules/Donations/webhook.php?mode=sandbox
```

The Donations dashboard displays the environment-specific webhook URLs derived from the configured site URL. Copy those values to avoid mistakes.

### Event selection

Subscribe to the following events handled by this package:

```text
PAYMENT.CAPTURE.COMPLETED
PAYMENT.CAPTURE.PENDING
PAYMENT.CAPTURE.DENIED
PAYMENT.CAPTURE.REFUNDED
PAYMENT.CAPTURE.REVERSED
```

Save the webhook, then copy its **Webhook ID** into the corresponding environment section of `config.php`.

The Webhook ID identifies the webhook registration. It is not a webhook event ID, order ID or capture ID. Sandbox and live registrations have their own IDs; create and configure each separately.

The endpoint verifies PayPal's signature through PayPal's API before processing an event. It must be reachable without interactive login or a browser challenge. Ensure firewalls, site login restrictions and security plugins allow genuine PayPal POST requests and preserve PayPal signature headers.

Opening the URL in a browser normally returns HTTP **405** because the endpoint accepts POST requests. This alone does not indicate a broken installation. Successfully processed verified events receive HTTP **204** with no response body.

## Create the API configuration file

Copy:

```text
modules/Donations/includes/config.example.php
```

to:

```text
modules/Donations/includes/config.php
```

Edit the copy and enter your own values:

```php
<?php

defined( 'NUKE_EVO' ) || exit;

return array(
    'sandbox' => array(
        'client_id'   => 'YOUR_SANDBOX_CLIENT_ID',
        'secret'      => 'YOUR_SANDBOX_SECRET',
        'merchant_id' => 'YOUR_SANDBOX_BUSINESS_MERCHANT_ID',
        'webhook_id'  => 'YOUR_SANDBOX_WEBHOOK_ID',
    ),
    'live' => array(
        'client_id'   => '',
        'secret'      => '',
        'merchant_id' => '',
        'webhook_id'  => '',
    ),
);
```

Replace every sandbox placeholder with the actual value. Leave live values blank until you are ready to configure live payments.

| Key | Value to supply |
| --- | --- |
| `client_id` | REST app Client ID for this environment |
| `secret` | Matching REST app Secret |
| `merchant_id` | Receiving business account's PayPal Merchant ID |
| `webhook_id` | ID of the webhook registered for this app and environment |

All four values must be non-empty for an environment to be reported as configured. The administration status checks the presence of configuration values; it does not prove that PayPal has accepted the credentials. Confirm the connection with an actual sandbox transaction.

Credentials are read from this PHP file, not entered through the donation settings form and not stored in the serialized donation settings option. The module obtains API access tokens itself; you do not need to paste a temporary access token into the file.

### Optional external configuration

For hosting that supports PHP environment variables, set `EVO_DONATIONS_CONFIG` to the absolute path of an alternative configuration file. It must return the same array and be readable by PHP. This allows credentials to be kept outside the public website directory. The variable must be available to the PHP process serving both checkout and webhook requests.

### Protect the credentials

Keep the real configuration out of public repositories, shared ZIP files and publicly downloadable backups. Share only the example file. Use restrictive file permissions that still allow the web server's PHP process to read it, and ensure the server executes PHP rather than serving PHP source as text.

## Donation settings

Return to Donations administration after installation and API configuration.

| Setting | Purpose | Fresh-install default |
| --- | --- | --- |
| Enable donations | Allows visitors to begin a donation | Off |
| PayPal environment | Selects sandbox or live credentials and payment records | Sandbox |
| Currency | GBP, USD, EUR, AUD or CAD | GBP |
| Minimum donation | Smallest accepted amount | 1.00 |
| Maximum donation | Largest accepted amount | 1,000.00 |
| Goal amount | Target amount; `0` hides/disables the goal display | 100.00 |
| Goal start date (UTC) | Includes donations created from this date in goal totals | Installation date in UTC |
| Allow guest donations | Allows checkout without a site member account | Off |

Enter monetary values in ordinary currency units, such as `5.00`; the module converts them to integer minor units internally. The minimum must be at least `1.00`, and the maximum must be at least the minimum and no greater than `1,000,000.00`.

Save the settings after making changes. Enable donations only after completing the configuration for the selected environment.

Changing the goal start date starts a different reporting period without deleting transaction history. The recent supporters list shows the five latest completed donations for the selected environment and currency; it is not restricted by the goal start date. Amounts are not converted between currencies.

## Enable the companion block

1. Upload `blocks/Donations/` and install the module as described above.
2. Open the CMS **Blocks Manager**.
3. Discover or refresh available blocks if your CMS requires it.
4. Add/select the Donations folder block, whose entry file is `Donations/index.php`.
5. Choose its title, position, order and audience, then activate it.
6. Check it on a page where that block position is displayed by your theme.

The block shares the module's settings, transaction data, goal and recent supporters. It has no separate PayPal credentials or database installer. Its button takes visitors to the full donation page, where checkout begins.

When donations are disabled, the block displays an unavailable message instead of the donation-page button. In sandbox mode it displays a test-payment notice.

Do not deploy the block alone or remove the module while leaving the block active: the block requires the module's helper files.

## Test the integration

1. Complete the built-in installation.
2. Configure all four sandbox values and register the sandbox webhook.
3. Select **Sandbox**, choose your currency and limits, enable donations, and save.
4. Visit `modules.php?name=Donations` as a permitted visitor. Sign in to the site if guest donations are disabled.
5. Enter an amount within the configured limits and choose whether to hide your username publicly.
6. Select **Continue to PayPal** and complete checkout with a sandbox personal buyer account, separate from the receiving business account.
7. Return to the website and check its confirmation message.
8. In Donations administration, check that the transaction is recorded with the expected amount, currency and completed status.
9. Check the module and block totals and recent supporters.
10. Inspect the sandbox app's webhook deliveries to confirm that real transaction notifications reach the endpoint successfully.

Also test cancellation and, if required, guest access and anonymity. For refund testing, issue the refund through the sandbox PayPal account, then check webhook delivery and the site's reconciled status.

A webhook simulator alone does not prove that a real donation will reconcile correctly. Use a genuine sandbox checkout created through this module for end-to-end verification.

If checkout reports an uncertain result, check PayPal and the administrator transaction record before trying again to avoid an unintended duplicate donation.

## Switch to live payments

1. Finish sandbox testing first.
2. In PayPal **Apps & Credentials**, select **Live** and create/open the REST app for the receiving live business account.
3. Copy its live Client ID and Secret, and obtain the live receiving account's Merchant ID.
4. Register the live webhook URL in that live app, subscribing to the same five events.
5. Copy the new live Webhook ID.
6. Fill in all four values under `live` in `config.php`.
7. In Donations administration, select **Live**, review currency, limits, goal and guest access, enable donations, and save.
8. Verify the public page no longer displays the sandbox notice and monitor the first live transaction and webhook deliveries.

Live payments move real money. Sandbox credentials and webhook IDs must never be mixed with live configuration. The module separates transaction records by environment, so sandbox donations do not contribute to live totals.

## Transactions and public totals

The administration dashboard lists donation records, PayPal references, supporter information, environment, amount, status and UTC creation date. Use **Check PayPal status** to reconcile a record against PayPal when investigating a delayed or uncertain payment.

Only qualifying `COMPLETED` transactions contribute to public totals. Totals are based on gross donation amounts, subject to the module's exclusions, rather than the net funds received after PayPal fees. Pending and unsuccessful payments are not counted as completed donations.

Refunds and reversals are reconciled through PayPal. In this version, partially refunded donations are conservatively excluded in full from public totals rather than displaying only the remaining amount. Review the actual refund amounts in PayPal.

Webhook processing updates existing module-created donation records; it is not a general importer for unrelated payments sent directly to your PayPal account.

Choosing anonymity hides the username in the public supporter list. Administrators can still see the transaction record. Payment details are handled by PayPal.

Module and block summaries use the Evo cache system. Reconciled payment changes clear the donation summary caches. Settings that affect summary selection are included in the cache signature.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| Donations administration is unavailable | Confirm files are in the correct folders, CMS discovery/cache is refreshed, and the administrator has Donations permissions. Use your configured administrator filename. |
| Installer fails | Check database connectivity, CREATE/read/write permissions and metadata access. Review server logs. Do not delete existing payment records to retry. |
| Installer reports incompatible tables | Back up the database and arrange a migration for the reported schema. The installer does not silently alter incompatible tables. |
| Credentials missing or incomplete | Check the exact `includes/config.php` path or external override, PHP syntax, file readability and all four values for the selected mode. |
| Configured status appears but checkout fails | Presence checks do not authenticate with PayPal. Check actual values, matching environments, merchant ID, cURL connectivity and PayPal account/app status. |
| Site URL must use HTTPS | Correct the CMS site URL, including any installation subdirectory, and verify the certificate. |
| Block is missing from the available list | Confirm the folder entry is `blocks/Donations/index.php`, refresh discovery/cache in Blocks Manager, then activate it in a visible theme position. |
| Visitors cannot donate | Check module audience, donations enabled, selected environment credentials, guest policy and amount limits. |
| Webhook returns 405 in a browser | Expected for a GET request. Check actual PayPal POST deliveries instead. |
| Webhook delivery fails | Check URL and `mode`, matching app/Webhook ID, installation state, signature headers, HTTPS reachability and firewall/login restrictions. |
| Webhook returns 400 | Possible invalid request, missing signature headers or failed signature verification. Check the registered webhook and matching environment values. |
| Webhook returns 503 | Installation, configuration, PayPal connectivity or reconciliation may not be ready. Inspect logs and delivery details; the endpoint requests redelivery for processing failures. |
| Payment approved but not confirmed | Approval and completed capture are different stages. Check the PayPal record, webhook delivery and **Check PayPal status** before donating again. |
| Donation missing from totals | Check status, environment, currency, goal start date and refund/reversal exclusions. |
| Goal total differs from PayPal balance | The goal uses qualifying gross donations from its selected date, not account balance or net amounts after fees. |

Never disable TLS certificate validation or webhook signature verification to work around a connection problem. Correct the certificate, connectivity or configuration issue instead.

## Updates, translations and removal

### Updates

Back up the database and private configuration before replacing files. Preserve `config.php` and review release-specific instructions. Both the module and block include their own `version.json` metadata; keep the appropriate file with each component.

The built-in installer is not a general upgrade engine for arbitrary schema changes. If a future release changes required columns, follow that release's migration instructions.

### Translations

English language files are supplied separately:

```text
modules/Donations/language/lang-english.php
blocks/Donations/language/lang-english.php
```

Add matching `lang-<language>.php` files in both locations when translating the module and block. Preserve array keys and PHP syntax, and translate the displayed text. The language loaders provide English fallback.

### Uninstall

The administration dashboard includes an uninstall screen with an explicit confirmation checkbox.

**Uninstall permanently removes donation history, donation settings, module/block registrations and attempts to delete both Donations folders, including the local `config.php`. It does not refund PayPal payments.**

Back up any records you need before proceeding. If filesystem permissions prevent full removal, follow the remaining-path list shown after uninstall. An external configuration file outside the module folder is not removed automatically.

After uninstalling, remove obsolete webhook registrations from the PayPal apps so PayPal no longer sends events to the removed endpoint. Review or revoke app credentials if the app is no longer used.

## Official PayPal references

The module-specific behaviour above is based on the supplied version 1.0.0 source. For PayPal account and dashboard procedures, refer to:

- [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/)
- [Get started with PayPal REST APIs](https://developer.paypal.com/api/get-started/)
- [Integrate webhooks](https://developer.paypal.com/api/rest/webhooks/rest/)
- [Find your PayPal Merchant ID](https://www.paypal.com/uk/cshelp/article/how-do-i-find-my-secure-merchant-id-in-my-paypal-account-help538)

PayPal dashboard wording and available account features can vary. Use credentials and webhook registrations belonging to the correct app, receiving account and environment.
