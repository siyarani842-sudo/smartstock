# SmartStock AI — Database Setup

This file contains every SQL query the project needs. The same queries are also
available as plain `.sql` files in this folder, so you can import them directly in phpMyAdmin.

| File | Purpose |
|---|---|
| `schema.sql` | Creates all tables (fresh install) |
| `upgrade_from_old_version.sql` | Moves data from the old single-file version into the new tables |
| `seeder.sql` / `SEEDER.md` | Demo data (products, suppliers, 6 months of orders) |

---

## 1. Which path should I use?

**A. Fresh install (recommended):** run **Section 3 — Full schema**, then the seeder from `SEEDER.md`.

**B. You already have the old `products` / `orders` tables with data you want to keep:**
run **Section 4 — Upgrade**. It renames your old tables to `*_backup` first, so nothing is lost.

> ⚠️ `schema.sql` starts with `DROP TABLE IF EXISTS` for the SmartStock tables.
> If you have data in the old `products` / `orders` tables, run Step 1 of Section 4 **before** the schema.

---

## 2. Tables overview

| Table | What it stores | Key relations |
|---|---|---|
| `categories` | Product categories | — |
| `warehouses` | Storage locations | — |
| `suppliers` | Supplier details, rating, quality score, lead time | — |
| `products` | Catalogue: SKU, prices, reorder level, image file name | `category_id → categories`, `supplier_id → suppliers` |
| `inventory` | **Current stock** per product per warehouse | `product_id`, `warehouse_id` (unique pair) |
| `orders` | Purchase orders (stock in) and sales orders (stock out) | `supplier_id`, `warehouse_id` |
| `order_items` | Products inside each order | `order_id`, `product_id` |
| `stock_movements` | **Ledger**: every stock change (+ in / − out) | `product_id`, `warehouse_id`, `order_id` |
| `prediction_runs` | Saved “Run Prediction” snapshots | — |
| `settings` | Key/value app settings | — |

### How automatic inventory sync works

```
Order saved / status changed / edited / deleted
        │
        ▼
sync_order()  (includes/inventory.php)
        │  works out what the order SHOULD contribute:
        │    purchase + Delivered            → +qty
        │    sale + In Transit / Delivered   → −qty
        │    Pending / Cancelled             → nothing (pending sale = reserved)
        ▼
compares with what is already in stock_movements
        │  posts only the difference → inventory.quantity
        │  refuses (and rolls back) if any warehouse would go below 0
        ▼
inventory  =  SUM(stock_movements)   ← always true
```

The **Sync Inventory** button (and the automatic sync in Settings) re-checks every order and
rebuilds `inventory` from the ledger, so totals can always be repaired.

Product images are **not** stored in the database. Only the file name is saved in `products.image`;
the file itself lives in `uploads/products/`.

---

## 3. Full schema (fresh install)

