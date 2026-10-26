-- ============================================================
-- PROMED APP – Garantir les colonnes critiques de promises
-- Exécuter via run_promises_migration.php
-- NE PAS utiliser IF NOT EXISTS ici : le runner PHP vérifie
-- chaque colonne via INFORMATION_SCHEMA avant d'exécuter.
-- ============================================================

-- Colonnes financières
ALTER TABLE promises ADD COLUMN sale_price        DECIMAL(15,3) NOT NULL DEFAULT 0;
ALTER TABLE promises ADD COLUMN advance_amount    DECIMAL(15,3) NOT NULL DEFAULT 0;
ALTER TABLE promises ADD COLUMN extra_works       DECIMAL(15,3) NOT NULL DEFAULT 0;
ALTER TABLE promises ADD COLUMN vat_rate          DECIMAL(5,2)  NOT NULL DEFAULT 19;

-- Infos contrat
ALTER TABLE promises ADD COLUMN payment_plan_type VARCHAR(100) DEFAULT NULL;
ALTER TABLE promises ADD COLUMN details_json      LONGTEXT     DEFAULT NULL;

-- Verrouillage
ALTER TABLE promises ADD COLUMN is_locked         TINYINT(1)   NOT NULL DEFAULT 0;
ALTER TABLE promises ADD COLUMN locked_at         DATETIME     DEFAULT NULL;

-- Lien vente
ALTER TABLE promises ADD COLUMN vente_id          INT          DEFAULT NULL;

-- Colonnes utilitaires pour payment_installments
ALTER TABLE payment_installments ADD COLUMN promise_id      INT          DEFAULT NULL;
ALTER TABLE payment_installments ADD COLUMN reminder_sent   TINYINT(1)   NOT NULL DEFAULT 0;
ALTER TABLE payment_installments ADD COLUMN reminder_sent_at DATETIME    DEFAULT NULL;

-- Colonnes utilitaires pour payments
ALTER TABLE payments ADD COLUMN promise_id        INT          DEFAULT NULL;
ALTER TABLE payments ADD COLUMN installment_id    INT          DEFAULT NULL;
ALTER TABLE payments ADD COLUMN client_id         INT          DEFAULT NULL;
ALTER TABLE payments ADD COLUMN unit_code         VARCHAR(64)  DEFAULT NULL;
ALTER TABLE payments ADD COLUMN payment_date      DATE         DEFAULT NULL;
ALTER TABLE payments ADD COLUMN amount            DECIMAL(14,3) DEFAULT NULL;
ALTER TABLE payments ADD COLUMN payment_method    VARCHAR(50)  DEFAULT NULL;
ALTER TABLE payments ADD COLUMN bank_name         VARCHAR(100) DEFAULT NULL;
ALTER TABLE payments ADD COLUMN reference_number  VARCHAR(100) DEFAULT NULL;
ALTER TABLE payments ADD COLUMN receipt_number    VARCHAR(100) DEFAULT NULL;
ALTER TABLE payments ADD COLUMN notes             TEXT         DEFAULT NULL;
ALTER TABLE payments ADD COLUMN created_by        INT          DEFAULT NULL;
ALTER TABLE payments ADD COLUMN status            ENUM('valid','cancelled','corrected') NOT NULL DEFAULT 'valid';
ALTER TABLE payments ADD COLUMN updated_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
