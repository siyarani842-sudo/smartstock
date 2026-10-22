# SmartStock AI — Inventory Intelligence (Core PHP)

## Setup (XAMPP / WAMP / Laragon)

1. Copy the `smartstock` folder into `htdocs` (or `www`).
2. Open phpMyAdmin and import, in this order:
   - `database/schema.sql` creates all tables. If you still have the old `products`/`orders` tables with real data, follow `database/DATABASE.md` → *Upgrade* instead.
   - `database/seeder.sql` loads the demo data (optional).
3. Check the credentials in `config/db.php` (default `root` with no password, database `smartstock`).
4. Open <http://localhost/smartstock/>.

Requires PHP 7.4+ (8.x recommended) with `mysqli` and `fileinfo`, and MySQL 5.7+ / MariaDB 10.3+.
`uploads/products/` must be writable.

## Folder structure

```
smartstock/
├── index.php                  Dashboard
├── search.php                 Global search (topbar)
├── config/db.php              Database connection + BASE_URL
├── includes/
│   ├── bootstrap.php          Loaded first by every page (session, DB, helpers, CSRF)
│   ├── header.php             <head>, opens layout, includes sidebar + topbar
│   ├── sidebar.php            Navigation menu
│   ├── topbar.php             Page title, search, notifications
│   ├── footer.php             Closes layout, toasts, scripts
│   ├── functions.php          DB helpers (prepared statements), uploads, flash, pagination
│   ├── inventory.php          Automatic inventory sync engine
│   └── analytics.php          Forecasts, KPIs, supplier performance
├── modules/
│   ├── products/              index · add · edit · view · delete   (+ image upload)
│   ├── categories/            index · add · edit · delete
│   ├── warehouses/            index · add · edit · delete
│   ├── suppliers/             index · add · edit · delete
│   ├── orders/                index · add · edit · view · status · delete
│   ├── inventory/             index · adjust · movements · sync · delete
│   ├── predictions/           index · run · delete
│   ├── reports/               index · export (CSV)
│   └── settings/              index
├── assets/css/style.css       Original design (unchanged)
├── assets/css/app.css         Styles for forms, pagination, toasts…
├── assets/js/script.js
├── uploads/products/          Product images
└── database/                  DATABASE.md, SEEDER.md and the .sql files
```

## Template inheritance

Every page sets its title, then wraps its content between the shared header and footer:

```php
<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$pageTitle    = 'Products';
$pageSubtitle = 'Manage your complete product catalog';
$activePage   = 'products';      // highlights the sidebar item

include ROOT_PATH . 'includes/header.php';   // head + sidebar + topbar
?>
    ... page content ...
<?php include ROOT_PATH . 'includes/footer.php'; ?>
```

`add.php` and `edit.php` in each module share one `_form.php` (same validation and HTML).

## Automatic inventory sync

| Order | Status | Stock effect |
|---|---|---|
| Purchase | Delivered | **+ quantity** into the order's warehouse |
| Sale | In Transit / Delivered | **− quantity** from the order's warehouse |
| Sale | Pending | shown as **reserved** |
| Any | Cancelled / deleted | effect reversed |

- Creating, editing (items, quantities, warehouse), changing status or deleting an order updates stock immediately. Only the difference is applied.
- A sale cannot take more stock than the warehouse has, and a delivered purchase can't be reverted after its stock has been sold. The whole save is rolled back and an error is shown.
- Every change is recorded in `stock_movements` (*Inventory → Stock Ledger*), and `inventory` always equals the ledger total. **Sync Inventory** re-checks every order and rebuilds the totals. With *Settings → Automatic Inventory Sync* on, this also runs automatically, at most every 10 minutes.
- Manual changes (stock counts, damage, opening stock) go through *Inventory → Add / Adjust Stock*.

## Security

- All queries use prepared statements (the old version was open to SQL injection).
- Every form has a CSRF token, and deletes are POST-only with a confirmation.
- Output is escaped with `e()`.
- Uploads are checked by real MIME type (JPG/PNG/WEBP/GIF, max 2 MB) and saved with random names. Scripts can't run inside `uploads/`.
