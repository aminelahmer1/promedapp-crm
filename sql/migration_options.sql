-- ============================================================
-- PROMED APP – Migration Option de Vente
-- Exécuter via run_options_migration.php
-- Idempotent : CREATE TABLE IF NOT EXISTS
-- ============================================================

CREATE TABLE IF NOT EXISTS option_ventes (
    id                INT           AUTO_INCREMENT PRIMARY KEY,
    option_number     VARCHAR(30)   NOT NULL,
    promise_id        INT           DEFAULT NULL,
    client_id         INT           DEFAULT NULL,
    project_id        INT           DEFAULT NULL,
    unit_code         VARCHAR(100)  NOT NULL,
    reservation_amount DECIMAL(15,3) NOT NULL DEFAULT 0,
    currency          VARCHAR(10)   NOT NULL DEFAULT 'TND',
    option_date       DATE          NOT NULL,
    valid_until       DATE          NOT NULL,
    payment_status    ENUM('paid','deferred','none') NOT NULL DEFAULT 'none',
    payment_method    VARCHAR(50)   DEFAULT NULL,
    payment_reference VARCHAR(200)  DEFAULT NULL,
    status            ENUM('active','expired','converted','cancelled') NOT NULL DEFAULT 'active',
    details_json      LONGTEXT      DEFAULT NULL,
    created_by        INT           DEFAULT NULL,
    created_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_option_number (option_number),
    INDEX idx_ov_promise  (promise_id),
    INDEX idx_ov_client   (client_id),
    INDEX idx_ov_unit     (unit_code),
    INDEX idx_ov_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
