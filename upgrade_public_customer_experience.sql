USE laguerta_pos;

ALTER TABLE users
    ADD COLUMN phone_number VARCHAR(30) NULL AFTER full_name;

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
