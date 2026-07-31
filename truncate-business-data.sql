-- Clears all business/domain data, KEEPS users + framework tables.
-- Reviewed by Claude on 2026-07-31. IRREVERSIBLE — a fresh offsite backup was
-- taken first (OneDrive\ImprintBackups\imprint-backup-20260731-150508.zip).
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE production_orders;
TRUNCATE TABLE order_items;
TRUNCATE TABLE clients;
TRUNCATE TABLE job_orders;
TRUNCATE TABLE job_order_files;
TRUNCATE TABLE tasks;
TRUNCATE TABLE task_files;
TRUNCATE TABLE payments;
TRUNCATE TABLE order_documents;
TRUNCATE TABLE material_requests;
TRUNCATE TABLE inventory_items;
TRUNCATE TABLE stock_movements;
TRUNCATE TABLE product_items;
TRUNCATE TABLE product_movements;
TRUNCATE TABLE product_receipts;
TRUNCATE TABLE station_sessions;
TRUNCATE TABLE attendances;
TRUNCATE TABLE app_notifications;
TRUNCATE TABLE push_subscriptions;
SET FOREIGN_KEY_CHECKS = 1;
