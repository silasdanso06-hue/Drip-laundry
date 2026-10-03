# Owner guide

## Daily use

- Prices: http://localhost/Driplaudary/admin.php
- Orders and payments: http://localhost/Driplaudary/admin-orders.php
- Paystack setup and payment checks: http://localhost/Driplaudary/admin-payments.php
- Customer SMS: http://localhost/Driplaudary/sms.php
- Business details and backups: http://localhost/Driplaudary/admin-business.php

All owner tools require your owner password. Customers use separate accounts and cannot update order or payment statuses.

In the owner price editor, change **Item name**, **Category**, and the service prices, then choose **Save items & prices**. Category accepts an existing name or a new one. Saved changes appear on the customer Prices page and in new carts, orders and invoices. Previously saved orders retain their original item details. Printed/downloaded price-list PDFs are snapshots; regenerate them after changing the catalogue. Keep using the live localhost website for current prices; file previews use the catalogue from the last browser build.

Change laundry status to Received, Being cleaned, Ready, Delivered or Cancelled. Record payment as Unpaid, Paid or Refunded. The customer dashboard refreshes orders when opened. Prepare customer SMS opens the owner composer; sending is an explicit action and uses provider credits.

## Prices and invoices

Wash & fold prices are shown in the live catalogue; blank prices request a quote. The Wash & iron service card directs customers to contact the business for pricing; online item checkout currently supports wash & fold. Use the editor to make future changes. Invoices use the prices saved at booking, not today's catalogue, and reflect owner-confirmed payment status. Customers must be signed in to download their own invoices.

New orders receive 5% off when the subtotal is above GHS 100 and below GHS 200, or 10% off at GHS 200 and above. Exactly GHS 100 has no discount. Only the highest qualifying rate applies. Discounts are rounded to the nearest pesewa before being subtracted. Checkout, saved orders, owner records, invoices and Paystack all use the same final amount. The saved subtotal, rate and discount preserve the calculation for each order. Older saved orders retain their original totals.

The shared discount policy is in `assets/data/discount-policy.json`; PHP reads it when saving orders and the browser receives it with the catalogue. Rebuild `assets/js/app.js` with `php tests/build-browser.php` after editing the policy so file previews also match.

Wash & fold also has prices per load: 6 kg for GHS 50, 7 kg for GHS 60, and 8 kg for GHS 70. These appear under Laundry by weight and can be edited in the owner price editor. Quantity means the number of loads, not the number of kilograms. The existing order discounts apply to the combined subtotal of items and loads.

## Accounts and storage

Customer accounts, orders, prices, owner settings, SMS settings, account codes and notification records are saved in `private/data/store.php`. This is a PHP file containing an array, not an SQL database. Orders remain linked to customer IDs. Temporary carts stay in the browser; login sessions use protected server files.

The storage code is in `lib/php-storage.php`, loaded through `lib/database.php`. It locks data while updating and replaces the complete file only after validating the saved copy. Manage data through the website instead of editing the PHP data file while the site is running. The old SQLite file and pre-migration backup are retained for recovery and are no longer used by the site.

PHP and Apache must be running; MySQL is not required. PHP file storage is intended for this small, single-server site. It reads and rewrites the full store, so review the storage design if the site grows substantially or needs multiple servers.

Customers can sign up with phone or email and a password of their chosen length, up to the hashing limit of 72 bytes. Email signup does not require a separate phone number. Saved phone numbers support login. Passwords are hashed. Contact verification is available from the dashboard and password recovery from the login page. SMS verification needs an approved sender and credits; email verification needs configured server mail delivery and `DRIPCLEAN_MAIL_FROM`. Password resets invalidate existing sessions.

If no customer records exist, the account page offers registration first. A customer must create an account before signing in; owner credentials are separate. At the storage migration, the active database and retained backups contained no customer records.

## Online payments with Paystack

Open Online payments from the owner price editor. Add your Paystack secret key privately, select Test mode, set the site URL to `http://localhost/Driplaudary`, and enable checkout. Customers can choose online payment at checkout or pay a saved unpaid order from their dashboard. Phone-only accounts enter a receipt email on the payment page; this does not change their sign-in details.

Paystack hosts the Mobile Money/card form. Amounts come from the saved order, in GHS. The site verifies each transaction with Paystack before recording payment and verifies webhook signatures. Duplicate notifications do not duplicate payment history. Pending or uncertain payments show a status check instead of silently opening another payment. Card details, PINs and reusable card authorizations are not stored on this site.

MTN MoMo and Telecel Cash are supported through Paystack's Mobile Money channel. The existing server request enables `card` and `mobile_money`; Paystack handles the network choice, wallet number and approval prompts. One Paystack secret key connects this checkout; separate MTN and Telecel keys are not required. Enable Mobile Money in your Ghana Paystack dashboard. See [Paystack's supported Mobile Money networks](https://support.paystack.com/en/articles/2128386) and [payment channels](https://paystack.com/docs/payments/payment-channels/).

Test payments are clearly labelled and do not mark real orders paid. To accept actual money, activate your Paystack business, publish the site with HTTPS, choose Live mode and save the live secret key. Set your Paystack webhook to `https://YOUR-SITE/payment-webhook.php` (include any site subdirectory). Callback URL is set automatically to the configured base URL plus `/payment-return.php`. Localhost cannot receive Paystack webhooks; use the return page or Check payment status for local tests.

An uncertain initialization must be checked before retrying. If it remains unknown, check the reference in Paystack before arranging another payment. Online payments received after cancellation or after an order was already paid are flagged for owner review. Issue refunds in Paystack before marking an order Refunded; the order editor records refunds but does not initiate them. Previously used verification keys are kept privately so pending transactions can still be verified after key rotation.

All payment settings and attempts are included in PHP data backups. The automated payment tests use a fake provider; no real payment has been processed during implementation. Setup and a Paystack test transaction are still needed.

Provider documentation: [transactions](https://paystack.com/docs/api/transaction/), [verification](https://paystack.com/docs/payments/verify-payments/), [webhooks](https://paystack.com/docs/payments/webhooks/).

## SMS activation

The configured sender is currently ?Silas Tomy?. The authorized test returned `DS_REJECTED_SENDER_UNREGISTERED`. Save the exact approved sender name after SMSOnlineGH approves it; the earlier requested name was ?DRIPLAUNDRY?. Do not assume a provider response means delivery.

Welcome SMS runs after successful login/signup when a saved phone and provider credentials exist, with a five-minute duplicate cooldown. No message is sent merely by refreshing the page. Failed SMS does not undo login. Recovery/verification codes are sent only when requested. API keys stay in the private database.

## Business information

The public information page displays 24-hour operation and free pickup/delivery. Add service areas, cancellation/refund rules and privacy information in Business settings. Unspecified fields direct customers to contact you.

## Backups and deployment

Business settings can create and download a consistent `.php` backup of all saved application data. The CLI tool `tools/backup-database.php` creates the same backup in `private/backups`. Keep an off-server copy private. Site assets and active login sessions are separate; back up the project folder when moving hosts.

See LAUNCH.md for hosting prerequisites, mail setup, backups and restore instructions. Domain purchase, hosting, SMS approval and email transport activation are still outstanding. The private directory and backups must stay blocked from web access after deployment.
