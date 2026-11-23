USE laguerta_pos;

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin', 'user', 'cashier', 'customer') NOT NULL;

UPDATE users SET role = 'customer' WHERE role = 'user';

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin', 'customer', 'cashier') NOT NULL;

ALTER TABLE sales
    ADD COLUMN driver_name VARCHAR(120) NULL AFTER total,
    ADD COLUMN driver_commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER driver_name,
    ADD COLUMN driver_commission DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER driver_commission_rate;

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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_expenses_created_at (created_at),
    CONSTRAINT fk_expenses_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_expenses_amount_positive CHECK (amount > 0)
) ENGINE=InnoDB;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('driver_commission_rate', '10.00');
