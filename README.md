# ETIVAC WINGS POS SYSTEM

Laravel 12 application using PHP 8.2+, Blade, vanilla JavaScript, and SQLite for a single-device local installation. The app serves its screens beneath `/posetivacwingspos`.

## Start on Windows

Double-click [`START-ETIVAC-WINGS-POS.bat`](./START-ETIVAC-WINGS-POS.bat). The script prepares the SQLite database, runs outstanding migrations and safe seed data, starts the Laravel server on port 8002, and opens:

`http://localhost:8002/posetivacwingspos`

The PHP installation needs `pdo_sqlite` and `fileinfo`. This machine's PHP has the Fileinfo DLL installed but disabled globally; the batch file loads it for the app process only. No global PHP configuration is changed.

To use another device on the same trusted LAN, open `http://<host-computer-ip>:8002/posetivacwingspos`. The server binds to all network interfaces. Configure Windows Firewall deliberately for the private LAN only; do not expose this development server to the public internet.

## Run on Android with Termux

The Termux setup is a **one-time install**. Copy the project to the tablet's Termux home directory first; SQLite should not be used directly from Android shared storage:

```bash
termux-setup-storage
cp -a ~/storage/shared/Download/etivacwings ~/etivacwings
cd ~/etivacwings
bash setup-termux.sh
```

Install Termux from a current, supported source and open Termux before running setup. The setup updates and upgrades Termux packages first (to keep native libraries such as PHP and libcurl compatible), installs PHP, Composer, and curl, installs the locked Composer dependencies, creates `.env` and an SQLite database only when missing, generates the app key only when missing, clears stale configuration, applies outstanding migrations and idempotent seeders, then clears application caches and caches routes and compiled views for faster page startup. Migrations intentionally run before clearing the application cache because the database cache table is created by a migration. Setup does not drop or reset existing tables or sales.

If `pkg update` or `pkg upgrade` fails with `CANNOT LINK EXECUTABLE` / `SSL_set_quic_tls_early_data_enabled`, the Termux packages are partially upgraded and the `pkg` wrapper cannot start because it uses the broken `curl`. Use `apt` directly to repair the package set:

```bash
apt update
apt full-upgrade -y
apt install -y php composer curl
php -v
php -m | grep -Ei 'pdo_sqlite|sqlite3'
```

Run these commands one at a time in Termux and wait for each to finish. If `apt update` reports download or repository errors, run `termux-change-repo`, select a reachable official Termux main repository mirror, and retry the `apt` commands. The “No mirror or mirror group selected” notice by itself is not the PHP linker error; the `CANNOT LINK EXECUTABLE` message is the failure to resolve first.

Once `php -v` works and both SQLite modules appear, retry `bash setup-termux.sh`. If direct `apt` also cannot run, do not delete the project or database. Back up `~/etivacwings` (especially `database/database.sqlite`, `.env`, and `storage/app/public`) to shared storage before attempting any Termux reinstall; reinstalling Termux can erase its private home directory.

After setup finishes, start the POS each day with:

```bash
cd ~/etivacwings
bash start-termux.sh
```

The daily start does not reinstall packages or rerun migrations. It listens on the tablet's Wi-Fi interface and prints both the tablet-local address and a detected **For other devices on the same Wi-Fi** link, for example `http://192.168.1.25:8002/posetivacwingspos`. Open the printed Wi-Fi link on each phone/tablet/computer connected to the same Wi-Fi network. When available, the script opens the local address in the tablet browser. Keep Termux open while using the POS. Press Ctrl+C in Termux to stop it.

If the script cannot detect the tablet IP, connect the tablet to Wi-Fi and check **Android Settings → About tablet → Status → IP address**, then open `http://<that-ip>:8002/posetivacwingspos` on the other device. If the older local-only server is still running, stop it with Ctrl+C and start again using the updated `start-termux.sh`. The network link is for a trusted private Wi-Fi network only; do not expose this server to the public internet. To keep the server tablet-only, start with `HOST=127.0.0.1 bash start-termux.sh`.

### Updating the tablet with a newer project version

Stop the running server with Ctrl+C. Before updating, make a database backup and keep the receipt QR uploads and local settings:

```bash
cd ~/etivacwings
cp database/database.sqlite ~/etivacwings-database-backup-$(date +%Y%m%d-%H%M%S).sqlite
pkg install rsync
rsync -a --exclude='.env' --exclude='/database/database.sqlite' --exclude='/storage/app/public/' ~/storage/shared/Download/etivacwings/ ~/etivacwings/
cd ~/etivacwings
php artisan migrate --force
php artisan route:cache
php artisan view:cache
bash start-termux.sh
```

