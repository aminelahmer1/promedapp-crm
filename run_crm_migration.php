<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

function tblExists(PDO $pdo, string $t): bool {
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $s->execute([$t]); return (bool)$s->fetchColumn();
}
function colExists(PDO $pdo, string $t, string $c): bool {
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $s->execute([$t,$c]); return (bool)$s->fetchColumn();
}
function addCol(PDO $pdo, string $t, string $c, string $def, array &$log): void {
    if(colExists($pdo,$t,$c)){ $log[]=['skip',"$t.$c",'Déjà présente']; return; }
    $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
    $log[]=['ok',"$t.$c",'Ajoutée'];
}

$log=[]; $ok=true;

/* ══ 1. clients — colonnes CRM ══ */
if(!tblExists($pdo,'clients')){ $log[]=['err','clients','Table clients introuvable !']; $ok=false; }
else {
    $log[]=['ok','clients','Table trouvée'];
    $cc=['first_name'=>"VARCHAR(100) DEFAULT NULL",'last_name'=>"VARCHAR(100) DEFAULT NULL",
         'address'=>"TEXT DEFAULT NULL",'nationality'=>"VARCHAR(100) DEFAULT NULL",
         'source'=>"VARCHAR(100) DEFAULT NULL",'assigned_to'=>"INT DEFAULT NULL",
         'notes'=>"TEXT DEFAULT NULL",'updated_at'=>"TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"];
    foreach($cc as $c=>$d){ try{ addCol($pdo,'clients',$c,$d,$log); }catch(Throwable $e){ $log[]=['err',"clients.$c",$e->getMessage()]; $ok=false; } }
}

