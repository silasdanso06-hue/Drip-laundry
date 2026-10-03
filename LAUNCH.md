# Launch and operations

Implemented: customer accounts, orders and settings in protected PHP file storage; owner-only order/payment management; invoices generated from immutable saved prices; contact verification and password reset codes; editable business information; consistent PHP data backups.

## Before customers use the public site

1. Approve the SMS sender in SMSOnlineGH and save the exact approved spelling in SMS settings. The authorized test on 1 October 2026 returned `DS_REJECTED_SENDER_UNREGISTERED`. No successful delivery has been confirmed. Check provider credit and approval before another test.
2. Configure email delivery on the hosting server and set `DRIPCLEAN_MAIL_FROM` to your verified business email address. The application uses PHP's `mail()` transport; its delivery must be configured by the host. Email codes cannot be delivered with the current unconfigured local email service. Phone codes depend on the SMS sender approval.
3. In Business settings, supply the service areas and cancellation/refund rules. Opening hours are 24/7 and pickup/delivery are free, as instructed. The public information page directs customers to contact you for unspecified details.
4. Choose a domain and a PHP 8.1+ hosting account with mbstring, cURL, OpenSSL, GD and iconv enabled, a writable private folder on local disk, and HTTPS. A MySQL/SQL service is not required. PDO SQLite is only needed for the one-time import of an old SQL backup. No hosting or domain has been purchased or published.
5. Keep `private` inaccessible on the host. Apache must honor `private/.htaccess`; Nginx requires an equivalent deny rule. Verify HTTP access to the database, configuration and backups returns 403 or 404 before launch. Do not upload local test output or private setup links into public download directories.
6. Set the server timezone to the business location. Use HTTPS so PHP session cookies are marked Secure. Test signup/login, order placement, invoice download and owner updates on the deployed URL.
7. Keep production secrets in the private settings/database and server environment. Replace localhost links in account help text with the deployed URL when it is known.

## Backups

Use Owner → Business settings → Create and download backup, or run `C:\xampp\php\php.exe C:\xampp\htdocs\Driplaudary\tools\backup-database.php` from Windows Task Scheduler daily. Scheduling has not been enabled automatically. Backups contain personal data and credentials; keep an encrypted copy outside the web server. Old backups are not automatically deleted.

Restore while Apache is stopped: retain a copy of `private/data/store.php`, replace it with a trusted `.php` data backup from this site (named `store.php`), then restart Apache and check login and orders. Keep `private/data/.htaccess` in place. Only restore your own trusted backups, since PHP files can contain executable code. Restoring an older backup loses records created after that backup; confirm the chosen backup first.

The migration tool `tools/migrate-to-php.php` retains the original SQLite database and creates a verified SQL backup before importing. It refuses to overwrite an existing PHP store. The older SQL setup tools are retired and do not change data. Do not replace `store.php` with a `.sqlite` backup; those formats are different.

## Owner workflow

Open `admin-orders.php`, find the customer order, set laundry and payment status, and save. Customers see these statuses when their orders reload. “Prepare customer SMS” fills in the owner's SMS form; review and send it explicitly. Cash and arranged Mobile Money are recorded manually. Configured Paystack payments are verified by the server and recorded automatically.

## Paystack activation

Online payments are disabled until the owner adds a secret key and enables them in `admin-payments.php`. Start with a Paystack test key and Test mode. Complete an actual provider test checkout before launch; implementation tests use mocked provider responses and have not charged money. Use an email for payment receipts even if the customer signs in with a phone number.

For live payments, activate the Paystack business, publish the site at a public HTTPS URL, set that URL in payment settings, and use a live secret key with Live mode. In Paystack, enable the available Ghana Mobile Money and card channels and configure `YOUR-BASE-URL/payment-webhook.php`. The return page is `YOUR-BASE-URL/payment-return.php`. Webhooks cannot reach localhost. Verify a successful payment, a cancellation and a pending payment on the published site before opening checkout to customers. Refunds are issued in Paystack and then recorded in the order editor; refund API calls are not implemented.

Invoices now require customer login and a matching saved order. Later price changes do not alter old invoices. The payment label reflects owner-confirmed status.

## Account help

`recover.php` sends a short-lived code to a contact already stored on the account. Codes expire in ten minutes, allow five attempts and can be used once. `recover.php?purpose=verify` verifies the signed-in customer's saved contact. Password resets invalidate existing customer sessions. Delivery setup above must be completed before using these flows with real customers.