```sql
-- =========================================================
-- SMARTSTOCK AI - DATABASE SCHEMA
-- Creates every table the application needs.
-- WARNING: drops the SmartStock tables if they already exist.
-- =========================================================

CREATE DATABASE IF NOT EXISTS smartstock
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE smartstock;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS inventory;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS warehouses;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS prediction_runs;
DROP TABLE IF EXISTS settings;

SET FOREIGN_KEY_CHECKS = 1;


-- ---------------------------------------------------------
-- 1. CATEGORIES
-- ---------------------------------------------------------
CREATE TABLE categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- 2. WAREHOUSES
-- ---------------------------------------------------------
CREATE TABLE warehouses (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100) NOT NULL,
    code        VARCHAR(20)  NOT NULL,
    location    VARCHAR(255) NULL,
    manager     VARCHAR(100) NULL,
    phone       VARCHAR(30)  NULL,
    capacity    INT UNSIGNED NOT NULL DEFAULT 0,
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_warehouses_name (name),
    UNIQUE KEY uq_warehouses_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- 3. SUPPLIERS
-- ---------------------------------------------------------
CREATE TABLE suppliers (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(150) NOT NULL,
    supply_type     VARCHAR(100) NULL,            -- e.g. "Electronics Supplier"
    contact_person  VARCHAR(100) NULL,
    email           VARCHAR(150) NULL,
    phone           VARCHAR(30)  NULL,
    address         VARCHAR(255) NULL,
    lead_time_days  INT UNSIGNED NOT NULL DEFAULT 7,
    rating          DECIMAL(2,1) NOT NULL DEFAULT 0.0,   -- 0.0 - 5.0
    quality_score   DECIMAL(5,2) NOT NULL DEFAULT 0.00,  -- 0 - 100 %
    status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- 4. PRODUCTS  (image = file name inside uploads/products/)
-- ---------------------------------------------------------
CREATE TABLE products (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(150) NOT NULL,
    sku            VARCHAR(50)  NOT NULL,
    category_id    INT UNSIGNED NULL,
    supplier_id    INT UNSIGNED NULL,               -- preferred supplier
    subtitle       VARCHAR(150) NULL,               -- short tagline e.g. "Premium Audio"
    description    TEXT NULL,
    unit           VARCHAR(20)  NOT NULL DEFAULT 'pcs',
    cost_price     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    price          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reorder_level  INT UNSIGNED NOT NULL DEFAULT 10,
    image          VARCHAR(255) NULL,
    status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_sku (sku),
    KEY idx_products_category (category_id),
    KEY idx_products_supplier (supplier_id),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id)
        REFERENCES suppliers (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- 5. INVENTORY  (current stock of each product per warehouse)
--    quantity is always rebuilt from stock_movements by the
--    sync engine - never edit it by hand.
-- ---------------------------------------------------------
CREATE TABLE inventory (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id      INT UNSIGNED NOT NULL,
    warehouse_id    INT UNSIGNED NOT NULL,
    quantity        INT NOT NULL DEFAULT 0,
    last_synced_at  DATETIME NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_product_warehouse (product_id, warehouse_id),
    KEY idx_inventory_warehouse (warehouse_id),
    CONSTRAINT fk_inventory_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_inventory_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES warehouses (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- 6. ORDERS
--    order_type  purchase = stock IN  (from a supplier)
--                sale     = stock OUT (to a customer)
--    stock_applied = 1 when this order's items are already
--    posted to stock_movements / inventory.
-- ---------------------------------------------------------
CREATE TABLE orders (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_no        VARCHAR(30)  NOT NULL,
    order_type      ENUM('purchase','sale') NOT NULL DEFAULT 'purchase',
    supplier_id     INT UNSIGNED NULL,
    customer_name   VARCHAR(150) NULL,
    warehouse_id    INT UNSIGNED NOT NULL,
    status          ENUM('Pending','In Transit','Delivered','Cancelled') NOT NULL DEFAULT 'Pending',
    order_date      DATE NOT NULL,
    expected_date   DATE NULL,
    delivered_date  DATE NULL,
    total_value     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    notes           TEXT NULL,
    stock_applied   TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_order_no (order_no),
    KEY idx_orders_type_status (order_type, status),
    KEY idx_orders_supplier (supplier_id),
    KEY idx_orders_warehouse (warehouse_id),
    KEY idx_orders_date (order_date),
    CONSTRAINT fk_orders_supplier FOREIGN KEY (supplier_id)
        REFERENCES suppliers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_orders_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES warehouses (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- 7. ORDER ITEMS
-- ---------------------------------------------------------
CREATE TABLE order_items (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id    INT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    quantity    INT UNSIGNED NOT NULL,
    unit_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    line_total  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (id),
    KEY idx_order_items_order (order_id),
    KEY idx_order_items_product (product_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id)
        REFERENCES orders (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- 8. STOCK MOVEMENTS  (ledger - every stock change is a row)
--    quantity is signed: + stock in, - stock out
-- ---------------------------------------------------------
CREATE TABLE stock_movements (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id      INT UNSIGNED NOT NULL,
    warehouse_id    INT UNSIGNED NOT NULL,
    order_id        INT UNSIGNED NULL,
    movement_type   ENUM('opening','purchase','sale','adjustment') NOT NULL,
    quantity        INT NOT NULL,
    note            VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_movements_product_wh (product_id, warehouse_id),
    KEY idx_movements_order (order_id),
    KEY idx_movements_type_date (movement_type, created_at),
    CONSTRAINT fk_movements_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_movements_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES warehouses (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_movements_order FOREIGN KEY (order_id)
        REFERENCES orders (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- 9. PREDICTION RUNS  (history of "Run Prediction")
-- ---------------------------------------------------------
CREATE TABLE prediction_runs (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    horizon_months       TINYINT UNSIGNED NOT NULL DEFAULT 6,
    model                VARCHAR(60) NOT NULL DEFAULT 'Linear trend + moving average',
    accuracy             DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    expected_growth      DECIMAL(7,2) NOT NULL DEFAULT 0.00,
    potential_stockouts  INT UNSIGNED NOT NULL DEFAULT 0,
    recommended_orders   INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_savings    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    forecast_json        TEXT NULL,
    notes                VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_prediction_runs_run_at (run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------
-- 10. SETTINGS  (key / value)
-- ---------------------------------------------------------
CREATE TABLE settings (
    setting_key    VARCHAR(100) NOT NULL,
    setting_value  TEXT NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
('company_name',       'SmartStock AI'),
('admin_name',         'Admin User'),
('admin_role',         'Administrator'),
('currency_symbol',    '$'),
('low_stock_alerts',   '1'),
('ai_alerts',          '1'),
('auto_sync',          '1'),
('email_reports',      '0'),
('report_email',       ''),
('forecast_horizon',   '6'),
('safety_stock_days',  '7'),
('coverage_days',      '30');
```

