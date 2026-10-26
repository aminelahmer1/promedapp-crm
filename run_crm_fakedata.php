<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { die('Non authentifié'); }

$log = [];
function ok($msg)  { global $log; $log[] = ['ok',  $msg]; }
function err($msg) { global $log; $log[] = ['err', $msg]; }
function warn($msg){ global $log; $log[] = ['warn',$msg]; }

// ── 1. Auto-migration : applique les colonnes manquantes ──────────────
$migrations = [
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS whatsapp          VARCHAR(30)   DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS profession        VARCHAR(100)  DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS campaign          VARCHAR(100)  DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS budget_min        DECIMAL(15,3) DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS budget_max        DECIMAL(15,3) DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS buying_delay      VARCHAR(50)   DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS financing_mode    VARCHAR(50)   DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS motivation        TEXT          DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS private_notes     TEXT          DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS last_contact_at   DATETIME      DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS next_followup_at  DATETIME      DEFAULT NULL",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS crm_status        VARCHAR(30)   NOT NULL DEFAULT 'new'",
    "ALTER TABLE clients ADD COLUMN IF NOT EXISTS interest_level    VARCHAR(20)   NOT NULL DEFAULT 'medium'",
    "ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS activity_date DATETIME     DEFAULT NULL",
    "ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS client_id    INT          DEFAULT NULL",
    "ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS subject      VARCHAR(255) DEFAULT NULL",
    "ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS result       TEXT         DEFAULT NULL",
    "ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS next_action  TEXT         DEFAULT NULL",
    "ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS due_date     DATETIME     DEFAULT NULL",
    "ALTER TABLE crm_activities ADD COLUMN IF NOT EXISTS completed_at DATETIME     DEFAULT NULL",
    "CREATE TABLE IF NOT EXISTS crm_client_needs (
      id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, project_id INT DEFAULT NULL,
      property_type VARCHAR(50) DEFAULT NULL, bedrooms INT DEFAULT NULL,
      surface_min DECIMAL(8,2) DEFAULT NULL, surface_max DECIMAL(8,2) DEFAULT NULL,
      budget_min DECIMAL(15,3) DEFAULT NULL, budget_max DECIMAL(15,3) DEFAULT NULL,
      wants_parking TINYINT(1) NOT NULL DEFAULT 0, wants_cellier TINYINT(1) NOT NULL DEFAULT 0,
      wants_pool TINYINT(1) NOT NULL DEFAULT 0, wants_independent_kitchen TINYINT(1) NOT NULL DEFAULT 0,
      wants_terrace TINYINT(1) NOT NULL DEFAULT 0, wants_garden TINYINT(1) NOT NULL DEFAULT 0,
      wants_open_view TINYINT(1) NOT NULL DEFAULT 0, preferred_floor VARCHAR(50) DEFAULT NULL,
      notes TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_cn_client (client_id))",
    "CREATE TABLE IF NOT EXISTS crm_client_units (
      id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, project_id INT DEFAULT NULL,
      unit_code VARCHAR(100) NOT NULL, interest_level VARCHAR(20) NOT NULL DEFAULT 'medium',
      status VARCHAR(30) NOT NULL DEFAULT 'interested', is_favorite TINYINT(1) NOT NULL DEFAULT 0,
      notes TEXT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_cu_client (client_id))",
    "CREATE TABLE IF NOT EXISTS crm_visits (
      id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, unit_code VARCHAR(100) DEFAULT NULL,
      project_id INT DEFAULT NULL, visit_date DATE NOT NULL, visit_time TIME DEFAULT NULL,
      location VARCHAR(200) DEFAULT NULL,
      status ENUM('planned','done','cancelled','no_show') NOT NULL DEFAULT 'planned',
      commercial_id INT DEFAULT NULL, notes TEXT DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_v_client (client_id), INDEX idx_v_date (visit_date))",
];

foreach ($migrations as $sql) {
    try { $pdo->exec($sql); }
    catch (Throwable $e) { warn('Migration: '.$e->getMessage()); }
}
// Backfill activity_date from created_at for existing rows
try { $pdo->exec("UPDATE crm_activities SET activity_date=created_at WHERE activity_date IS NULL AND created_at IS NOT NULL"); } catch(Throwable $e){}
ok('Migration appliquée ✓');

