# Dripclean Laundry

Laundry website with customer accounts, editable prices, orders, PDF invoices and optional Paystack/SMS integrations. Built with PHP and plain HTML, CSS and JavaScript. No npm build or SQL server is required.

## Project layout

```text
assets/
  css/       Stylesheets (site.css contains the main page styles)
  js/        Browser code and generated app.js bundle
  images/    Logos and photographs
  videos/    Laundry videos
  data/      Public starter catalogue and discount policy
  notes/     Image credits and creation notes
lib/         Shared PHP application code
tools/       Command-line setup, maintenance and packaging tools
tests/       Backend, HTTP and JavaScript checks
private/     Local accounts, orders, secrets and backups (not uploaded)
index.html   Customer website
*.php        Customer and owner endpoints
```

## Run locally

1. Use PHP 8.1+ with mbstring, cURL, OpenSSL, GD, DOM, iconv and ZIP extensions. XAMPP includes Apache and PHP.
2. Place this folder in `C:/xampp/htdocs/Driplaudary` and start Apache.
3. From the project directory run:

   ```sh
   php tools/install.php
   php tools/setup-owner.php
   ```

   If PHP is not on PATH, use `C:/xampp/php/php.exe` instead of `php`.
4. Open `http://localhost/Driplaudary/`. Open the one-time owner setup link saved in `private/owner-setup.txt` to create the owner account. Keep that link private.

The installer refuses to replace existing data. A fresh install starts without customer accounts, orders or payment/SMS credentials. Apache must honor `private/.htaccess`. See [LAUNCH.md](LAUNCH.md) before production deployment and [OWNER-GUIDE.md](OWNER-GUIDE.md) for daily operation.

## Edit assets

Edit `assets/js/app.mjs` and `assets/js/cart.mjs`, then rebuild:

```sh
php tests/build-browser.php
```

`assets/js/app.js` is generated and included so the site works immediately. Edit CSS directly in `assets/css/`. CSS image paths are relative to that folder (`../images/...`).

The live catalogue is stored privately and edited through `admin.php`. `assets/data/catalog.json` is the public starter/file-preview catalogue. After intentionally updating that JSON, run `php tools/refresh-static-prices.php` and rebuild the browser bundle. These commands do not change live prices.

## Checks

```sh
php tests/catalog-editor-test.php
php tests/invoice-test.php
php tests/site-http-smoke.php
php tests/export-dom.php
node tests/navigation-test.mjs
```

HTTP tests require Apache at the localhost URL above. JavaScript tests require Node.js. Historical migration and dated price-update tools are for specific maintenance tasks; do not run them during normal installation.

## Upload to GitHub

Run `php tools/package-github.php` to create `dist/dripclean-github.zip`. Extract the ZIP and upload its **contents** into your repository, including `.gitignore`, `.gitattributes` and `private/.htaccess`. The ZIP excludes private records, credentials, backups, sessions and test output. Do not upload the entire working folder through the browser: browser uploads do not apply `.gitignore`.

Alternatively, with Git installed, run `git init`, `git add .`, review `git status`, then commit and connect your GitHub repository. `.gitignore` excludes local runtime files; it does not remove files previously committed.

GitHub stores this source code. GitHub Pages cannot run PHP, customer accounts, order saving or payments; the full application needs PHP hosting. No credentials are included in the repository or upload ZIP. Configure integrations privately after installation.
