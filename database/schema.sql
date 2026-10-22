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
