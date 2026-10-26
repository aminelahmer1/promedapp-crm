<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id']))           { header('Location: login.php');          exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: select_project.php'); exit; }

$promedLogo = 'assets/images/PromedGroup.png';

/* ══ Helpers ═══════════════════════════════════════════════════════════ */
function fmtNum($n, int $dec = 0): string {
    return number_format((float)$n, $dec, ',', ' ');
}
function pct($a, $b): float {
    return $b > 0 ? round($a / $b * 100, 1) : 0;
}
function pctBar(float $p, string $color = '#145f7d'): string {
    $w = min(100, max(0, $p));
    return "<div class='bar-wrap'><div class='bar-fill' style='width:{$w}%;background:{$color}'></div></div>";
}

/* ── Normalise une désignation → catégorie affichable ────────────────── */
function normCategory(string $desig, string $type): string {
    $d = strtoupper(trim($desig));
    $t = strtoupper(trim($type));
    if (in_array($d, ['COMMERCE','LOCAL COMMERCIAL','LC'])) return 'Commerce';
    if (str_contains($d, 'DUPLEX'))     return 'Duplex';
    if ($d === 'PENTHOUSE')             return 'Penthouse';
    if ($d === 'LOFT')                  return 'Loft';
    return 'Appartement';
}

/* ══ 1. Unités vente par projet ══════════════════════════════════════════ */
$unitRows = $pdo->query("
    SELECT p.id AS proj_id, p.code AS proj_code, p.nom AS proj_nom,
      COUNT(u.id) AS total,
      SUM(CASE WHEN UPPER(u.status_code) IN ('V','VENDU')                                          THEN 1 ELSE 0 END) AS vendus,
      SUM(CASE WHEN UPPER(u.status_code) IN ('R','RESERVE','RÉSERVÉ')                              THEN 1 ELSE 0 END) AS reserves,
      SUM(CASE WHEN UPPER(u.status_code) IN ('D','DISPONIBLE') OR u.status_code IS NULL OR u.status_code='' THEN 1 ELSE 0 END) AS disponibles,
      ROUND(SUM(COALESCE(u.surface,0)),2)                                                          AS surface_totale,
      ROUND(SUM(CASE WHEN UPPER(u.status_code) IN ('V','VENDU') THEN COALESCE(u.surface,0) ELSE 0 END),2) AS surface_vendue,
      ROUND(SUM(COALESCE(u.price_ttc_sale,0)),0)                                                   AS valeur_catalogue
    FROM projects p
    LEFT JOIN units u ON u.project_id = p.id AND u.operation_mode = 'sale'
    WHERE UPPER(p.code) IN ('AVA','FLAVA','EVOLO')
    GROUP BY p.id, p.code, p.nom
    ORDER BY p.code
")->fetchAll(PDO::FETCH_ASSOC);

/* ══ 2. Accessoires par projet ═══════════════════════════════════════════ */
$accRows = $pdo->query("
    SELECT p.code AS proj_code,
      SUM(a.accessory_type='PARKING')                                                              AS pk_total,
      SUM(a.accessory_type='PARKING' AND UPPER(a.status_code) IN ('V','VENDU'))                   AS pk_vendus,
      SUM(a.accessory_type='PARKING' AND UPPER(a.status_code) IN ('R','RESERVE','RÉSERVÉ'))        AS pk_reserves,
      SUM(a.accessory_type='CELLIER')                                                              AS ce_total,
      SUM(a.accessory_type='CELLIER' AND UPPER(a.status_code) IN ('V','VENDU'))                   AS ce_vendus,
      SUM(a.accessory_type='CELLIER' AND UPPER(a.status_code) IN ('R','RESERVE','RÉSERVÉ'))        AS ce_reserves
    FROM projects p
    LEFT JOIN unit_accessoires a ON a.project_id = p.id
    WHERE UPPER(p.code) IN ('AVA','FLAVA','EVOLO')
    GROUP BY p.code
")->fetchAll(PDO::FETCH_ASSOC);
$acc = [];
foreach ($accRows as $r) $acc[$r['proj_code']] = $r;

/* ══ 3. Prix moyens — toutes unités brutes ═══════════════════════════════ */
$rawUnits = $pdo->query("
    SELECT p.code AS proj,
           UPPER(TRIM(COALESCE(u.designation,''))) AS desig,
           UPPER(TRIM(COALESCE(u.type,'')))        AS typ,
           UPPER(TRIM(u.status_code))              AS sc,
           u.price_ttc_sale,
           u.surface
    FROM units u JOIN projects p ON p.id = u.project_id
    WHERE UPPER(p.code) IN ('AVA','FLAVA','EVOLO')
      AND u.operation_mode = 'sale'
      AND u.price_ttc_sale > 0
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Prix moyens accessoires ─────────────────────────────────────────── */
$rawAcc = $pdo->query("
    SELECT p.code AS proj, UPPER(a.accessory_type) AS acc_type,
           UPPER(TRIM(a.status_code)) AS sc, a.price_ttc_sale
    FROM unit_accessoires a JOIN projects p ON p.id = a.project_id
    WHERE UPPER(p.code) IN ('AVA','FLAVA','EVOLO')
      AND a.operation_mode = 'sale'
      AND a.price_ttc_sale > 0
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Construire le tableau prix moyens par [proj][catégorie][statut] ──── */
// statut : 'vendu' | 'dispo'
$pm = []; // $pm[$proj][$cat] = ['v_sum'=>,'v_nb'=>,'d_sum'=>,'d_nb'=>]

function addPM(array &$pm, string $proj, string $cat, string $sc, float $price): void {
    if (!isset($pm[$proj][$cat])) $pm[$proj][$cat] = ['v_sum'=>0,'v_nb'=>0,'d_sum'=>0,'d_nb'=>0];
    if (in_array($sc, ['V','VENDU'])) {
        $pm[$proj][$cat]['v_sum'] += $price;
        $pm[$proj][$cat]['v_nb']++;
    } elseif (in_array($sc, ['D','DISPONIBLE',''])) {
        $pm[$proj][$cat]['d_sum'] += $price;
        $pm[$proj][$cat]['d_nb']++;
    }
}

foreach ($rawUnits as $u) {
    $cat = normCategory($u['desig'], $u['typ']);
    addPM($pm, $u['proj'], $cat, $u['sc'], (float)$u['price_ttc_sale']);
}
foreach ($rawAcc as $a) {
    $cat = ucfirst(strtolower($a['acc_type']));
    addPM($pm, $a['proj'], $cat, $a['sc'], (float)$a['price_ttc_sale']);
}

/* ── Ordre d'affichage des catégories ─────────────────────────────────── */
$catOrder = ['Appartement','Duplex','Penthouse','Loft','Commerce','Parking','Cellier'];

/* ══ 4. Financier ════════════════════════════════════════════════════════ */
$finRows = $pdo->query("
    SELECT pr.promise_number, pr.unit_code, pr.status AS promise_status,
           COALESCE(NULLIF(TRIM(CONCAT(COALESCE(cl.first_name,''),' ',COALESCE(cl.last_name,''))),''), cl.nom, '—') AS client_nom,
           COALESCE(p.code,'—') AS proj_code,
           ps.total_amount, ps.paid_amount, ps.remaining_amount,
           ps.progress_percent, ps.status AS sched_status
    FROM promises pr
    LEFT JOIN clients cl  ON cl.id = pr.client_id
    LEFT JOIN units u     ON UPPER(u.ref_code COLLATE utf8mb4_unicode_ci) = UPPER(pr.unit_code COLLATE utf8mb4_unicode_ci)
    LEFT JOIN projects p  ON p.id = u.project_id
    LEFT JOIN payment_schedules ps ON ps.promise_id = pr.id
    WHERE pr.status IN ('signed','converted_to_sale')
    ORDER BY ps.paid_amount DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ══ 5. Expiration réservations ══════════════════════════════════════════ */
$cutoff7 = date('Y-m-d H:i:s', strtotime('-7 days'));
$pdo->prepare("UPDATE units SET status_code='D', reserved_at=NULL
    WHERE status_code='R' AND reserved_at IS NOT NULL AND reserved_at < ?")->execute([$cutoff7]);
$pdo->prepare("UPDATE unit_accessoires SET status_code='D', reserved_at=NULL
    WHERE status_code='R' AND reserved_at IS NOT NULL AND reserved_at < ?")->execute([$cutoff7]);

$expiringRows = $pdo->query("
    SELECT u.ref_code AS code, 'Appartement' AS kind, p.code AS proj_code, u.reserved_at,
           TIMESTAMPDIFF(HOUR, NOW(), DATE_ADD(u.reserved_at, INTERVAL 7 DAY)) AS heures_restantes
    FROM units u JOIN projects p ON p.id = u.project_id
    WHERE u.status_code = 'R' AND u.reserved_at IS NOT NULL
    UNION ALL
    SELECT a.code, CONCAT('Acc. ',a.accessory_type), p.code, a.reserved_at,
           TIMESTAMPDIFF(HOUR, NOW(), DATE_ADD(a.reserved_at, INTERVAL 7 DAY))
    FROM unit_accessoires a JOIN projects p ON p.id = a.project_id
    WHERE a.status_code = 'R' AND a.reserved_at IS NOT NULL
    ORDER BY heures_restantes ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ══ 6. Paiements en retard ═════════════════════════════════════════════ */
$overdueCount = (int)$pdo->query("
    SELECT COUNT(*) FROM payment_installments WHERE status IN ('overdue','pending') AND due_date < CURDATE()
")->fetchColumn();
$overdueAmount = (float)$pdo->query("
    SELECT COALESCE(SUM(expected_amount - paid_amount),0) FROM payment_installments
    WHERE status IN ('overdue','pending') AND due_date < CURDATE()
")->fetchColumn();

/* ══ Agrégats globaux ════════════════════════════════════════════════════ */
$totUnits    = array_sum(array_column($unitRows, 'total'));
$totVendus   = array_sum(array_column($unitRows, 'vendus'));
$totReserves = array_sum(array_column($unitRows, 'reserves'));
$totDispo    = array_sum(array_column($unitRows, 'disponibles'));
$totPK       = array_sum(array_column($accRows,  'pk_total'));
$totCE       = array_sum(array_column($accRows,  'ce_total'));
$totEncaisse = array_sum(array_column($finRows,  'paid_amount'));
$totRestant  = array_sum(array_column($finRows,  'remaining_amount'));
$totContrats = array_sum(array_column($finRows,  'total_amount'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Statistiques — PROMED Admin</title>
<style>
:root{--brand:#145f7d;--border:#e6e6e6;--bg:#fafafa}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#fff;min-height:100vh;padding:36px 24px}
.shell{max-width:1260px;margin:0 auto}

/* Header */
.header{text-align:center;margin-bottom:36px}
.logo-main{display:inline-flex;align-items:center;justify-content:center;height:80px;margin-bottom:10px}
.logo-main img{max-height:72px}
.page-title{font-size:22px;font-weight:700;color:#111;margin:0}
.page-sub{font-size:13px;color:#888;margin-top:4px}

/* KPI cards */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:32px}
.kpi{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px 18px 14px;text-align:center;box-shadow:0 4px 16px rgba(0,0,0,.04)}
.kpi-val{font-size:26px;font-weight:800;color:#111;line-height:1;margin-bottom:4px}
.kpi-lbl{font-size:11px;color:#999;text-transform:uppercase;letter-spacing:.06em;font-weight:600}
.kpi-sub{font-size:11px;color:#bbb;margin-top:2px}
.kpi.accent-green .kpi-val{color:#166534}
.kpi.accent-blue  .kpi-val{color:var(--brand)}
.kpi.accent-amber .kpi-val{color:#92400e}
.kpi.accent-red   .kpi-val{color:#b3261e}

/* Section title */
.sec-title{font-size:15px;font-weight:700;color:#111;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.sec-title::after{content:'';flex:1;height:1px;background:#f0f0f0}

/* Alertes */
.overdue-box{background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:14px 18px;margin-bottom:24px;display:flex;align-items:center;gap:12px;font-size:13px}
.overdue-box strong{color:#92400e}

/* ── Prix moyens ─────────────────────────────────────────────────────── */
.pm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;margin-bottom:36px}
.pm-card{border:1px solid var(--border);border-radius:20px;overflow:hidden;background:#fff;box-shadow:0 4px 16px rgba(0,0,0,.04)}
.pm-card-head{padding:14px 20px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between}
.pm-card-title{font-size:16px;font-weight:800;color:#111}
.pm-table{width:100%;border-collapse:collapse;font-size:13px}
.pm-table th{background:#f8fafc;color:#6b7280;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:8px 14px;text-align:right;border-bottom:1px solid #f0f0f0}
.pm-table th:first-child{text-align:left}
.pm-table td{padding:10px 14px;border-bottom:1px solid #f8f8f8;vertical-align:middle}
.pm-table td:first-child{font-weight:600;color:#374151}
.pm-table tr:last-child td{border-bottom:none}
.pm-table tr:hover td{background:#fafbff}
.pm-val{text-align:right;font-weight:700;color:#111}
.pm-val.v{color:#166534}
.pm-val.d{color:var(--brand)}
.pm-nb{font-size:11px;color:#9ca3af;font-weight:400;display:block}
.pm-dash{text-align:right;color:#d1d5db}

/* Barre de progression */
.bar-wrap{height:6px;background:#f0f0f0;border-radius:999px;overflow:hidden;margin-top:2px}
.bar-fill{height:100%;border-radius:999px}

/* Cartes projet */
.proj-section{margin-bottom:36px}
.proj-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:18px}
.proj-card{border:1px solid var(--border);border-radius:20px;overflow:hidden;background:#fff;box-shadow:0 4px 16px rgba(0,0,0,.04)}
.proj-card-head{padding:16px 20px 12px;border-bottom:1px solid #f5f5f5;display:flex;align-items:center;justify-content:space-between}
.proj-card-name{font-size:16px;font-weight:800;color:#111}
.proj-card-body{padding:16px 20px}
.stat-row{display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f5f5f5;font-size:13px}
.stat-row:last-child{border-bottom:none}
.stat-label{color:#666;font-weight:500}
.stat-val{font-weight:700;color:#111;text-align:right}
.stat-val.green{color:#166534}
.stat-val.amber{color:#92400e}
.stat-val.blue{color:var(--brand)}

/* Pill */
.pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700}
.pill-green{background:#f0fdf4;color:#166534}
.pill-amber{background:#fef3c7;color:#92400e}
.pill-red  {background:#fff0f0;color:#b3261e}
.pill-blue {background:#e0f2fe;color:#0369a1}
.pill-gray {background:#f4f4f4;color:#666}

/* Tableau financier */
.fin-table-wrap{border:1px solid var(--border);border-radius:18px;overflow:auto}
table.fin-table{width:100%;border-collapse:collapse;font-size:13px}
table.fin-table th{background:#fafafa;color:#999;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:10px 14px;text-align:left;border-bottom:1px solid #f0f0f0;white-space:nowrap}
table.fin-table td{padding:12px 14px;border-bottom:1px solid #f8f8f8;vertical-align:middle}
table.fin-table tr:last-child td{border-bottom:none}
table.fin-table tr:hover td{background:#fcfcfc}
table.fin-table tfoot td{background:#fafafa;font-weight:700;color:#111;border-top:2px solid #e6e6e6}

/* Footer */
.footer-row{margin-top:32px;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#999;border-top:1px solid #f0f0f0;padding-top:20px}
@media(max-width:640px){body{padding:20px 12px}.proj-cards,.pm-grid{grid-template-columns:1fr}.kpi-grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<div class="shell">

  <header class="header">
    <div class="logo-main">
      <?php if (is_file($promedLogo)): ?>
        <img src="<?= htmlspecialchars($promedLogo) ?>" alt="PROMED">
      <?php endif; ?>
    </div>
    <h1 class="page-title">Tableau de bord — Statistiques</h1>
    <p class="page-sub">Données en temps réel · <?= date('d/m/Y à H:i') ?></p>
  </header>

  <?php if ($overdueCount > 0): ?>
  <div class="overdue-box">
    <span style="font-size:22px">⚠️</span>
    <div>
      <strong><?= $overdueCount ?> échéance<?= $overdueCount > 1 ? 's' : '' ?> en retard</strong>
      — montant total : <strong><?= fmtNum($overdueAmount) ?> TND</strong>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($expiringRows)): ?>
  <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:14px;padding:14px 18px;margin-bottom:24px">
    <div style="font-size:13px;font-weight:700;color:#92400e;margin-bottom:10px">
      ⏳ <?= count($expiringRows) ?> réservation<?= count($expiringRows)>1?'s':'' ?> en cours
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <?php foreach ($expiringRows as $er):
        $h = (int)$er['heures_restantes'];
        $jours = floor($h/24); $heures = $h%24;
        $urgency = $h<=24 ? '#b3261e' : ($h<=72 ? '#d97706' : '#166534');
        $label = $h<=0 ? 'Expirée' : ($jours>0 ? "{$jours}j {$heures}h" : "{$h}h");
      ?>
      <div style="background:#fff;border:1px solid #fde68a;border-radius:10px;padding:7px 12px;font-size:12px">
        <span style="font-weight:700;color:#111"><?= htmlspecialchars($er['proj_code']) ?> · <?= htmlspecialchars($er['code']) ?></span>
        <span style="color:#888;margin:0 6px">·</span>
        <span style="color:<?= $urgency ?>;font-weight:700"><?= $label ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══ KPI GLOBAUX ══════════════════════════════════════════════════ -->
  <div class="sec-title">📊 Vue globale</div>
  <div class="kpi-grid">
    <div class="kpi accent-blue">
      <div class="kpi-val"><?= $totUnits ?></div>
      <div class="kpi-lbl">Total unités</div>
      <div class="kpi-sub">Vente — tous projets</div>
    </div>
    <div class="kpi accent-green">
      <div class="kpi-val"><?= $totVendus ?></div>
      <div class="kpi-lbl">Vendus</div>
      <div class="kpi-sub"><?= pct($totVendus,$totUnits) ?>% du total</div>
    </div>
    <div class="kpi accent-amber">
      <div class="kpi-val"><?= $totReserves ?></div>
      <div class="kpi-lbl">Réservés</div>
      <div class="kpi-sub"><?= pct($totReserves,$totUnits) ?>% du total</div>
    </div>
    <div class="kpi">
      <div class="kpi-val"><?= $totDispo ?></div>
      <div class="kpi-lbl">Disponibles</div>
      <div class="kpi-sub"><?= pct($totDispo,$totUnits) ?>% du total</div>
    </div>
    <div class="kpi accent-blue">
      <div class="kpi-val"><?= $totPK ?></div>
      <div class="kpi-lbl">Parkings</div>
      <div class="kpi-sub">Toutes résidences</div>
    </div>
    <div class="kpi">
      <div class="kpi-val"><?= $totCE ?></div>
      <div class="kpi-lbl">Celliers</div>
      <div class="kpi-sub">Toutes résidences</div>
    </div>
    <div class="kpi accent-green">
      <div class="kpi-val"><?= fmtNum($totEncaisse) ?></div>
      <div class="kpi-lbl">Encaissé TND</div>
      <div class="kpi-sub"><?= pct($totEncaisse,$totContrats) ?>% des contrats</div>
    </div>
    <div class="kpi accent-red">
      <div class="kpi-val"><?= fmtNum($totRestant) ?></div>
      <div class="kpi-lbl">Reste à encaisser</div>
      <div class="kpi-sub"><?= pct($totRestant,$totContrats) ?>% des contrats</div>
    </div>
  </div>

  <!-- ═══ PRIX MOYENS ══════════════════════════════════════════════════ -->
  <div class="sec-title">💹 Prix moyens par catégorie</div>
  <div class="pm-grid">
  <?php
  $projOrder = ['AVA','FLAVA','EVOLO'];
  foreach ($projOrder as $proj):
    if (!isset($pm[$proj])) continue;
    $projData = $pm[$proj];
    // Trier les catégories dans l'ordre défini
    $cats = [];
    foreach ($catOrder as $c) {
        if (isset($projData[$c])) $cats[$c] = $projData[$c];
    }
    // Catégories restantes non dans l'ordre standard
    foreach ($projData as $c => $v) {
        if (!isset($cats[$c])) $cats[$c] = $v;
    }
    if (empty($cats)) continue;
  ?>
  <div class="pm-card">
    <div class="pm-card-head">
      <span class="pm-card-title"><?= $proj ?></span>
      <span style="font-size:12px;color:#9ca3af">Prix TTC</span>
    </div>
    <table class="pm-table">
      <thead>
        <tr>
          <th>Catégorie</th>
          <th style="color:#166534">Vendus</th>
          <th style="color:#166534">Prix moy. vendu</th>
          <th style="color:var(--brand)">Disponibles</th>
          <th style="color:var(--brand)">Prix moy. dispo</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($cats as $cat => $d):
        $avgV = $d['v_nb'] > 0 ? round($d['v_sum'] / $d['v_nb']) : null;
        $avgD = $d['d_nb'] > 0 ? round($d['d_sum'] / $d['d_nb']) : null;
      ?>
        <tr>
          <td><?= htmlspecialchars($cat) ?></td>
          <td class="pm-val v">
            <?= $d['v_nb'] > 0 ? $d['v_nb'] : '<span class="pm-dash">—</span>' ?>
          </td>
          <td class="pm-val v">
            <?= $avgV !== null ? fmtNum($avgV).' TND' : '<span class="pm-dash">—</span>' ?>
          </td>
          <td class="pm-val d">
            <?= $d['d_nb'] > 0 ? $d['d_nb'] : '<span class="pm-dash">—</span>' ?>
          </td>
          <td class="pm-val d">
            <?= $avgD !== null ? fmtNum($avgD).' TND' : '<span class="pm-dash">—</span>' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>
  </div>

  <!-- ═══ DÉTAIL PAR PROJET ════════════════════════════════════════════ -->
  <div class="sec-title">🏗 Détail par projet</div>
  <div class="proj-cards">
  <?php foreach ($unitRows as $r):
    $code  = $r['proj_code'];
    $a     = $acc[$code] ?? [];
    $pctV  = pct($r['vendus'],    $r['total']);
    $pctR  = pct($r['reserves'],  $r['total']);
    $pktot = (int)($a['pk_total']  ?? 0);
    $pkv   = (int)($a['pk_vendus'] ?? 0);
    $pkr   = (int)($a['pk_reserves'] ?? 0);
    $pkd   = max(0, $pktot - $pkv - $pkr);
    $cetot = (int)($a['ce_total']  ?? 0);
    $cev   = (int)($a['ce_vendus'] ?? 0);
    $cer   = (int)($a['ce_reserves'] ?? 0);
    $ced   = max(0, $cetot - $cev - $cer);
  ?>
  <div class="proj-card">
    <div class="proj-card-head">
      <span class="proj-card-name"><?= htmlspecialchars($code) ?></span>
      <span class="pill pill-blue"><?= $r['total'] ?> appts</span>
    </div>
    <div class="proj-card-body">

      <?php if ($r['total'] > 0): ?>
      <div class="stat-row">
        <span class="stat-label">Vendus</span>
        <span class="stat-val green"><?= $r['vendus'] ?> <small style="font-weight:400;color:#999">(<?= $pctV ?>%)</small></span>
      </div>
      <div style="padding:2px 0 6px"><?= pctBar($pctV,'#166534') ?></div>

      <div class="stat-row">
        <span class="stat-label">Réservés</span>
        <span class="stat-val amber"><?= $r['reserves'] ?> <small style="font-weight:400;color:#999">(<?= $pctR ?>%)</small></span>
      </div>

      <div class="stat-row">
        <span class="stat-label">Disponibles</span>
        <span class="stat-val"><?= $r['disponibles'] ?> <small style="font-weight:400;color:#999">(<?= pct($r['disponibles'],$r['total']) ?>%)</small></span>
      </div>

      <div class="stat-row" style="margin-top:6px">
        <span class="stat-label">Surface totale</span>
        <span class="stat-val blue"><?= fmtNum($r['surface_totale']) ?> m²</span>
      </div>
      <div class="stat-row">
        <span class="stat-label">Surface vendue</span>
        <span class="stat-val green"><?= fmtNum($r['surface_vendue']) ?> m² <small style="font-weight:400;color:#999">(<?= pct($r['surface_vendue'],$r['surface_totale']) ?>%)</small></span>
      </div>
      <?php if ($r['valeur_catalogue'] > 0): ?>
      <div class="stat-row">
        <span class="stat-label">Valeur catalogue</span>
        <span class="stat-val blue"><?= fmtNum($r['valeur_catalogue']) ?> TND</span>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <?php if ($pktot > 0): ?>
      <div class="stat-row" style="margin-top:8px;border-top:1px dashed #f0f0f0;padding-top:10px">
        <span class="stat-label">🅿 Parking total</span>
        <span class="stat-val"><?= $pktot ?></span>
      </div>
      <div class="stat-row">
        <span class="stat-label">Vendus / Réservés / Dispo</span>
        <span class="stat-val">
          <span style="color:#166534"><?= $pkv ?></span> /
          <span style="color:#92400e"><?= $pkr ?></span> /
          <?= $pkd ?>
        </span>
      </div>
      <div style="padding:2px 0 4px"><?= pctBar(pct($pkv,$pktot),'#166534') ?></div>
      <?php endif; ?>

      <?php if ($cetot > 0): ?>
      <div class="stat-row" style="margin-top:8px;border-top:1px dashed #f0f0f0;padding-top:10px">
        <span class="stat-label">📦 Cellier total</span>
        <span class="stat-val"><?= $cetot ?></span>
      </div>
      <div class="stat-row">
        <span class="stat-label">Vendus / Réservés / Dispo</span>
        <span class="stat-val">
          <span style="color:#166534"><?= $cev ?></span> /
          <span style="color:#92400e"><?= $cer ?></span> /
          <?= $ced ?>
        </span>
      </div>
      <div style="padding:2px 0 4px"><?= pctBar(pct($cev,$cetot),'#166534') ?></div>
      <?php endif; ?>

    </div>
  </div>
  <?php endforeach; ?>
  </div>

  <!-- ═══ TABLEAU FINANCIER ════════════════════════════════════════════ -->
  <?php if (!empty($finRows)): ?>
  <div class="sec-title" style="margin-top:4px">💰 Suivi financier des contrats</div>

  <div class="kpi-grid" style="margin-bottom:20px">
    <div class="kpi">
      <div class="kpi-val"><?= count($finRows) ?></div>
      <div class="kpi-lbl">Contrats actifs</div>
    </div>
    <div class="kpi accent-blue">
      <div class="kpi-val"><?= fmtNum($totContrats) ?></div>
      <div class="kpi-lbl">Total contrats TND</div>
    </div>
    <div class="kpi accent-green">
      <div class="kpi-val"><?= fmtNum($totEncaisse) ?></div>
      <div class="kpi-lbl">Encaissé TND</div>
      <div class="kpi-sub"><?= pct($totEncaisse,$totContrats) ?>%</div>
    </div>
    <div class="kpi accent-red">
      <div class="kpi-val"><?= fmtNum($totRestant) ?></div>
      <div class="kpi-lbl">Reste à encaisser</div>
      <div class="kpi-sub"><?= pct($totRestant,$totContrats) ?>%</div>
    </div>
  </div>

  <div class="fin-table-wrap">
    <table class="fin-table">
      <thead>
        <tr>
          <th>N° Promesse</th><th>Client</th><th>Unité</th><th>Projet</th>
          <th style="text-align:right">Total TND</th>
          <th style="text-align:right">Encaissé</th>
          <th style="text-align:right">Restant</th>
          <th>Avancement</th><th>Statut</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($finRows as $f):
        $pctEnc = (float)($f['progress_percent'] ?? 0);
        $sLabel = [
          'pending'          => ['pill-gray',  'En attente'],
          'in_progress'      => ['pill-blue',  'En cours'],
          'paid'             => ['pill-green', 'Soldé'],
          'overdue'          => ['pill-red',   'En retard'],
        ][$f['sched_status'] ?? ''] ?? ['pill-gray','—'];
      ?>
        <tr>
          <td style="font-weight:700;color:var(--brand)"><?= htmlspecialchars($f['promise_number']) ?></td>
          <td><?= htmlspecialchars($f['client_nom'] ?? '—') ?></td>
          <td style="font-weight:600"><?= htmlspecialchars($f['unit_code']) ?></td>
          <td><span class="pill pill-blue"><?= htmlspecialchars($f['proj_code']) ?></span></td>
          <td style="text-align:right"><?= fmtNum($f['total_amount']) ?></td>
          <td style="text-align:right;color:#166534;font-weight:700"><?= fmtNum($f['paid_amount']) ?></td>
          <td style="text-align:right;color:#b3261e;font-weight:700"><?= fmtNum($f['remaining_amount']) ?></td>
          <td style="min-width:110px">
            <div style="font-size:11px;color:#666;margin-bottom:3px"><?= $pctEnc ?>%</div>
            <?= pctBar($pctEnc, $pctEnc >= 100 ? '#166534' : '#145f7d') ?>
          </td>
          <td><span class="pill <?= $sLabel[0] ?>"><?= $sLabel[1] ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" style="color:#999;font-size:12px">TOTAL</td>
          <td style="text-align:right"><?= fmtNum($totContrats) ?> TND</td>
          <td style="text-align:right;color:#166534"><?= fmtNum($totEncaisse) ?> TND</td>
          <td style="text-align:right;color:#b3261e"><?= fmtNum($totRestant) ?> TND</td>
          <td colspan="2">
            <?= pctBar(pct($totEncaisse,$totContrats),'#145f7d') ?>
            <div style="font-size:11px;color:#666;margin-top:3px"><?= pct($totEncaisse,$totContrats) ?>% encaissé</div>
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php else: ?>
  <div style="text-align:center;color:#bbb;font-size:14px;padding:40px 0;border:1px dashed #e6e6e6;border-radius:16px">
    Aucun contrat signé pour le moment.
  </div>
  <?php endif; ?>

  <div class="footer-row">
    <div style="display:flex;gap:20px">
      <a href="admin_users.php" style="color:#145f7d;text-decoration:none">← Utilisateurs</a>
      <a href="select_project.php" style="color:#555;text-decoration:none">Sélection projet</a>
    </div>
    <a href="logout.php" style="color:#b3261e;text-decoration:none">Se déconnecter</a>
  </div>

</div>
</body>
</html>
