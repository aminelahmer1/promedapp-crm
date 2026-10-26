-- ============================================================
-- CRM CLEANUP MIGRATION — PromedApp
-- Idempotent: safe to run multiple times (IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)
-- Does NOT drop or rename existing tables.
-- Does NOT delete existing data.
-- ============================================================

-- ── A. clients — colonnes CRM additionnelles ──────────────────────────
ALTER TABLE clients ADD COLUMN IF NOT EXISTS whatsapp           VARCHAR(30)   DEFAULT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS profession         VARCHAR(100)  DEFAULT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS campaign           VARCHAR(100)  DEFAULT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS budget_min         DECIMAL(15,3) DEFAULT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS budget_max         DECIMAL(15,3) DEFAULT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS buying_delay       VARCHAR(50)   DEFAULT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS financing_mode     VARCHAR(50)   DEFAULT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS motivation         TEXT          DEFAULT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS private_notes      TEXT          DEFAULT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS last_contact_at    DATETIME      DEFAULT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS next_followup_at   DATETIME      DEFAULT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS crm_status         VARCHAR(30)   NOT NULL DEFAULT 'new';
ALTER TABLE clients ADD COLUMN IF NOT EXISTS interest_level     VARCHAR(20)   NOT NULL DEFAULT 'medium';
ALTER TABLE clients ADD COLUMN IF NOT EXISTS source             VARCHAR(100)  DEFAULT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS assigned_to        INT           DEFAULT NULL;

-- ── B. crm_activities — colonnes additionnelles ───────────────────────
ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS activity_date DATETIME     DEFAULT NULL;
ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS client_id     INT          DEFAULT NULL;
ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS subject       VARCHAR(255) DEFAULT NULL;
ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS result        TEXT         DEFAULT NULL;
ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS next_action   TEXT         DEFAULT NULL;
ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS due_date      DATETIME     DEFAULT NULL;
ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS completed_at  DATETIME     DEFAULT NULL;

-- Backfill activity_date depuis created_at si vide
UPDATE crm_activities SET activity_date = created_at WHERE activity_date IS NULL AND created_at IS NOT NULL;

-- ── C. crm_client_needs — besoins immobiliers du client ──────────────
CREATE TABLE IF NOT EXISTS crm_client_needs (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    client_id               INT NOT NULL,
    project_id              INT DEFAULT NULL,
    property_type           VARCHAR(50) DEFAULT NULL,
    bedrooms                INT DEFAULT NULL,
    surface_min             DECIMAL(8,2) DEFAULT NULL,
    surface_max             DECIMAL(8,2) DEFAULT NULL,
    budget_min              DECIMAL(15,3) DEFAULT NULL,
    budget_max              DECIMAL(15,3) DEFAULT NULL,
    wants_parking           TINYINT(1) NOT NULL DEFAULT 0,
    wants_cellier           TINYINT(1) NOT NULL DEFAULT 0,
    wants_pool              TINYINT(1) NOT NULL DEFAULT 0,
    wants_independent_kitchen TINYINT(1) NOT NULL DEFAULT 0,
    wants_terrace           TINYINT(1) NOT NULL DEFAULT 0,
    wants_garden            TINYINT(1) NOT NULL DEFAULT 0,
    wants_open_view         TINYINT(1) NOT NULL DEFAULT 0,
    preferred_floor         VARCHAR(50) DEFAULT NULL,
    financing_mode          VARCHAR(50) DEFAULT NULL,
    buying_delay            VARCHAR(50) DEFAULT NULL,
    notes                   TEXT DEFAULT NULL,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cn_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── D. crm_client_units — unités intéressées (avant promesse) ────────
CREATE TABLE IF NOT EXISTS crm_client_units (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    client_id       INT NOT NULL,
    project_id      INT DEFAULT NULL,
    unit_code       VARCHAR(100) NOT NULL,
    interest_level  VARCHAR(20) NOT NULL DEFAULT 'medium',
    status          VARCHAR(30) NOT NULL DEFAULT 'interested',
    is_favorite     TINYINT(1) NOT NULL DEFAULT 0,
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cu_client (client_id),
    INDEX idx_cu_unit (unit_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── E. crm_visits — visites planifiées ───────────────────────────────
CREATE TABLE IF NOT EXISTS crm_visits (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    client_id       INT NOT NULL,
    unit_code       VARCHAR(100) DEFAULT NULL,
    project_id      INT DEFAULT NULL,
    visit_date      DATE NOT NULL,
    visit_time      TIME DEFAULT NULL,
    location        VARCHAR(200) DEFAULT NULL,
    status          ENUM('planned','done','cancelled','no_show') NOT NULL DEFAULT 'planned',
    commercial_id   INT DEFAULT NULL,
    notes           TEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_v_client (client_id),
    INDEX idx_v_date (visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── F. promises — colonnes additionnelles si table existe ─────────────
-- (La table promises existe déjà — on ajoute seulement ce qui manque)
ALTER TABLE promises ADD COLUMN IF NOT EXISTS promise_number     VARCHAR(50)   DEFAULT NULL;
ALTER TABLE promises ADD COLUMN IF NOT EXISTS client_id          INT           DEFAULT NULL;
ALTER TABLE promises ADD COLUMN IF NOT EXISTS project_id         INT           DEFAULT NULL;
ALTER TABLE promises ADD COLUMN IF NOT EXISTS is_locked          TINYINT(1)    NOT NULL DEFAULT 0;
ALTER TABLE promises ADD COLUMN IF NOT EXISTS locked_at          DATETIME      DEFAULT NULL;
ALTER TABLE promises ADD COLUMN IF NOT EXISTS signed_date        DATE          DEFAULT NULL;
ALTER TABLE promises ADD COLUMN IF NOT EXISTS total_ttc          DECIMAL(15,3) DEFAULT NULL;
ALTER TABLE promises ADD COLUMN IF NOT EXISTS paid_amount        DECIMAL(15,3) DEFAULT NULL;
ALTER TABLE promises ADD COLUMN IF NOT EXISTS remaining_amount   DECIMAL(15,3) DEFAULT NULL;

-- ── G. audit_logs ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT DEFAULT NULL,
    entity_type VARCHAR(50) DEFAULT NULL,
    entity_id   INT DEFAULT NULL,
    action      VARCHAR(50) DEFAULT NULL,
    old_value   TEXT DEFAULT NULL,
    new_value   TEXT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_al_entity (entity_type, entity_id),
    INDEX idx_al_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- FIN DE MIGRATION
-- ============================================================
