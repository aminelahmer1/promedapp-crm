-- ============================================================
-- PROMED APP – Migration CRM v2
-- Exécuter via run_crm_migration2.php
-- NE PAS utiliser IF NOT EXISTS ici : le runner PHP vérifie
-- l'existence de chaque colonne/index avant d'exécuter.
-- ============================================================

-- ── 1. Extension table clients (une colonne par instruction) ──
ALTER TABLE clients ADD COLUMN whatsapp          VARCHAR(30)    DEFAULT NULL;
ALTER TABLE clients ADD COLUMN profession        VARCHAR(100)   DEFAULT NULL;
ALTER TABLE clients ADD COLUMN campaign          VARCHAR(100)   DEFAULT NULL;
ALTER TABLE clients ADD COLUMN budget_min        DECIMAL(15,3)  DEFAULT NULL;
ALTER TABLE clients ADD COLUMN budget_max        DECIMAL(15,3)  DEFAULT NULL;
ALTER TABLE clients ADD COLUMN buying_delay      VARCHAR(50)    DEFAULT NULL;
ALTER TABLE clients ADD COLUMN financing_mode    VARCHAR(50)    DEFAULT NULL;
ALTER TABLE clients ADD COLUMN motivation        TEXT           DEFAULT NULL;
ALTER TABLE clients ADD COLUMN private_notes     TEXT           DEFAULT NULL;
ALTER TABLE clients ADD COLUMN last_contact_at   DATETIME       DEFAULT NULL;
ALTER TABLE clients ADD COLUMN next_followup_at  DATETIME       DEFAULT NULL;
ALTER TABLE clients ADD COLUMN crm_status           VARCHAR(30)    NOT NULL DEFAULT 'new';
ALTER TABLE clients ADD COLUMN interest_level       VARCHAR(20)    NOT NULL DEFAULT 'medium';
ALTER TABLE clients ADD COLUMN preferred_project    VARCHAR(100)   DEFAULT NULL;
ALTER TABLE clients ADD COLUMN preferred_unit_type  VARCHAR(100)   DEFAULT NULL;
ALTER TABLE clients ADD COLUMN last_contact_date    DATETIME       DEFAULT NULL;
ALTER TABLE clients ADD COLUMN next_follow_up_date  DATETIME       DEFAULT NULL;

-- ── 2. Extension crm_activities (une colonne par instruction) ─
ALTER TABLE crm_activities ADD COLUMN client_id    INT           DEFAULT NULL;
ALTER TABLE crm_activities ADD COLUMN subject      VARCHAR(255)  DEFAULT NULL;
ALTER TABLE crm_activities ADD COLUMN result       TEXT          DEFAULT NULL;
ALTER TABLE crm_activities ADD COLUMN next_action  TEXT          DEFAULT NULL;
ALTER TABLE crm_activities ADD COLUMN due_date     DATETIME      DEFAULT NULL;
ALTER TABLE crm_activities ADD COLUMN completed_at DATETIME      DEFAULT NULL;

-- Index (ignoré si déjà présent via runner PHP)
ALTER TABLE crm_activities ADD INDEX idx_act_client (client_id);

-- ── 3. TABLE crm_client_needs ───────────────────────────────
CREATE TABLE IF NOT EXISTS crm_client_needs (
  id                        INT           AUTO_INCREMENT PRIMARY KEY,
  client_id                 INT           NOT NULL,
  project_id                INT           DEFAULT NULL,
  property_type             VARCHAR(50)   DEFAULT NULL,
  bedrooms                  INT           DEFAULT NULL,
  surface_min               DECIMAL(8,2)  DEFAULT NULL,
  surface_max               DECIMAL(8,2)  DEFAULT NULL,
  budget_min                DECIMAL(15,3) DEFAULT NULL,
  budget_max                DECIMAL(15,3) DEFAULT NULL,
  wants_parking             TINYINT(1)    NOT NULL DEFAULT 0,
  wants_cellier             TINYINT(1)    NOT NULL DEFAULT 0,
  wants_pool                TINYINT(1)    NOT NULL DEFAULT 0,
  wants_independent_kitchen TINYINT(1)    NOT NULL DEFAULT 0,
  wants_terrace             TINYINT(1)    NOT NULL DEFAULT 0,
  wants_garden              TINYINT(1)    NOT NULL DEFAULT 0,
  wants_open_view           TINYINT(1)    NOT NULL DEFAULT 0,
  preferred_floor           VARCHAR(50)   DEFAULT NULL,
  notes                     TEXT          DEFAULT NULL,
  created_at                TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at                TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cn_client  (client_id),
  INDEX idx_cn_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. TABLE crm_client_units ───────────────────────────────
CREATE TABLE IF NOT EXISTS crm_client_units (
  id             INT           AUTO_INCREMENT PRIMARY KEY,
  client_id      INT           NOT NULL,
  project_id     INT           DEFAULT NULL,
  unit_code      VARCHAR(100)  NOT NULL,
  interest_level VARCHAR(20)   NOT NULL DEFAULT 'medium',
  status         VARCHAR(30)   NOT NULL DEFAULT 'interested',
  is_favorite    TINYINT(1)    NOT NULL DEFAULT 0,
  notes          TEXT          DEFAULT NULL,
  created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cu_client  (client_id),
  INDEX idx_cu_project (project_id),
  INDEX idx_cu_unit    (unit_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. TABLE crm_visits ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS crm_visits (
  id            INT           AUTO_INCREMENT PRIMARY KEY,
  client_id     INT           NOT NULL,
  unit_code     VARCHAR(100)  DEFAULT NULL,
  project_id    INT           DEFAULT NULL,
  visit_date    DATE          NOT NULL,
  visit_time    TIME          DEFAULT NULL,
  location      VARCHAR(200)  DEFAULT NULL,
  status        ENUM('planned','done','cancelled','no_show') NOT NULL DEFAULT 'planned',
  commercial_id INT           DEFAULT NULL,
  notes         TEXT          DEFAULT NULL,
  created_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_v_client     (client_id),
  INDEX idx_v_date       (visit_date),
  INDEX idx_v_commercial (commercial_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