---

## 4. Upgrade from the old single-file version

```sql
-- =========================================================
-- SMARTSTOCK AI - UPGRADE FROM THE OLD SINGLE-FILE VERSION
-- Use this ONLY if your database still has the old tables:
--   products (product_name, sku, category, price, stock)
--   orders   (order_id, suppliers, product, value, orderdate)
--
-- STEP 1 (this block): keep the old tables as a backup
-- STEP 2: run schema.sql
-- STEP 3 (block below): copy old products into the new tables
-- =========================================================

-- ---------- STEP 1 ----------
USE smartstock;
RENAME TABLE products TO old_products_backup;
RENAME TABLE orders   TO old_orders_backup;


-- ---------- STEP 2 ----------
-- Now run database/schema.sql


-- ---------- STEP 3 ----------
USE smartstock;

-- categories from the old free-text category column
INSERT IGNORE INTO categories (name)
SELECT DISTINCT TRIM(category)
  FROM old_products_backup
 WHERE category IS NOT NULL AND TRIM(category) <> '';

-- a default warehouse to hold the old stock
INSERT IGNORE INTO warehouses (name, code, location)
VALUES ('Main Warehouse', 'WH-MAIN', 'Default location');

-- products (cost price assumed 70% of price - edit later)
INSERT IGNORE INTO products (name, sku, category_id, cost_price, price, reorder_level, status)
SELECT TRIM(op.product_name),
       UPPER(TRIM(op.sku)),
       c.id,
       ROUND(CAST(op.price AS DECIMAL(12,2)) * 0.7, 2),
       CAST(op.price AS DECIMAL(12,2)),
       10,
       'active'
  FROM old_products_backup op
  LEFT JOIN categories c ON c.name = TRIM(op.category)
 WHERE TRIM(op.sku) <> '' AND TRIM(op.product_name) <> '';

-- old stock becomes opening stock in the ledger
INSERT INTO stock_movements (product_id, warehouse_id, movement_type, quantity, note)
SELECT p.id, w.id, 'opening', s.qty, 'Migrated from old products table'
  FROM (SELECT UPPER(TRIM(sku)) AS sku, MAX(CAST(stock AS SIGNED)) AS qty
          FROM old_products_backup
         GROUP BY UPPER(TRIM(sku))) s
  JOIN products p   ON p.sku = s.sku
  JOIN warehouses w ON w.code = 'WH-MAIN'
 WHERE s.qty > 0;

-- inventory totals from the ledger
INSERT INTO inventory (product_id, warehouse_id, quantity, last_synced_at)
SELECT product_id, warehouse_id, SUM(quantity), NOW()
  FROM stock_movements
 GROUP BY product_id, warehouse_id;

-- When you are happy with the result you can remove the backups:
-- DROP TABLE old_products_backup;
-- DROP TABLE old_orders_backup;
```

---

## 5. Adding or changing tables later

Templates you can copy when the project grows:

```sql
-- add a column
ALTER TABLE products ADD COLUMN barcode VARCHAR(64) NULL AFTER sku;
ALTER TABLE products ADD UNIQUE KEY uq_products_barcode (barcode);

-- change a column
ALTER TABLE suppliers MODIFY phone VARCHAR(40) NULL;

-- new table linked to products
CREATE TABLE product_batches (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id   INT UNSIGNED NOT NULL,
    batch_no     VARCHAR(50)  NOT NULL,
    expiry_date  DATE NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_batches_product (product_id),
    CONSTRAINT fk_batches_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- new setting
INSERT INTO settings (setting_key, setting_value) VALUES ('low_stock_email', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
```

> **Rule:** never change `inventory.quantity` with SQL directly. Add a row to
> `stock_movements` (or use *Inventory → Add / Adjust Stock*) and press **Sync Inventory**.

### Useful checks

```sql
-- inventory must equal the ledger (should return no rows)
SELECT i.product_id, i.warehouse_id, i.quantity, COALESCE(SUM(m.quantity), 0) AS ledger
  FROM inventory i
  LEFT JOIN stock_movements m ON m.product_id = i.product_id AND m.warehouse_id = i.warehouse_id
 GROUP BY i.id, i.product_id, i.warehouse_id, i.quantity
HAVING i.quantity <> ledger;

-- products at or below reorder level
SELECT p.sku, p.name, COALESCE(SUM(i.quantity), 0) AS stock, p.reorder_level
  FROM products p LEFT JOIN inventory i ON i.product_id = p.id
 GROUP BY p.id, p.sku, p.name, p.reorder_level
HAVING stock <= p.reorder_level;
```
