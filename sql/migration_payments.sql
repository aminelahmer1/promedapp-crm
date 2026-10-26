-- ============================================================
-- PROMED APP – Migration paiements & alertes
-- Exécuter via run_payments_migration.php
-- NE PAS utiliser IF NOT EXISTS ici : le runner PHP vérifie
-- l'existence de chaque colonne avant d'exécuter.
-- ============================================================

-- ── 1. Colonnes de rappel sur payment_installments ──────────
ALTER TABLE payment_installments ADD COLUMN reminder_sent    TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE payment_installments ADD COLUMN reminder_sent_at DATETIME   DEFAULT NULL;

-- ── 2. TABLE payment_documents (justificatifs de paiement) ──
CREATE TABLE IF NOT EXISTS payment_documents (
  id            INT          AUTO_INCREMENT PRIMARY KEY,
  payment_id    INT          NOT NULL,
  promise_id    INT          NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(255) NOT NULL,
  file_path     VARCHAR(500) NOT NULL,
  file_size     INT          DEFAULT NULL,
  mime_type     VARCHAR(100) DEFAULT NULL,
  uploaded_by   INT          DEFAULT NULL,
  created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pd_payment (payment_id),
  INDEX idx_pd_promise (promise_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. TABLE notifications (alertes internes) ───────────────
CREATE TABLE IF NOT EXISTS notifications (
  id             INT           AUTO_INCREMENT PRIMARY KEY,
  type           VARCHAR(50)   NOT NULL DEFAULT 'payment_reminder',
  installment_id INT           DEFAULT NULL,
  promise_id     INT           DEFAULT NULL,
  client_id      INT           DEFAULT NULL,
  unit_code      VARCHAR(100)  DEFAULT NULL,
  client_nom     VARCHAR(200)  DEFAULT NULL,
  client_email   VARCHAR(200)  DEFAULT NULL,
  amount         DECIMAL(15,3) DEFAULT NULL,
  due_date       DATE          DEFAULT NULL,
  message        TEXT          DEFAULT NULL,
  is_read        TINYINT(1)    NOT NULL DEFAULT 0,
  email_sent     TINYINT(1)    NOT NULL DEFAULT 0,
  email_sent_at  DATETIME      DEFAULT NULL,
  created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_read (is_read),
  INDEX idx_notif_date (due_date),
  INDEX idx_notif_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