// ── 2. Guard ──────────────────────────────────────────────────────────
$exists = 0;
try { $exists = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE source='crm_demo'")->fetchColumn(); } catch(Throwable $e){}
if ($exists >= 10) {
    echo '<div style="font-family:\'Segoe UI\',Arial;padding:30px;color:#c03030;background:#fdecea;border-radius:10px;max-width:600px;margin:30px auto">
      Données de démo déjà présentes ('.$exists.' clients).<br>
      <a href="crm_dashboard.php" style="color:#145f7d">→ Dashboard CRM</a>
    </div>';
    exit;
}

// ── 3. Detect projects & users ────────────────────────────────────────
$projects = $pdo->query("SELECT id,code,nom FROM projects ORDER BY id LIMIT 4")->fetchAll();
if (!$projects) { die('<p style="font-family:Arial;color:red;padding:20px">Aucun projet trouvé.</p>'); }
$pj1 = $projects[0];
$pj2 = $projects[1] ?? $projects[0];
$pj3 = $projects[2] ?? $projects[0];

$users = $pdo->query("SELECT id,full_name FROM users ORDER BY id LIMIT 5")->fetchAll();
$u1 = $users[0]['id'] ?? $_SESSION['user_id'];
$u2 = $users[1]['id'] ?? $u1;
$u3 = $users[2]['id'] ?? $u1;

// ── 4. Probe available columns ────────────────────────────────────────
function hasCol(PDO $pdo, string $tbl, string $col): bool {
    try { $pdo->query("SELECT `$col` FROM `$tbl` LIMIT 0"); return true; } catch(Throwable $e){ return false; }
}
$hasCrmStatus  = hasCol($pdo, 'clients', 'crm_status');
$hasInterest   = hasCol($pdo, 'clients', 'interest_level');
$hasWhatsapp   = hasCol($pdo, 'clients', 'whatsapp');
$hasProfession = hasCol($pdo, 'clients', 'profession');
$hasCampaign   = hasCol($pdo, 'clients', 'campaign');
$hasBudgetMin  = hasCol($pdo, 'clients', 'budget_min');
$hasBudgetMax  = hasCol($pdo, 'clients', 'budget_max');
$hasDelay      = hasCol($pdo, 'clients', 'buying_delay');
$hasFinMode    = hasCol($pdo, 'clients', 'financing_mode');
$hasMotivation = hasCol($pdo, 'clients', 'motivation');
$hasLastCon    = hasCol($pdo, 'clients', 'last_contact_at');
$hasNextFu     = hasCol($pdo, 'clients', 'next_followup_at');

$hasActClientId = hasCol($pdo, 'crm_activities', 'client_id');
$hasActSubject  = hasCol($pdo, 'crm_activities', 'subject');
$hasActDate     = hasCol($pdo, 'crm_activities', 'activity_date');
$actUserCol     = hasCol($pdo, 'crm_activities', 'created_by') ? 'created_by' : 'user_id';

ok('Colonnes détectées ✓');

// ── 5. Fake clients ────────────────────────────────────────────────────
$clientDefs = [
  // [fn, ln, nom, tel, whatsapp, email, cin, profession, campaign, interest, crm_status, pjId, ptype, bmin, bmax, delay, fin_mode, motivation]
  ['Mohamed','Ben Salah','Ben Salah Mohamed','21650001','21650001','m.bensalah@gmail.com','12345678','Ingénieur','Facebook','hot','negotiation',$pj1['id'],'S3',320000,380000,'6months','cash','Veut S3 avec vue mer, très motivé'],
  ['Sonia','Dridi','Dridi Sonia','21650002','21650002','sonia.dridi@hotmail.com','23456789','Médecin','Référence','vip','reserved',$pj2['id'],'S4',450000,550000,'immediate','cash','Cliente VIP, budget cash, très sérieuse'],
  ['Ahmed','Trabelsi','Trabelsi Ahmed','21650003','','a.trabelsi@yahoo.fr','34567890','Commerçant','Instagram','low','new',$pj1['id'],'S2',180000,240000,'1year','bank_loan','Premier contact, pas encore convaincu'],
  ['Fatma','Chaabane','Chaabane Fatma','21650004','21650004','fatma.c@gmail.com','45678901','Enseignante','Site web','hot','visit',$pj2['id'],'S3',270000,320000,'3months','both','Très intéressée S3, visite planifiée'],
  ['Karim','Mansouri','Mansouri Karim','21650005','21650005','k.mansouri@gmail.com','56789012','Avocat','Salon','medium','interested',$pj1['id'],'S3',300000,400000,'6months','cash','Client sérieux, compare plusieurs projets'],
  ['Leila','Bchini','Bchini Leila','21650006','21650006','leila.bchini@gmail.com','67890123','Directrice','Référence','vip','reserved',$pj2['id'],'S4',480000,600000,'immediate','cash','VIP, réservation confirmée, budget très large'],
  ['Riadh','Jomaa','Jomaa Riadh','21650007','21650007','r.jomaa@gmail.com','78901234','Entrepreneur','Instagram','high','contacted',$pj1['id'],'S2',200000,280000,'6months','bank_loan','Cherche investissement locatif'],
  ['Nadia','Sfaxi','Sfaxi Nadia','21650008','','nadia.sfaxi@outlook.com','89012345','Comptable','Facebook','low','new',$pj1['id'],'S1',150000,200000,'not_decided','bank_loan','Cherche petit appartement, budget limité'],
  ['Mehdi','Khelil','Khelil Mehdi','21650009','21650009','mehdi.khelil@gmail.com','90123456','Architecte','Site web','medium','interested',$pj2['id'],'S3',280000,360000,'6months','cash','Intéressé par les grandes surfaces'],
  ['Yasmine','Bou Saada','Bou Saada Yasmine','21650010','21650010','yasmine.bs@gmail.com','01234567','Pharmacienne','Référence','hot','negotiation',$pj1['id'],'S3',310000,390000,'3months','cash','En négociation prix, très chaude'],
  ['Tarek','Oueslati','Oueslati Tarek','21650011','21650011','t.oueslati@gmail.com','11234567','Directeur','LinkedIn','high','negotiation',$pj2['id'],'S2',240000,300000,'3months','both','Négocie sur le prix, sérieux'],
  ['Hana','Meddeb','Meddeb Hana','21650012','','hana.meddeb@gmail.com','22345678','Professeure','Facebook','medium','contacted',$pj1['id'],'S2',190000,260000,'6months','bank_loan','Cherche pour habiter, pas pour investir'],
  ['Sami','Gharbi','Gharbi Sami','21650013','21650013','sami.gharbi@gmail.com','33456789','Chef entreprise','Référence','vip','reserved',$pj2['id'],'S4',500000,580000,'immediate','cash','A réservé S4 + parking double'],
  ['Rim','Ghorbel','Ghorbel Rim','21650014','21650014','rim.ghorbel@gmail.com','44567890','Infirmière','Site web','high','visit',$pj1['id'],'S2',210000,270000,'6months','bank_loan','Visite prévue, intéressée par étage élevé'],
  ['Amine','Jouini','Jouini Amine','21650015','21650015','amine.jouini@gmail.com','55678901','Ingénieur','Instagram','medium','new',$pj2['id'],'S3',260000,340000,'1year','both','Nouveau lead, premier contact à faire'],
];

$dossierStatMap = [
    'new'=>'interested','contacted'=>'interested','interested'=>'interested',
    'visit'=>'interested','negotiation'=>'promise_draft','reserved'=>'reserved',
    'lost'=>'cancelled','sold'=>'sold',
];

$clientIds  = [];
$dossierIds = [];

foreach ($clientDefs as $i => [$fn,$ln,$nom,$tel,$wa,$email,$cin,$prof,$camp,$ilevel,$crmst,$pjId,$ptype,$bmin,$bmax,$delay,$finMode,$motiv]) {
    $assignTo   = [$u1,$u2,$u3][$i % 3];
    $lastContact= date('Y-m-d H:i:s', strtotime('-'.rand(1,30).' days'));
    $nextFu     = date('Y-m-d H:i:s', strtotime('+'.rand(1,14).' days'));

    // Build dynamic INSERT
    $cols   = ['nom','first_name','last_name','tel','email','cin','source','assigned_to','created_at'];
    $vals   = ['?','?','?','?','?','?','?','?','DATE_SUB(NOW(),INTERVAL ? DAY)'];
    $params = [$nom,$fn,$ln,$tel,$email,$cin,'crm_demo',$assignTo,rand(5,120)];

    if ($hasWhatsapp)   { $cols[]='whatsapp';       $vals[]='?'; $params[]=$wa; }
    if ($hasProfession) { $cols[]='profession';     $vals[]='?'; $params[]=$prof; }
    if ($hasCampaign)   { $cols[]='campaign';       $vals[]='?'; $params[]=$camp; }
    if ($hasBudgetMin)  { $cols[]='budget_min';     $vals[]='?'; $params[]=(float)$bmin; }
    if ($hasBudgetMax)  { $cols[]='budget_max';     $vals[]='?'; $params[]=(float)$bmax; }
    if ($hasDelay)      { $cols[]='buying_delay';   $vals[]='?'; $params[]=$delay; }
    if ($hasFinMode)    { $cols[]='financing_mode'; $vals[]='?'; $params[]=$finMode; }
    if ($hasMotivation) { $cols[]='motivation';     $vals[]='?'; $params[]=$motiv; }
    if ($hasCrmStatus)  { $cols[]='crm_status';     $vals[]='?'; $params[]=$crmst; }
    if ($hasInterest)   { $cols[]='interest_level'; $vals[]='?'; $params[]=$ilevel; }
    if ($hasLastCon)    { $cols[]='last_contact_at';$vals[]='?'; $params[]=$lastContact; }
    if ($hasNextFu)     { $cols[]='next_followup_at';$vals[]='?'; $params[]=$nextFu; }

    $sql = "INSERT INTO clients (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
    try {
        $pdo->prepare($sql)->execute($params);
        $cid = (int)$pdo->lastInsertId();
        $clientIds[$i] = $cid;
        ok("Client #{$cid} : {$nom}");
    } catch (Throwable $e) {
        err("Client {$nom} : ".$e->getMessage());
        continue;
    }

    // crm_client_needs
    try {
        $pdo->prepare("INSERT INTO crm_client_needs
            (client_id,project_id,property_type,surface_min,surface_max,budget_min,budget_max,
             wants_parking,wants_cellier,wants_terrace,wants_open_view,preferred_floor)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$cid,$pjId,$ptype,rand(80,150),rand(160,250),(float)$bmin,(float)$bmax,
                       rand(0,1),rand(0,1),rand(0,1),rand(0,1),
                       ['RDC','1er','2ème','3ème','Haut'][rand(0,4)]]);
    } catch(Throwable $e){ warn("Needs: ".$e->getMessage()); }

    // crm_dossier
    $dNum  = 'DOS-'.date('Y').'-'.str_pad($cid,4,'0',STR_PAD_LEFT);
    $dStat = $dossierStatMap[$crmst] ?? 'interested';
    $unitSuffixes = ['101','202','303','404','101','202','303','404','105','205','305','405','106','206','306'];
    $unitCode = $ptype.'-'.chr(65+($i%4)).'-'.$unitSuffixes[$i];
    try {
        $pdo->prepare("INSERT INTO crm_dossiers (dossier_number,client_id,project_id,unit_code,status,interest_level,assigned_to,budget,source,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,DATE_SUB(NOW(),INTERVAL ? DAY))")
            ->execute([$dNum,$cid,$pjId,$unitCode,$dStat,$ilevel,$assignTo,(float)$bmin,'crm_demo',rand(5,120)]);
        $did = (int)$pdo->lastInsertId();
        $dossierIds[$i] = $did;
        ok("  Dossier #{$did} : {$dNum} ({$unitCode})");
    } catch(Throwable $e){ warn("Dossier {$dNum}: ".$e->getMessage()); }

    // crm_client_units (favori)
    try {
        $pdo->prepare("INSERT INTO crm_client_units (client_id,project_id,unit_code,interest_level,is_favorite) VALUES (?,?,?,?,1)")
            ->execute([$cid,$pjId,$unitCode,$ilevel]);
    } catch(Throwable $e){}

    // 2nd unit (autre projet)
    $unit2 = $ptype.'-'.chr(65+(($i+1)%4)).'-'.($unitSuffixes[($i+1)%15]??'201');
    try {
        $pdo->prepare("INSERT INTO crm_client_units (client_id,project_id,unit_code,interest_level) VALUES (?,?,?,?)")
            ->execute([$cid,$pj2['id'],$unit2,'medium']);
    } catch(Throwable $e){}
}

