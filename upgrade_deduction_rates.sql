USE laguerta_pos;

ALTER TABLE sales
    ADD COLUMN pwd_discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER pwd_discount_amount;

ALTER TABLE expenses
    ADD COLUMN rate_percent DECIMAL(5,2) NULL AFTER amount;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('driver_commission_rate', '10.00'),
    ('pwd_discount_rate', '20.00');

UPDATE sales
SET pwd_discount_rate = 20.00
WHERE pwd_discount_amount > 0;

UPDATE expenses
SET rate_percent = 20.00
WHERE category = 'PWD Discount' AND rate_percent IS NULL;

INSERT INTO expenses (created_by, description, category, amount, rate_percent, created_at)
SELECT s.cashier_id,
       CONCAT('Driver commission - ', COALESCE(s.driver_name, 'Driver'), ' on sale #', s.id),
       'Driver Commission',
       s.driver_commission,
       s.driver_commission_rate,
       s.created_at
FROM sales s
WHERE s.driver_commission > 0
  AND NOT EXISTS (
      SELECT 1
      FROM expenses e
      WHERE e.category = 'Driver Commission'
        AND e.description = CONCAT('Driver commission - ', COALESCE(s.driver_name, 'Driver'), ' on sale #', s.id)
  );