This keeps the tablet's `.env`, SQLite sales database, and uploaded receipt assets instead of replacing them with the desktop copies. If the update changes `composer.json` or `composer.lock`, run `bash setup-termux.sh` after copying the update so Composer dependencies are installed as well. Never reset or delete the tablet database during an update.

The daily start uses the tablet's current Wi-Fi IP for generated asset URLs automatically. For a Wi-Fi address that changes often, no manual `.env` edit is needed. Never set `APP_URL` to `0.0.0.0`. The seeded local demo accounts use the passwords shown above; change them before operational use.

## Development accounts

| Role | Username | Password |
|---|---|---|
| Super admin | `admin` | `password` |
| Cashier | `cashier` | `password` |
| Kitchen | `kitchen` | `password` |

These are local-only demo accounts. Change every password and configure production-grade sessions, HTTPS, secrets, backups, and a production web server before deployment. Seeding is idempotent and does not reset existing passwords or stock quantities.

## Implemented

- Session-backed login, password hashing, and server-side role middleware.
- Five menu categories and requested initial product prices, including Set D at ₱249.
- Persistent cart checkout, server-side price/flavor validation, receipts, cash change, idempotency keys, and piece-based wing stock deductions.
- Six Unliwings tables, authorized table creation, package/customer billing, exact flavor-quantity round deductions, kitchen tickets, and billout.
- Included Unliwings rice is automatically sent to the kitchen in round one, with the requested quantity matching paid sets/customers.
- Regular POS delivery orders accept a cashier-entered delivery fee; it is itemized on the receipt and tracked separately from sales in cashflow, sales reports, and CSV exports.
- Superadmin-managed promotions with configurable full-paying-companion/free-customer groups, eligible Unliwings billout savings, receipt snapshots, and customer/transaction usage counts that remain available after a promotion is deleted.
- Tablet-friendly kitchen board with persistent Preparing/Cooking/Served statuses and periodic refresh.
- Receipt preview, clipboard copy, browser print, and PNG export.
- ETIVAC WINGS artwork from `public/images/EWLOGO.png` is used on login, POS header, and printed/exported receipts.
- A successful checkout clears the cart and presents a **New order** action to start a clean transaction immediately.
- Meals, platters, and bilao may use any 1-to-maximum number of distinct flavors, provided the wing allocation still equals the product's wing count.
- Unliwings is handled only through dine-in table sessions; it is hidden from the regular POS menu and rejected by regular-order APIs.
- Superadmin cashflow views paid sales, refunds, and expenses by date range, separates cash from non-cash sales, and supports recording, editing, and voiding expenses with payment method and reference.
- Superadmin account management creates staff usernames, updates roles/passwords, and deactivates or reactivates accounts.
- Configurable categories and flavors, product availability, and per-product ingredient recipes. Recipe quantities are entered by an administrator; none are guessed or preconfigured.
- Transactional, recipe-based ingredient deductions and stock restoration on an approved void. Wing stock is still deducted by exact wing count, and a `WINGS` recipe is skipped to prevent duplicate deductions.
- Inventory item creation, opening stock, cost and supplier fields, low-stock alerts, adjustments with movement history, and CSV sales export.
- Daily cash drawer opening, cash-in/out recording, expected closing cash, counted cash, and variance; non-cash payments remain separate.
- Filterable sales reports with totals, refunds and voids, products, flavors, categories, cashiers, order types, day/hour breakdowns, CSV export, and browser print.
- Cashier void/refund requests, superadmin review, persistent receipt reload from recent orders, kitchen archive/history, and audit log views.
- SQLite migrations, seed data, and focused workflow tests.

## Operational notes and limitations

- Recipe quantities are intentionally left unconfigured until the restaurant enters verified serving amounts. Recipe deductions apply only after a recipe is saved for a product.
- The receipt **Print** action uses the browser's print dialog. Direct ESC/POS thermal-printer integration, payment-terminal integration, and automated cash drawer hardware control are not included.
- Refunds require superadmin approval. This build records the refund and cash movement but does not issue a payment or return consumed ingredients.
- Cash drawer reconciliation is user- and business-date-based. Assign one cashier account to a physical drawer during each business day.
- Roles and payment/order types are fixed to the built-in options; configurable permission matrices, discount rules, and an in-app backup/restore screen are not included.
- This is a local POS application, not a production accounting system. Before production use, change demo passwords, configure HTTPS and secure sessions, verify backup/restore, review security, and complete operational acceptance testing.

## Database backup

Stop the server before copying `database/database.sqlite`. Keep encrypted backups outside the project directory and periodically verify that a copy can be restored. To restore, stop the server and replace that SQLite file with a verified backup. Do not run `migrate:fresh` on a database containing operational data.
