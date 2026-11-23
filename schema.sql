CREATE DATABASE IF NOT EXISTS laguerta_pos
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE laguerta_pos;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(80) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    phone_number VARCHAR(30) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'customer', 'cashier') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customer_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category ENUM('restaurant', 'website') NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    reviewer_name VARCHAR(120) NULL,
    review_text TEXT NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_feedback_visible_created (is_visible, created_at),
    KEY idx_customer_feedback_category_visible (category, is_visible),
    CONSTRAINT chk_customer_feedback_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS menu_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(60) NOT NULL,
    item_type ENUM('food', 'drink') NOT NULL DEFAULT 'food',
    price DECIMAL(10,2) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_menu_item_name_category (name, category),
    KEY idx_menu_category_active (category, is_active),
    KEY idx_menu_type_active (item_type, is_active),
    CONSTRAINT chk_menu_price_nonnegative CHECK (price >= 0)
) ENGINE=InnoDB;

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
    deleted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_customer_orders_status_created (status, created_at),
    KEY idx_customer_orders_customer (customer_id, created_at),
    KEY idx_customer_orders_deleted_created (deleted_at, created_at),
    UNIQUE KEY uq_customer_orders_sale (sale_id),
    CONSTRAINT fk_customer_orders_customer FOREIGN KEY (customer_id) REFERENCES users (id),
    CONSTRAINT chk_customer_order_total_nonnegative CHECK (total >= 0),
    CONSTRAINT chk_customer_order_party_size_positive CHECK (party_size IS NULL OR party_size > 0)
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS sales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cashier_id BIGINT UNSIGNED NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    pwd_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    pwd_discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    driver_name VARCHAR(120) NULL,
    driver_commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    driver_commission DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sales_created_at (created_at),
    KEY idx_sales_cashier (cashier_id),
    CONSTRAINT fk_sales_cashier FOREIGN KEY (cashier_id) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by BIGINT UNSIGNED NOT NULL,
    description VARCHAR(160) NOT NULL,
    category VARCHAR(60) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    rate_percent DECIMAL(5,2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_expenses_created_at (created_at),
    CONSTRAINT fk_expenses_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_expenses_amount_positive CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sale_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_id BIGINT UNSIGNED NOT NULL,
    menu_item_id BIGINT UNSIGNED NULL,
    item_name VARCHAR(120) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sale_items_sale (sale_id),
    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_items_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items (id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE customer_orders
    ADD CONSTRAINT fk_customer_orders_sale FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE SET NULL;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('driver_commission_rate', '10.00'),
    ('pwd_discount_rate', '20.00');

INSERT IGNORE INTO menu_items (name, category, price) VALUES
    ('Grilled Fish with Rice', 'Seafood', 279.00),
    ('Kinilaw', 'Seafood', 330.00),
    ('Camaron', 'Seafood', 330.00),
    ('Buffalo Shrimp', 'Seafood', 330.00),
    ('Gambas', 'Seafood', 330.00),
    ('Squid Adobo', 'Seafood', 330.00),
    ('Calamares', 'Seafood', 330.00),
    ('Buttered Chicken', 'Chicken', 330.00),
    ('Chicken Curry', 'Chicken', 330.00),
    ('Chicken Biryani', 'Chicken', 330.00),
    ('Beef Kare-Kare with Rice', 'Beef', 400.00),
    ('Beef Caldereta', 'Beef', 330.00),
    ('Grilled Pork with Rice', 'Pork', 279.00),
    ('Sisig', 'Pork', 330.00),
    ('Pork Lumpia', 'Pork', 259.00),
    ('Bam-E', 'Noodles', 320.00),
    ('Sotanghon Guisado', 'Noodles', 320.00),
    ('Garlic Rice Platter', 'Rice', 180.00),
    ('Plain Rice Platter', 'Rice', 160.00),
    ('Plain Cup Rice', 'Rice', 35.00);