/* ══ 2. crm_dossiers ══ */
try{
    if(tblExists($pdo,'crm_dossiers')){ $log[]=['skip','crm_dossiers','Déjà existante']; }
    else {
        $pdo->exec("CREATE TABLE crm_dossiers (
          id                    INT AUTO_INCREMENT PRIMARY KEY,
          dossier_number        VARCHAR(50) UNIQUE NOT NULL,
          client_id             INT NOT NULL,
          project_id            INT DEFAULT NULL,
          unit_id               INT DEFAULT NULL,
          unit_code             VARCHAR(100) DEFAULT NULL,
          status                ENUM('interested','reserved','promise_draft','promise_signed','sold','payment_in_progress','paid','closed','cancelled') NOT NULL DEFAULT 'interested',
          source                VARCHAR(100) DEFAULT NULL,
          assigned_to           INT DEFAULT NULL,
          interest_level        ENUM('low','medium','high','hot') DEFAULT 'medium',
          budget                DECIMAL(15,3) DEFAULT NULL,
          reservation_date      DATE DEFAULT NULL,
          reservation_expires_at DATE DEFAULT NULL,
          sold_date             DATE DEFAULT NULL,
          closed_date           DATE DEFAULT NULL,
          notes                 TEXT DEFAULT NULL,
          created_by            INT DEFAULT NULL,
          created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at            DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_dos_client (client_id),
          INDEX idx_dos_unit   (unit_code),
          INDEX idx_dos_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $log[]=['ok','crm_dossiers','Table créée'];
    }
}catch(Throwable $e){ $log[]=['err','crm_dossiers',$e->getMessage()]; $ok=false; }

/* ══ 3. promises — créer ou enrichir ══ */
try{
    if(!tblExists($pdo,'promises')){
        $pdo->exec("CREATE TABLE promises (
          id                  INT AUTO_INCREMENT PRIMARY KEY,
          promise_number      VARCHAR(50) UNIQUE NOT NULL,
          dossier_id          INT DEFAULT NULL,
          client_id           INT NOT NULL,
          project_id          INT DEFAULT NULL,
          unit_id             INT DEFAULT NULL,
          unit_code           VARCHAR(100) NOT NULL,
          parking_codes       VARCHAR(255) DEFAULT NULL,
          cellier_codes       VARCHAR(255) DEFAULT NULL,
          asset_type          VARCHAR(100) DEFAULT NULL,
          apartment_price_ht  DECIMAL(15,3) NOT NULL DEFAULT 0,
          parking_price_ht    DECIMAL(15,3) NOT NULL DEFAULT 0,
          cellier_price_ht    DECIMAL(15,3) NOT NULL DEFAULT 0,
          extra_works_ht      DECIMAL(15,3) NOT NULL DEFAULT 0,
          discount_apartment  DECIMAL(15,3) NOT NULL DEFAULT 0,
          discount_parking    DECIMAL(15,3) NOT NULL DEFAULT 0,
          discount_cellier    DECIMAL(15,3) NOT NULL DEFAULT 0,
          vat_rate            DECIMAL(5,2) NOT NULL DEFAULT 19,
          total_ht            DECIMAL(15,3) NOT NULL DEFAULT 0,
          total_vat           DECIMAL(15,3) NOT NULL DEFAULT 0,
          total_ttc           DECIMAL(15,3) NOT NULL DEFAULT 0,
          advance_amount      DECIMAL(15,3) NOT NULL DEFAULT 0,
          finance_mode        VARCHAR(100) DEFAULT NULL,
          payment_plan_type   VARCHAR(100) DEFAULT NULL,
          promise_date        DATE DEFAULT NULL,
          signed_date         DATE DEFAULT NULL,
          status              ENUM('draft','signed','cancelled','converted_to_sale') NOT NULL DEFAULT 'draft',
          is_locked           TINYINT(1) NOT NULL DEFAULT 0,
          locked_at           DATETIME DEFAULT NULL,
          details_json        LONGTEXT DEFAULT NULL,
          vente_id            INT DEFAULT NULL,
          created_by          INT DEFAULT NULL,
          created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_p_dossier (dossier_id),
          INDEX idx_p_client  (client_id),
          INDEX idx_p_unit    (unit_code),
          INDEX idx_p_status  (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $log[]=['ok','promises','Table créée'];
    } else {
        $log[]=['skip','promises','Déjà existante — enrichissement des colonnes'];
        $newCols=[
            'dossier_id'         =>"INT DEFAULT NULL AFTER promise_number",
            'unit_id'            =>"INT DEFAULT NULL AFTER project_id",
            'asset_type'         =>"VARCHAR(100) DEFAULT NULL",
            'apartment_price_ht' =>"DECIMAL(15,3) NOT NULL DEFAULT 0",
            'parking_price_ht'   =>"DECIMAL(15,3) NOT NULL DEFAULT 0",
            'cellier_price_ht'   =>"DECIMAL(15,3) NOT NULL DEFAULT 0",
            'extra_works_ht'     =>"DECIMAL(15,3) NOT NULL DEFAULT 0",
            'discount_apartment' =>"DECIMAL(15,3) NOT NULL DEFAULT 0",
            'discount_parking'   =>"DECIMAL(15,3) NOT NULL DEFAULT 0",
            'discount_cellier'   =>"DECIMAL(15,3) NOT NULL DEFAULT 0",
            'total_ht'           =>"DECIMAL(15,3) NOT NULL DEFAULT 0",
            'total_vat'          =>"DECIMAL(15,3) NOT NULL DEFAULT 0",
            'total_ttc'          =>"DECIMAL(15,3) NOT NULL DEFAULT 0",
            'finance_mode'       =>"VARCHAR(100) DEFAULT NULL",
            'locked_at'          =>"DATETIME DEFAULT NULL",
        ];
        foreach($newCols as $c=>$d){ try{ addCol($pdo,'promises',$c,$d,$log); }catch(Throwable $e){ $log[]=['err',"promises.$c",$e->getMessage()]; } }
        // Renommer sale_price -> apartment_price_ht si nécessaire (keep sale_price for compat)
    }
}catch(Throwable $e){ $log[]=['err','promises',$e->getMessage()]; $ok=false; }

/* ══ 4. payment_schedules ══ */
try{
    if(!tblExists($pdo,'payment_schedules')){
        $pdo->exec("CREATE TABLE payment_schedules (
          id               INT AUTO_INCREMENT PRIMARY KEY,
          dossier_id       INT DEFAULT NULL,
          promise_id       INT NOT NULL,
          client_id        INT NOT NULL,
          unit_code        VARCHAR(100) NOT NULL,
          total_amount     DECIMAL(15,3) NOT NULL DEFAULT 0,
          paid_amount      DECIMAL(15,3) NOT NULL DEFAULT 0,
          remaining_amount DECIMAL(15,3) NOT NULL DEFAULT 0,
          progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
          status           ENUM('pending','in_progress','paid','overdue','cancelled') NOT NULL DEFAULT 'pending',
          created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uk_schedule_promise (promise_id),
          INDEX idx_s_dossier (dossier_id),
          INDEX idx_s_client  (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $log[]=['ok','payment_schedules','Table créée'];
    } else {
        $log[]=['skip','payment_schedules','Déjà existante — enrichissement'];
        try{ addCol($pdo,'payment_schedules','dossier_id',"INT DEFAULT NULL AFTER id",$log); }catch(Throwable $e){}
    }
}catch(Throwable $e){ $log[]=['err','payment_schedules',$e->getMessage()]; $ok=false; }

/* ══ 5. payment_installments ══ */
try{
    if(!tblExists($pdo,'payment_installments')){
        $pdo->exec("CREATE TABLE payment_installments (
          id               INT AUTO_INCREMENT PRIMARY KEY,
          schedule_id      INT NOT NULL,
          dossier_id       INT DEFAULT NULL,
          promise_id       INT DEFAULT NULL,
          label            VARCHAR(200) DEFAULT NULL,
          due_date         DATE DEFAULT NULL,
          expected_amount  DECIMAL(15,3) NOT NULL DEFAULT 0,
          paid_amount      DECIMAL(15,3) NOT NULL DEFAULT 0,
          remaining_amount DECIMAL(15,3) NOT NULL DEFAULT 0,
          status           ENUM('pending','partially_paid','paid','overdue') NOT NULL DEFAULT 'pending',
          notes            TEXT DEFAULT NULL,
          sort_order       INT NOT NULL DEFAULT 0,
          created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_i_schedule (schedule_id),
          INDEX idx_i_dossier  (dossier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $log[]=['ok','payment_installments','Table créée'];
    } else {
        $log[]=['skip','payment_installments','Déjà existante — enrichissement'];
        foreach(['dossier_id'=>"INT DEFAULT NULL",'promise_id'=>"INT DEFAULT NULL"] as $c=>$d){
            try{ addCol($pdo,'payment_installments',$c,$d,$log); }catch(Throwable $e){}
        }
    }
}catch(Throwable $e){ $log[]=['err','payment_installments',$e->getMessage()]; $ok=false; }

/* ══ 6. payments ══ */
try{
    if(!tblExists($pdo,'payments')){
        $pdo->exec("CREATE TABLE payments (
          id               INT AUTO_INCREMENT PRIMARY KEY,
          dossier_id       INT DEFAULT NULL,
          promise_id       INT NOT NULL,
          schedule_id      INT DEFAULT NULL,
          installment_id   INT DEFAULT NULL,
          client_id        INT NOT NULL,
          unit_code        VARCHAR(100) NOT NULL,
          payment_date     DATE NOT NULL,
          amount           DECIMAL(15,3) NOT NULL,
          payment_method   ENUM('cash','cheque','bank_transfer','traite') NOT NULL DEFAULT 'bank_transfer',
          bank_name        VARCHAR(200) DEFAULT NULL,
          reference_number VARCHAR(200) DEFAULT NULL,
          receipt_number   VARCHAR(100) DEFAULT NULL,
          status           ENUM('valid','cancelled','corrected') NOT NULL DEFAULT 'valid',
          notes            TEXT DEFAULT NULL,
          created_by       INT DEFAULT NULL,
          created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_pay_dossier     (dossier_id),
          INDEX idx_pay_promise     (promise_id),
          INDEX idx_pay_installment (installment_id),
          INDEX idx_pay_client      (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $log[]=['ok','payments','Table créée'];
    } else {
        $log[]=['skip','payments','Déjà existante — enrichissement'];
        foreach(['dossier_id'=>"INT DEFAULT NULL",'schedule_id'=>"INT DEFAULT NULL",
                 'status'=>"ENUM('valid','cancelled','corrected') NOT NULL DEFAULT 'valid'",
                 'receipt_number'=>"VARCHAR(100) DEFAULT NULL"] as $c=>$d){
            try{ addCol($pdo,'payments',$c,$d,$log); }catch(Throwable $e){}
        }
    }
}catch(Throwable $e){ $log[]=['err','payments',$e->getMessage()]; $ok=false; }

/* ══ 7. receipts ══ */
try{
    if(!tblExists($pdo,'receipts')){
        $pdo->exec("CREATE TABLE receipts (
          id             INT AUTO_INCREMENT PRIMARY KEY,
          payment_id     INT NOT NULL,
          dossier_id     INT DEFAULT NULL,
          receipt_number VARCHAR(100) UNIQUE NOT NULL,
          receipt_date   DATE NOT NULL,
          amount         DECIMAL(15,3) NOT NULL,
          file_path      VARCHAR(255) DEFAULT NULL,
          created_by     INT DEFAULT NULL,
          created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_rec_payment (payment_id),
          INDEX idx_rec_dossier (dossier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $log[]=['ok','receipts','Table créée'];
    } else { $log[]=['skip','receipts','Déjà existante']; }
}catch(Throwable $e){ $log[]=['err','receipts',$e->getMessage()]; $ok=false; }

/* ══ 8. crm_activities ══ */
try{
    if(!tblExists($pdo,'crm_activities')){
        $pdo->exec("CREATE TABLE crm_activities (
          id             INT AUTO_INCREMENT PRIMARY KEY,
          dossier_id     INT DEFAULT NULL,
          client_id      INT DEFAULT NULL,
          activity_type  ENUM('call','whatsapp','email','meeting','note','task') NOT NULL DEFAULT 'note',
          subject        VARCHAR(255) DEFAULT NULL,
          description    TEXT DEFAULT NULL,
          due_date       DATETIME DEFAULT NULL,
          completed_at   DATETIME DEFAULT NULL,
          created_by     INT DEFAULT NULL,
          created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_act_dossier (dossier_id),
          INDEX idx_act_client  (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $log[]=['ok','crm_activities','Table créée'];
    } else { $log[]=['skip','crm_activities','Déjà existante']; }
}catch(Throwable $e){ $log[]=['err','crm_activities',$e->getMessage()]; $ok=false; }

/* ══ 9. audit_logs ══ */
try{
    if(!tblExists($pdo,'audit_logs')){
        $pdo->exec("CREATE TABLE audit_logs (
          id          INT AUTO_INCREMENT PRIMARY KEY,
          user_id     INT DEFAULT NULL,
          entity_type VARCHAR(100) NOT NULL,
          entity_id   INT NOT NULL,
          action      VARCHAR(100) NOT NULL,
          old_value   TEXT DEFAULT NULL,
          new_value   TEXT DEFAULT NULL,
          created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_al_entity (entity_type, entity_id),
          INDEX idx_al_user   (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $log[]=['ok','audit_logs','Table créée'];
    } else { $log[]=['skip','audit_logs','Déjà existante']; }
}catch(Throwable $e){ $log[]=['err','audit_logs',$e->getMessage()]; $ok=false; }

/* ══ 10. Vue v_dossiers ══ */
try{
    $pdo->exec("CREATE OR REPLACE VIEW v_dossiers AS
        SELECT d.id, d.dossier_number, d.status AS dossier_status, d.unit_code, d.unit_id,
               d.reservation_date, d.sold_date, d.created_at, d.notes, d.assigned_to, d.interest_level,
               c.id AS client_id, c.nom AS client_nom, c.tel AS client_tel, c.email AS client_email,
               pr.id AS project_id, pr.code AS project_code, pr.nom AS project_nom,
               p.id AS promise_id, p.promise_number, p.status AS promise_status,
               p.total_ttc, p.is_locked,
               ps.paid_amount, ps.remaining_amount, ps.progress_percent, ps.status AS schedule_status
        FROM crm_dossiers d
        LEFT JOIN clients c      ON c.id  = d.client_id
        LEFT JOIN projects pr    ON pr.id = d.project_id
        LEFT JOIN promises p     ON p.dossier_id = d.id AND p.status != 'cancelled'
        LEFT JOIN payment_schedules ps ON ps.dossier_id = d.id");
    $log[]=['ok','vue v_dossiers','Créée / mise à jour'];
}catch(Throwable $e){ $log[]=['err','vue v_dossiers',$e->getMessage()]; $ok=false; }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><title>Migration CRM</title>
<style>
  :root{--brand:#145f7d}*{box-sizing:border-box}
  body{margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif}
  .bar{background:var(--brand);color:#fff;height:52px;display:flex;align-items:center;padding:0 24px;font-size:16px;font-weight:800;gap:16px}
  .bar a{color:rgba(255,255,255,.75);text-decoration:none;font-size:13px;font-weight:400}.bar a:hover{color:#fff}
  .wrap{max-width:740px;margin:28px auto;padding:0 20px}
  .card{background:#fff;border:1px solid #ddd;border-radius:18px;padding:24px 28px;box-shadow:0 10px 30px rgba(0,0,0,.05)}
  h1{font-size:20px;margin:0 0 4px}.sub{color:#5e5e5e;font-size:13px;margin-bottom:20px}
  .row{display:flex;align-items:flex-start;gap:10px;padding:7px 0;border-bottom:1px solid #f4f4f4;font-size:13px}
  .row:last-of-type{border:none}.ico{font-size:14px;width:20px;text-align:center;flex-shrink:0;margin-top:1px}
  .lbl{font-weight:700;min-width:250px}.det{color:#6b7280;font-size:12px}
  .banner{margin-top:20px;padding:14px;border-radius:12px;font-size:14px;font-weight:700;text-align:center}
  .ok-b{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
  .err-b{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
  .actions{display:flex;gap:10px;margin-top:16px}
  .btn{display:inline-flex;align-items:center;padding:10px 20px;border-radius:12px;border:none;cursor:pointer;font-size:14px;font-weight:700;text-decoration:none;font-family:inherit}
  .btn-brand{background:var(--brand);color:#fff}.btn-sec{background:#ececec;color:#151515}
</style>
</head>
<body>
<div class="bar"><a href="crm_dashboard.php">← CRM</a> Migration base de données CRM</div>
<div class="wrap"><div class="card">
  <h1>Migration CRM complète</h1>
  <p class="sub">Tables : crm_dossiers, promises (enrichie), payment_schedules, payment_installments, payments, receipts, crm_activities, audit_logs, vue v_dossiers</p>
  <?php foreach($log as $e): $ico=$e[0]==='ok'?'✅':($e[0]==='skip'?'⏭️':'❌'); ?>
  <div class="row"><span class="ico"><?=$ico?></span><div><div class="lbl"><?=htmlspecialchars($e[1])?></div><div class="det"><?=htmlspecialchars($e[2]??'')?></div></div></div>
  <?php endforeach; ?>
  <div class="banner <?=$ok?'ok-b':'err-b'?>"><?=$ok?'✅ Migration terminée — toutes les tables sont prêtes.':'❌ Des erreurs se sont produites.'?></div>
  <?php if($ok): ?>
  <div class="actions">
    <a href="crm_dashboard.php" class="btn btn-brand">→ Dashboard CRM</a>
    <a href="crm_dossiers.php"  class="btn btn-sec">→ Dossiers</a>
  </div>
  <?php endif; ?>
</div></div>
</body></html>
