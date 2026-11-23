USE laguerta_pos;

ALTER TABLE customer_orders
    ADD COLUMN deleted_at DATETIME NULL AFTER staff_notes,
    ADD KEY idx_customer_orders_deleted_created (deleted_at, created_at);