// ── 6. Activités / suivis ─────────────────────────────────────────────
$actTypes    = ['call','whatsapp','email','meeting','call','whatsapp','note'];
$subjects    = ['Premier contact téléphonique','Envoi brochure WhatsApp','Réponse email',
                'RDV bureau','Relance client','Message WhatsApp suivi','Note interne',
                'Confirmation visite','Négociation prix','Envoi plan WhatsApp'];
$descriptions= ['Client intéressé, demande prix et disponibilités.',
                'Brochure envoyée, client a bien reçu.',
                'Client répond positivement, intéressé par le bien.',
                'RDV bureau, présentation projet, client enthousiaste.',
                'Client n\'a pas répondu, relance dans 2 jours.',
                'Client dit qu\'il réfléchit.',
                'Client a visité appartement témoin, très satisfait.',
                'Discussion prix, client demande remise parking.',
                'Email envoyé avec détails financement bancaire.',
                'Visite confirmée pour la semaine prochaine.'];

foreach ($clientIds as $i => $cid) {
    $did = $dossierIds[$i] ?? null;
    for ($j=0; $j<rand(2,5); $j++) {
        $daysAgo = rand(1, max(1,50-$j*5));
        $atype   = $actTypes[($i+$j) % count($actTypes)];
        $subj    = $subjects[($i+$j) % count($subjects)];
        $desc    = $descriptions[($i+$j) % count($descriptions)];
        $userId  = [$u1,$u2,$u3][$j % 3];
        $actDate = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));

        $cols   = ['activity_type','description',$actUserCol,'dossier_id'];
        $params = [$atype,$desc,$userId,$did];
        if ($hasActDate) { $cols[] = 'activity_date'; $params[] = $actDate; }

        if ($hasActClientId){ $cols[]='client_id'; $params[]=$cid; }
        if ($hasActSubject) { $cols[]='subject';   $params[]=$subj; }

        $ph = implode(',',array_fill(0,count($params),'?'));
        try {
            $pdo->prepare("INSERT INTO crm_activities (".implode(',',$cols).") VALUES ($ph)")->execute($params);
        } catch(Throwable $e){ warn("Act: ".$e->getMessage()); }
    }
}
ok('Activités insérées ✓');

