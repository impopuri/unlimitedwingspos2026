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

Install Termux from a current, supported source and open Termux before running setup. The setup installs PHP, Composer, and curl; installs the locked Composer dependencies; creates `.env` and an SQLite database only when missing; generates the app key only when missing; applies outstanding migrations; runs the idempotent seeders; and caches routes and compiled views for faster page startup. It does not drop or reset existing tables or sales.

After setup finishes, start the POS each day with:

```bash
cd ~/etivacwings
bash start-termux.sh
```

The daily start does not reinstall packages or rerun migrations. The default address is `http://127.0.0.1:8002/posetivacwingspos`; when available, the script opens it in the tablet browser. Keep Termux open while using the POS. Press Ctrl+C in Termux to stop it.

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

The server listens only on the tablet by default. To make it available to other devices on a trusted private Wi-Fi network, start it with `HOST=0.0.0.0 bash start-termux.sh` and open `http://<tablet-ip>:8002/posetivacwingspos` on the other device. Set `APP_URL=http://<tablet-ip>:8002` in `~/etivacwings/.env` using the tablet's actual Wi-Fi IP first so receipt/logo assets point to the tablet rather than the other device's localhost. Do not set `APP_URL` to `0.0.0.0`, and do not expose this local Laravel server to the public internet. The seeded local demo accounts use the passwords shown above; change them before operational use.

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
