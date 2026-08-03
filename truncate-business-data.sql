-- Clears all business/domain data so the system can be tested from empty.
-- KEEPS: users, and the framework tables (migrations, sessions, cache, jobs).
--
-- IRREVERSIBLE. A fresh offsite backup is taken before this is run — see
-- OneDrive\ImprintBackups. Raw materials can be reloaded afterwards by
-- importing the stock sheet again (Inventory → Import CSV).
SET FOREIGN_KEY_CHECKS = 0;

-- Orders and everything hanging off them
TRUNCATE TABLE production_orders;
TRUNCATE TABLE order_items;
TRUNCATE TABLE order_documents;
TRUNCATE TABLE clients;
TRUNCATE TABLE job_orders;
TRUNCATE TABLE job_order_files;
TRUNCATE TABLE tasks;
TRUNCATE TABLE task_files;
TRUNCATE TABLE payments;

-- Raw materials and finished products
TRUNCATE TABLE inventory_items;
TRUNCATE TABLE stock_movements;
TRUNCATE TABLE material_requests;
TRUNCATE TABLE product_items;
TRUNCATE TABLE product_movements;
TRUNCATE TABLE product_receipts;

-- Floor activity
TRUNCATE TABLE station_sessions;
TRUNCATE TABLE attendances;

-- Conversations
TRUNCATE TABLE messages;
TRUNCATE TABLE message_files;
TRUNCATE TABLE message_mentions;
TRUNCATE TABLE message_reads;

-- Bookkeeping
TRUNCATE TABLE expenses;

-- Alerts
TRUNCATE TABLE app_notifications;
TRUNCATE TABLE push_subscriptions;

SET FOREIGN_KEY_CHECKS = 1;
