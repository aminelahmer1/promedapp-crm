-- ============================================================
-- PROMED APP – Migration CRM complète
-- Exécuter une seule fois. Toutes les opérations sont idempotentes.
-- ============================================================

-- ── 1. TABLE clients (enrichissement) ──────────────────────
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS first_name    VARCHAR(100)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS last_name     VARCHAR(100)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS address       TEXT          DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS nationality   VARCHAR(100)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS source        VARCHAR(100)  DEFAULT NULL   COMMENT 'Référence / origine du contact',
  ADD COLUMN IF NOT EXISTS assigned_to   INT           DEFAULT NULL   COMMENT 'user_id du commercial responsable',
  ADD COLUMN IF NOT EXISTS notes         TEXT          DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS updated_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ── 2. TABLE promises ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS promises (
  id               INT            AUTO_INCREMENT PRIMARY KEY,
  promise_number   VARCHAR(50)    NOT NULL UNIQUE,
  client_id        INT            NOT NULL,
  project_id       INT            DEFAULT NULL,
  unit_code        VARCHAR(100)   NOT NULL,
  parking_codes    VARCHAR(255)   DEFAULT NULL,
  cellier_codes    VARCHAR(255)   DEFAULT NULL,
  sale_price       DECIMAL(15,3)  NOT NULL DEFAULT 0,
  advance_amount   DECIMAL(15,3)  NOT NULL DEFAULT 0,
  extra_works      DECIMAL(15,3)  NOT NULL DEFAULT 0,
  vat_rate         DECIMAL(5,2)   NOT NULL DEFAULT 19,
  payment_plan_type VARCHAR(100)  DEFAULT NULL,
  promise_date     DATE           DEFAULT NULL,
  signed_date      DATE           DEFAULT NULL,
  status           ENUM('draft','signed','cancelled','converted_to_sale') NOT NULL DEFAULT 'draft',
  is_locked        TINYINT(1)     NOT NULL DEFAULT 0,
  details_json     LONGTEXT       DEFAULT NULL  COMMENT 'Snapshot complet de la fiche',
  vente_id         INT            DEFAULT NULL  COMMENT 'Lien vers la table ventes existante',
  created_by       INT            DEFAULT NULL,
  created_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_p_client  (client_id),
  INDEX idx_p_unit    (unit_code),
  INDEX idx_p_status  (status),
  INDEX idx_p_project (project_id)
);

-- ── 3. TABLE payment_schedules ─────────────────────────────
CREATE TABLE IF NOT EXISTS payment_schedules (
  id               INT            AUTO_INCREMENT PRIMARY KEY,
  promise_id       INT            NOT NULL,
  client_id        INT            NOT NULL,
  unit_code        VARCHAR(100)   NOT NULL,
  total_amount     DECIMAL(15,3)  NOT NULL DEFAULT 0,
  paid_amount      DECIMAL(15,3)  NOT NULL DEFAULT 0,
  remaining_amount DECIMAL(15,3)  NOT NULL DEFAULT 0,
  progress_percent DECIMAL(5,2)   NOT NULL DEFAULT 0,
  status           ENUM('pending','in_progress','paid','overdue') NOT NULL DEFAULT 'pending',
  created_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_schedule_promise (promise_id),
  INDEX idx_s_client (client_id)
);

-- ── 4. TABLE payment_installments ──────────────────────────
CREATE TABLE IF NOT EXISTS payment_installments (
  id               INT            AUTO_INCREMENT PRIMARY KEY,
  schedule_id      INT            NOT NULL,
  label            VARCHAR(200)   DEFAULT NULL,
  due_date         DATE           DEFAULT NULL,
  expected_amount  DECIMAL(15,3)  NOT NULL DEFAULT 0,
  paid_amount      DECIMAL(15,3)  NOT NULL DEFAULT 0,
  remaining_amount DECIMAL(15,3)  NOT NULL DEFAULT 0,
  status           ENUM('pending','partially_paid','paid','overdue') NOT NULL DEFAULT 'pending',
  notes            TEXT           DEFAULT NULL,
  sort_order       INT            NOT NULL DEFAULT 0,
  created_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_i_schedule (schedule_id)
);

-- ── 5. TABLE payments ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS payments (
  id               INT            AUTO_INCREMENT PRIMARY KEY,
  promise_id       INT            NOT NULL,
  installment_id   INT            DEFAULT NULL,
  client_id        INT            NOT NULL,
  unit_code        VARCHAR(100)   NOT NULL,
  payment_date     DATE           NOT NULL,
  amount           DECIMAL(15,3)  NOT NULL,
  payment_method   ENUM('cash','cheque','bank_transfer','traite') NOT NULL DEFAULT 'bank_transfer',
  bank_name        VARCHAR(200)   DEFAULT NULL,
  reference_number VARCHAR(200)   DEFAULT NULL,
  receipt_number   VARCHAR(100)   DEFAULT NULL,
  notes            TEXT           DEFAULT NULL,
  created_by       INT            DEFAULT NULL,
  created_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pay_promise     (promise_id),
  INDEX idx_pay_installment (installment_id),
  INDEX idx_pay_client      (client_id)
);

-- ── 6. TABLE audit_logs ────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_logs (
  id           INT         AUTO_INCREMENT PRIMARY KEY,
  user_id      INT         DEFAULT NULL,
  entity_type  VARCHAR(50) NOT NULL  COMMENT 'promise | payment | client | installment',
  entity_id    INT         NOT NULL,
  action       VARCHAR(50) NOT NULL  COMMENT 'create | update | lock | delete | payment_added',
  old_value    TEXT        DEFAULT NULL,
  new_value    TEXT        DEFAULT NULL,
  created_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_al_entity (entity_type, entity_id),
  INDEX idx_al_user   (user_id)
);

-- ── 7. Vue utilitaire : promesses avec infos client ────────
CREATE OR REPLACE VIEW v_promises AS
SELECT
  p.id,
  p.promise_number,
  p.status,
  p.is_locked,
  p.unit_code,
  p.sale_price,
  p.advance_amount,
  p.promise_date,
  p.signed_date,
  p.created_at,
  c.id         AS client_id,
  c.nom        AS client_nom,
  c.tel        AS client_tel,
  c.email      AS client_email,
  pr.code      AS project_code,
  ps.paid_amount,
  ps.remaining_amount,
  ps.progress_percent,
  ps.status    AS schedule_status
FROM promises p
LEFT JOIN clients c  ON c.id  = p.client_id
LEFT JOIN projects pr ON pr.id = p.project_id
LEFT JOIN payment_schedules ps ON ps.promise_id = p.id;
