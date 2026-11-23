USE laguerta_pos;

ALTER TABLE menu_items
    ADD COLUMN item_type ENUM('food', 'drink') NOT NULL DEFAULT 'food' AFTER category,
    ADD KEY idx_menu_type_active (item_type, is_active);

CREATE TABLE IF NOT EXISTS customer_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    service_type ENUM('dine_in', 'takeout', 'advance_booking', 'catering') NOT NULL,
    event_at DATETIME NULL,
    party_size INT UNSIGNED NULL,
    customer_notes TEXT NULL,
    total DECIMAL(10,2) NOT NULL,
    sale_id BIGINT UNSIGNED NULL,
    status ENUM('pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    staff_notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_orders_status_created (status, created_at),
    KEY idx_customer_orders_customer (customer_id, created_at),
    UNIQUE KEY uq_customer_orders_sale (sale_id),
    CONSTRAINT fk_customer_orders_customer FOREIGN KEY (customer_id) REFERENCES users (id),
    CONSTRAINT chk_customer_order_total_nonnegative CHECK (total >= 0),
    CONSTRAINT chk_customer_order_party_size_positive CHECK (party_size IS NULL OR party_size > 0)
) ENGINE=InnoDB;

ALTER TABLE customer_orders
    ADD CONSTRAINT fk_customer_orders_sale FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS customer_order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    menu_item_id BIGINT UNSIGNED NULL,
    item_name VARCHAR(120) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_customer_order_items_order (order_id),
    CONSTRAINT fk_customer_order_items_order FOREIGN KEY (order_id) REFERENCES customer_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_order_items_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items (id) ON DELETE SET NULL
) ENGINE=InnoDB;