// ── 7. Visites planifiées ─────────────────────────────────────────────
$visitDefs = [
    [0, date('Y-m-d',strtotime('+1 day')), '09:00','Appartement témoin','planned'],
    [1, date('Y-m-d',strtotime('+2 days')),'10:30','Bureau Promed Tunis','planned'],
    [3, date('Y-m-d',strtotime('+3 days')),'14:00','Chantier '.$pj1['code'],'planned'],
    [4, date('Y-m-d',strtotime('+1 day')), '16:00','Bureau Promed Lac','planned'],
    [9, date('Y-m-d',strtotime('-3 days')),'10:00','Appartement témoin','done'],
    [13,date('Y-m-d',strtotime('+4 days')),'11:00','Chantier '.$pj2['code'],'planned'],
];
foreach ($visitDefs as [$ci, $vdate, $vtime, $vloc, $vstat]) {
    $cid = $clientIds[$ci] ?? null;
    if (!$cid) continue;
    $pjId = ($ci % 2 === 0) ? $pj1['id'] : $pj2['id'];
    try {
        $pdo->prepare("INSERT INTO crm_visits (client_id,project_id,visit_date,visit_time,location,status,commercial_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$cid,$pjId,$vdate,$vtime,$vloc,$vstat,[$u1,$u2,$u3][$ci%3]]);
    } catch(Throwable $e){ warn("Visit: ".$e->getMessage()); }
}
ok('Visites insérées ✓');

// ── 8. Update last_contact ────────────────────────────────────────────
if ($hasLastCon) {
    $lcDateExpr = $hasActDate ? 'activity_date' : 'created_at';
    try {
        $pdo->exec("UPDATE clients c
            JOIN (SELECT ".($hasActClientId?'client_id':'(SELECT client_id FROM crm_dossiers WHERE id=dossier_id LIMIT 1) AS client_id').",
                        MAX(`$lcDateExpr`) AS lc
                  FROM crm_activities WHERE ".($hasActClientId?'client_id IS NOT NULL':'dossier_id IS NOT NULL')."
                  GROUP BY ".($hasActClientId?'client_id':'dossier_id').") a
            ON a.client_id = c.id
            SET c.last_contact_at = a.lc
            WHERE c.source = 'crm_demo'");
    } catch(Throwable $e){ warn('last_contact update: '.$e->getMessage()); }
}

$total = count($clientIds);
?><!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Données de démo CRM</title>
<style>
body{font-family:'Segoe UI',Arial,sans-serif;padding:30px;background:#f4f6f8;color:#151515;margin:0}
.card{background:#fff;border:1px solid #dde3ea;border-radius:14px;padding:28px;max-width:860px;margin:0 auto;box-shadow:0 4px 20px rgba(0,0,0,.06)}
h1{color:#145f7d;margin:0 0 4px;font-size:22px}
.sub{color:#5e6878;margin:0 0 20px;font-size:14px}
.log{font-size:12px;font-family:monospace;background:#f5f7fa;border-radius:8px;padding:14px;max-height:380px;overflow:auto;margin:16px 0;line-height:1.7}
.ok{color:#19733e}.err{color:#c03030}.warn{color:#d97000}
.btns{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}
.btn{display:inline-block;padding:12px 22px;border-radius:9px;text-decoration:none;font-weight:700;font-size:14px;transition:opacity .15s}
.btn:hover{opacity:.85}
.btn-brand{background:#145f7d;color:#fff}
.btn-outline{background:#e2eef5;color:#145f7d}
.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
.s-box{text-align:center;padding:14px;background:#f5f7fa;border-radius:9px}
.s-n{font-size:24px;font-weight:800;color:#145f7d}
.s-l{font-size:11px;font-weight:700;text-transform:uppercase;color:#5e6878;letter-spacing:.04em}
</style></head>
<body>
<div class="card">
  <h1>Données de démo CRM insérées</h1>
  <p class="sub">Projets : <strong><?=htmlspecialchars($pj1['code'])?></strong> et <strong><?=htmlspecialchars($pj2['code'])?></strong></p>

  <div class="summary">
    <div class="s-box"><div class="s-n"><?=$total?></div><div class="s-l">Clients créés</div></div>
    <div class="s-box"><div class="s-n"><?=count($dossierIds)?></div><div class="s-l">Dossiers</div></div>
    <div class="s-box"><div class="s-n"><?=count($visitDefs)?></div><div class="s-l">Visites</div></div>
  </div>

  <div class="log">
    <?php foreach($log as [$t,$m]): ?>
    <div class="<?=$t?>"><?=htmlspecialchars($m)?></div>
    <?php endforeach; ?>
  </div>

  <div class="btns">
    <a href="crm_dashboard.php" class="btn btn-brand">→ Dashboard CRM</a>
    <a href="crm_clients.php" class="btn btn-outline">→ Liste clients</a>
    <a href="crm_pipeline.php" class="btn btn-outline">→ Pipeline</a>
    <a href="crm_units.php" class="btn btn-outline">→ Biens</a>
  </div>
</div>
</body></html>
