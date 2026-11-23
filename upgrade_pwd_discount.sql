USE laguerta_pos;

ALTER TABLE sales
    ADD COLUMN pwd_discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total;
