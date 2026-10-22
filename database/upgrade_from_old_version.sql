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
