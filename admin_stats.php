<?php
/* ══════════════════════════════════════════════════════════════════════
   Tableau de bord direction (statistiques admin)
   – Lecture seule : cette page ne modifie jamais la base.
   – Vente (AVA, FLAVA, EVOLO), Location (tous les projets en location),
     finances, activité de la période et activité par commercial.
   ══════════════════════════════════════════════════════════════════════ */
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/price_lib.php';
require_once __DIR__ . '/includes/locaux_lib.php';
require_once __DIR__ . '/includes/nav_lib.php';
if (empty($_SESSION['user_id']))           { header('Location: login.php');          exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: select_project.php'); exit; }

/* ── Petits outils ──────────────────────────────────────────────────── */
$dataErrors = [];
function q(PDO $pdo, string $sql, array $p = []): array {
    global $dataErrors;
    try { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { $dataErrors[] = $e->getMessage(); return []; }
}
function q1(PDO $pdo, string $sql, array $p = []) {
    $r = q($pdo, $sql, $p);
    return $r ? reset($r[0]) : null;
}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function n0($v): string { return number_format((float)$v, 0, ',', ' '); }
function n3($v): string { return number_format((float)$v, 3, ',', ' '); }
function pc($a, $b): float { return $b > 0 ? round($a / $b * 100, 1) : 0.0; }
function money($v): string {   // montants en DT : 1 234 567 ou 1,23 M
    $v = (float)$v;
    if (abs($v) >= 1000000) return number_format($v / 1000000, 2, ',', ' ') . ' M';
    return number_format($v, 0, ',', ' ');
}

/* ── Période ─────────────────────────────────────────────────────────── */
$periods = ['mois' => 'Ce mois', 'trimestre' => 'Ce trimestre', 'annee' => 'Cette année', 'tout' => 'Depuis le début'];
$p = $_GET['p'] ?? 'mois';
if (!isset($periods[$p])) $p = 'mois';
$today = new DateTimeImmutable('today');
switch ($p) {
    case 'mois':      $from = $today->modify('first day of this month'); break;
    case 'trimestre': $qm = intdiv((int)$today->format('n') - 1, 3) * 3 + 1;
                      $from = $today->setDate((int)$today->format('Y'), $qm, 1); break;
    case 'annee':     $from = $today->setDate((int)$today->format('Y'), 1, 1); break;
    default:          $from = new DateTimeImmutable('2000-01-01');
}
$fromSql = $from->format('Y-m-d');
$periodLbl = $p === 'tout' ? 'depuis le début' : 'du ' . $from->format('d/m/Y') . ' au ' . $today->format('d/m/Y');

/* ══ 1. VENTE : stock par projet ════════════════════════════════════════ */
$saleProjects = ['AVA', 'FLAVA', 'EVOLO'];
$sale = [];
foreach ($saleProjects as $code) {
    $sale[$code] = ['total' => 0, 'V' => 0, 'R' => 0, 'L' => 0, 'D' => 0, 'surf' => 0, 'surfV' => 0,
                    'stock' => 0, 'stockM2' => 0, 'stockSurf' => 0, 'types' => []];
}
$prices = [];
foreach (['AVA', 'FLAVA'] as $c) { try { $prices[$c] = project_month_prices($pdo, $c); } catch (Throwable $e) { $prices[$c] = null; } }

$rows = q($pdo, "SELECT p.code AS proj, u.ref_code, u.type, u.designation, u.surface, u.status_code, u.operation_mode,
                        u.price_m2_ht_sale, u.price_ht_sale, u.price_ttc_sale
                 FROM units u JOIN projects p ON p.id = u.project_id
                 WHERE u.operation_mode = 'sale' AND UPPER(p.code) IN ('AVA','FLAVA','EVOLO')");
foreach ($rows as $u) {
    $c  = strtoupper($u['proj']);
    $st = strtoupper(trim((string)$u['status_code']));
    $st = in_array($st, ['V', 'R', 'L'], true) ? $st : 'D';
    $s  = &$sale[$c];
    $s['total']++; $s[$st]++;
    $s['surf'] += (float)$u['surface'];
    if ($st === 'V') $s['surfV'] += (float)$u['surface'];
    $typ = trim((string)$u['type']) ?: 'Autre';
    if (strtoupper((string)$u['designation']) === 'COMMERCE') $typ = 'Commerce';
    elseif (in_array(strtoupper((string)$u['designation']), ['DUPLEX', 'PENTHOUSE', 'LOFT'], true)) $typ = ucfirst(strtolower($u['designation']));
    $s['types'][$typ] = ($s['types'][$typ] ?? ['total' => 0, 'V' => 0, 'R' => 0, 'L' => 0, 'D' => 0]);
    $s['types'][$typ]['total']++; $s['types'][$typ][$st]++;
    if ($st === 'D' || $st === 'R') {   // stock à vendre, au prix de la liste du mois
        $pr = ($prices[$c] ?? null) ? project_row_price($prices[$c], $u, false) : price_db($u);
        $s['stock'] += (float)$pr['price_ttc'];
        $s['stockSurf'] += (float)$u['surface'];
    }
    unset($s);
}
foreach ($sale as &$s) { $s['stockM2'] = $s['stockSurf'] > 0 ? $s['stock'] / $s['stockSurf'] : 0; ksort($s['types'], SORT_NATURAL); }
unset($s);

/* Accessoires vendus (parkings, celliers) */
$acc = [];
foreach (q($pdo, "SELECT p.code AS proj, UPPER(a.accessory_type) AS t, UPPER(TRIM(COALESCE(a.status_code,''))) AS st, COUNT(*) AS nb
                  FROM unit_accessoires a JOIN projects p ON p.id = a.project_id
                  WHERE a.operation_mode = 'sale' AND UPPER(p.code) IN ('AVA','FLAVA','EVOLO')
                    AND a.code <> 'Place de parking'
                  GROUP BY p.code, UPPER(a.accessory_type), UPPER(TRIM(COALESCE(a.status_code,'')))") as $r) {
    $st = in_array($r['st'], ['V', 'R', 'L'], true) ? $r['st'] : 'D';
    $k = strtoupper($r['proj']) . '|' . $r['t'];
    $acc[$k] = $acc[$k] ?? ['total' => 0, 'V' => 0, 'R' => 0, 'L' => 0, 'D' => 0];
    $acc[$k]['total'] += $r['nb']; $acc[$k][$st] += $r['nb'];
}

/* ══ 2. CONTRATS (promesses signées / converties) ═══════════════════════ */
$contracts = q($pdo, "SELECT pr.id, pr.promise_number, pr.unit_code, pr.status, pr.signed_date, pr.created_by,
           COALESCE(NULLIF(TRIM(CONCAT(COALESCE(cl.first_name,''),' ',COALESCE(cl.last_name,''))),''), cl.nom, '—') AS client,
           COALESCE(p.code, '—') AS proj,
           COALESCE(ps.total_amount, pr.sale_price, 0) AS total,
           COALESCE(ps.paid_amount, 0) AS paid,
           COALESCE(ps.remaining_amount, COALESCE(ps.total_amount, pr.sale_price, 0) - COALESCE(ps.paid_amount, 0)) AS remaining
    FROM promises pr
    LEFT JOIN clients cl ON cl.id = pr.client_id
    LEFT JOIN projects p ON p.id = pr.project_id
    LEFT JOIN payment_schedules ps ON ps.promise_id = pr.id
    WHERE pr.status IN ('signed','converted_to_sale')
    ORDER BY remaining DESC");
$fin = ['total' => 0, 'paid' => 0, 'remaining' => 0, 'byProj' => []];
foreach ($contracts as $c) {
    $fin['total'] += $c['total']; $fin['paid'] += $c['paid']; $fin['remaining'] += $c['remaining'];
    $k = $c['proj'];
    $fin['byProj'][$k] = $fin['byProj'][$k] ?? ['nb' => 0, 'total' => 0, 'paid' => 0, 'remaining' => 0];
    $fin['byProj'][$k]['nb']++; $fin['byProj'][$k]['total'] += $c['total'];
    $fin['byProj'][$k]['paid'] += $c['paid']; $fin['byProj'][$k]['remaining'] += $c['remaining'];
}
$noProject = $fin['byProj']['—']['nb'] ?? 0;

/* Échéances en retard */
$overdue = q($pdo, "SELECT pi.due_date, pi.label, (pi.expected_amount - pi.paid_amount) AS reste,
           ps.unit_code, pr.promise_number, pr.id AS promise_id, COALESCE(p.code,'—') AS proj,
           COALESCE(NULLIF(TRIM(CONCAT(COALESCE(cl.first_name,''),' ',COALESCE(cl.last_name,''))),''), cl.nom, '—') AS client
    FROM payment_installments pi
    JOIN payment_schedules ps ON ps.id = pi.schedule_id
    LEFT JOIN promises pr ON pr.id = ps.promise_id
    LEFT JOIN projects p ON p.id = pr.project_id
    LEFT JOIN clients cl ON cl.id = ps.client_id
    WHERE pi.status IN ('pending','partially_paid','overdue') AND pi.due_date < CURDATE()
      AND pi.expected_amount > pi.paid_amount
      AND (pr.status IS NULL OR pr.status <> 'cancelled')
    ORDER BY pi.due_date ASC");
$overdueAmount = array_sum(array_map(fn($r) => (float)$r['reste'], $overdue));

/* Réservations de vente en cours (lecture seule : rien n'est libéré ici) */
$resa = q($pdo, "SELECT p.code AS proj, u.ref_code AS code, u.reserved_at,
           DATEDIFF(CURDATE(), DATE(u.reserved_at)) AS jours,
           (SELECT COUNT(*) FROM promises pr WHERE pr.project_id = u.project_id AND UPPER(pr.unit_code) = UPPER(u.ref_code)
              AND pr.status IN ('draft','signed')) AS promesses
    FROM units u JOIN projects p ON p.id = u.project_id
    WHERE u.operation_mode = 'sale' AND u.status_code = 'R'
    ORDER BY u.reserved_at IS NULL, u.reserved_at ASC");

/* ══ 3. ACTIVITÉ DE LA PÉRIODE ══════════════════════════════════════════ */
$act = [
    'prom_new'  => (int)q1($pdo, "SELECT COUNT(*) FROM promises WHERE DATE(created_at) >= ?", [$fromSql]),
    'prom_sign' => (int)q1($pdo, "SELECT COUNT(*) FROM promises WHERE status IN ('signed','converted_to_sale') AND signed_date >= ?", [$fromSql]),
    'ca_sign'   => (float)q1($pdo, "SELECT COALESCE(SUM(COALESCE(ps.total_amount, pr.sale_price, 0)),0) FROM promises pr
                                     LEFT JOIN payment_schedules ps ON ps.promise_id = pr.id
                                     WHERE pr.status IN ('signed','converted_to_sale') AND pr.signed_date >= ?", [$fromSql]),
    'pay_sum'   => (float)q1($pdo, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date >= ?", [$fromSql]),
    'pay_nb'    => (int)q1($pdo, "SELECT COUNT(*) FROM payments WHERE payment_date >= ?", [$fromSql]),
    'loc_resa'  => (int)q1($pdo, "SELECT COUNT(*) FROM location_dossiers WHERE DATE(created_at) >= ? AND unit_type = 'unit'", [$fromSql]),
    'loc_conf'  => (int)q1($pdo, "SELECT COUNT(*) FROM location_dossiers WHERE statut = 'loue' AND DATE(date_confirmation) >= ? AND unit_type = 'unit'", [$fromSql]),
];

/* Évolution sur 12 mois : contrats signés et encaissements */
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $m = $today->modify('first day of this month')->modify("-$i month");
    $months[$m->format('Y-m')] = ['lbl' => $m->format('m/y'), 'sign' => 0, 'ca' => 0, 'pay' => 0];
}
$from12 = array_key_first($months) . '-01';
foreach (q($pdo, "SELECT DATE_FORMAT(pr.signed_date,'%Y-%m') AS ym, COUNT(*) AS nb, COALESCE(SUM(COALESCE(ps.total_amount, pr.sale_price, 0)),0) AS ca
                  FROM promises pr LEFT JOIN payment_schedules ps ON ps.promise_id = pr.id
                  WHERE pr.status IN ('signed','converted_to_sale') AND pr.signed_date >= ? GROUP BY ym", [$from12]) as $r) {
    if (isset($months[$r['ym']])) { $months[$r['ym']]['sign'] = (int)$r['nb']; $months[$r['ym']]['ca'] = (float)$r['ca']; }
}
foreach (q($pdo, "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS ym, SUM(amount) AS s FROM payments WHERE payment_date >= ? GROUP BY ym", [$from12]) as $r) {
    if (isset($months[$r['ym']])) $months[$r['ym']]['pay'] = (float)$r['s'];
}
$maxCa  = max(1, max(array_column($months, 'ca')));
$maxPay = max(1, max(array_column($months, 'pay')));

/* ══ 4. PAR COMMERCIAL (période) ════════════════════════════════════════ */
$users = [];
foreach (q($pdo, "SELECT id, COALESCE(NULLIF(full_name,''), username) AS nom, role, is_active FROM users") as $u) {
    $users[(int)$u['id']] = $u + ['prom' => 0, 'sign' => 0, 'ca' => 0, 'pay' => 0, 'loc' => 0, 'locL' => 0];
}
$addU = function (array $rows, string $field, string $val = 'v') use (&$users) {
    foreach ($rows as $r) { $id = (int)$r['uid']; if (isset($users[$id])) $users[$id][$field] += (float)$r[$val]; }
};
$addU(q($pdo, "SELECT created_by AS uid, COUNT(*) AS v FROM promises WHERE DATE(created_at) >= ? GROUP BY created_by", [$fromSql]), 'prom');
$addU(q($pdo, "SELECT pr.created_by AS uid, COUNT(*) AS v, COALESCE(SUM(COALESCE(ps.total_amount, pr.sale_price, 0)),0) AS ca
               FROM promises pr LEFT JOIN payment_schedules ps ON ps.promise_id = pr.id
               WHERE pr.status IN ('signed','converted_to_sale') AND pr.signed_date >= ? GROUP BY pr.created_by", [$fromSql]), 'sign');
$addU(q($pdo, "SELECT pr.created_by AS uid, COALESCE(SUM(COALESCE(ps.total_amount, pr.sale_price, 0)),0) AS v
               FROM promises pr LEFT JOIN payment_schedules ps ON ps.promise_id = pr.id
               WHERE pr.status IN ('signed','converted_to_sale') AND pr.signed_date >= ? GROUP BY pr.created_by", [$fromSql]), 'ca');
$addU(q($pdo, "SELECT created_by AS uid, COALESCE(SUM(amount),0) AS v FROM payments WHERE payment_date >= ? GROUP BY created_by", [$fromSql]), 'pay');
$addU(q($pdo, "SELECT created_by AS uid, COUNT(*) AS v FROM location_dossiers WHERE DATE(created_at) >= ? AND unit_type = 'unit' GROUP BY created_by", [$fromSql]), 'loc');
$addU(q($pdo, "SELECT created_by AS uid, COUNT(*) AS v FROM location_dossiers WHERE statut = 'loue' AND DATE(date_confirmation) >= ? AND unit_type = 'unit' GROUP BY created_by", [$fromSql]), 'locL');
$team = array_values(array_filter($users, fn($u) => $u['prom'] + $u['sign'] + $u['pay'] + $u['loc'] + $u['locL'] > 0));
usort($team, fn($a, $b) => [$b['ca'], $b['sign'], $b['prom']] <=> [$a['ca'], $a['sign'], $a['prom']]);

/* ══ 5. LOCATION ════════════════════════════════════════════════════════ */
const STAT_LOC_HAUSSE = 0.05;   // +5 % par an, seulement pendant la location
function stat_loyer_en_cours($base, ?string $depuis): float {
    $m = (float)$base;
    if (!$depuis || $m <= 0) return $m;
    $d0 = new DateTime(substr($depuis, 0, 10)); $now = new DateTime('today');
    $n = $d0 > $now ? 0 : (int)$d0->diff($now)->y;
    for ($i = 0; $i < $n; $i++) $m = round($m * (1 + STAT_LOC_HAUSSE), 3);
    return $m;
}
$loueDepuis = ['unit' => [], 'accessoire' => []];
foreach (q($pdo, "SELECT unit_id, unit_type, date_confirmation FROM location_dossiers WHERE statut = 'loue' AND date_confirmation IS NOT NULL") as $r) {
    $loueDepuis[$r['unit_type'] === 'accessoire' ? 'accessoire' : 'unit'][(int)$r['unit_id']] = $r['date_confirmation'];
}
$locUnits = q($pdo, "SELECT u.id, p.code AS proj, u.ref_code, u.surface, u.total_surface, u.status_code, u.price_month_ht_rent
                     FROM units u JOIN projects p ON p.id = u.project_id
                     WHERE u.operation_mode = 'location' ORDER BY p.code, u.id");
$loc = [];
foreach ($locUnits as $u) {   // regrouper les parties d'un même local
    $c = strtoupper($u['proj']);
    $g = local_group_of($c, (string)$u['ref_code']);
    $key = $g ? 'g' . $g['idx'] : 'u' . $u['id'];
    $loc[$c]['items'][$key][] = $u;
}
$locTot = ['total' => 0, 'D' => 0, 'R' => 0, 'L' => 0, 'surf' => 0, 'surfL' => 0, 'loyer' => 0, 'potentiel' => 0];
foreach ($loc as $c => &$L) {
    $L += ['total' => 0, 'D' => 0, 'R' => 0, 'L' => 0, 'surf' => 0, 'surfL' => 0, 'loyer' => 0, 'potentiel' => 0, 'sansDate' => 0];
    foreach ($L['items'] as $parts) {
        $lead = null;
        foreach ($parts as $pt) if ($pt['total_surface'] !== null) { $lead = $pt; break; }
        $lead = $lead ?? $parts[0];
        $st = strtoupper(trim((string)$lead['status_code']));
        $st = in_array($st, ['R', 'L'], true) ? $st : 'D';
        $surf = $lead['total_surface'] !== null ? (float)$lead['total_surface'] : array_sum(array_map(fn($x) => (float)$x['surface'], $parts));
        $L['total']++; $L[$st]++; $L['surf'] += $surf;
        if ($st === 'L') {
            $dep = null;
            foreach ($parts as $pt) if (isset($loueDepuis['unit'][(int)$pt['id']])) { $dep = $loueDepuis['unit'][(int)$pt['id']]; break; }
            if (!$dep) $L['sansDate']++;
            $L['loyer'] += stat_loyer_en_cours($lead['price_month_ht_rent'], $dep);
            $L['surfL'] += $surf;
        } else {
            $L['potentiel'] += (float)$lead['price_month_ht_rent'];
        }
    }
    foreach (['total', 'D', 'R', 'L', 'surf', 'surfL', 'loyer', 'potentiel'] as $k) $locTot[$k] += $L[$k];
}
unset($L);
ksort($loc);
$locPk = [];
foreach (q($pdo, "SELECT a.id, p.code AS proj, a.status_code, a.price_month_ht_rent FROM unit_accessoires a JOIN projects p ON p.id = a.project_id
                  WHERE a.operation_mode = 'location' AND a.accessory_type = 'PARKING' AND a.code <> 'Place de parking'") as $a) {
    $c = strtoupper($a['proj']);
    $st = in_array(strtoupper((string)$a['status_code']), ['R', 'L'], true) ? strtoupper($a['status_code']) : 'D';
    $locPk[$c] = $locPk[$c] ?? ['total' => 0, 'D' => 0, 'R' => 0, 'L' => 0, 'loyer' => 0];
    $locPk[$c]['total']++; $locPk[$c][$st]++;
    if ($st === 'L') $locPk[$c]['loyer'] += stat_loyer_en_cours($a['price_month_ht_rent'], $loueDepuis['accessoire'][(int)$a['id']] ?? null);
}
$pkLoyer = array_sum(array_column($locPk, 'loyer'));

/* ══ Totaux vente ══════════════════════════════════════════════════════ */
$T = ['total' => 0, 'V' => 0, 'R' => 0, 'L' => 0, 'D' => 0, 'stock' => 0];
foreach ($sale as $s) foreach ($T as $k => $_) $T[$k] += $s[$k];

$statusLbl = ['V' => 'Vendus', 'R' => 'Réservés', 'L' => 'Loués', 'D' => 'Disponibles'];
$adminName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
$promedLogo = 'assets/images/PromedGroup.png';
$stackBar = function (array $s, array $keys) : string {
    $tot = max(1, array_sum(array_map(fn($k) => $s[$k], $keys)));
    $out = '<div class="stack">';
    foreach ($keys as $k) if ($s[$k] > 0) $out .= '<span class="seg seg-' . $k . '" style="width:' . round($s[$k] / $tot * 100, 2) . '%" title="' . $s[$k] . '"></span>';
    return $out . '</div>';
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tableau de bord — PROMED</title>
<style>
:root{
  --brand:#145f7d;--ink:#111;--muted:#888;--line:#e6e6e6;--bg:#ffffff;--card:#fff;
  /* mêmes couleurs de statut que la page des prix */
  --V:#dc2626;--R:#ca8a04;--L:#1d4ed8;--D:#16a34a;--ok:#16a34a;--red:#b3261e;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#fff;color:var(--ink);font-size:14px;padding:40px 20px}
.shell{width:100%;max-width:1240px;margin:0 auto;background:#fff;border-radius:28px;padding:36px 40px 32px;border:1px solid #f0f0f0;box-shadow:0 18px 40px rgba(0,0,0,.06)}
.header{text-align:center;margin-bottom:26px}
.logo-main{display:inline-flex;align-items:center;justify-content:center;width:220px;height:100px;margin-bottom:12px}
.logo-main img{max-width:100%;max-height:90px;display:block}
.title{font-size:22px;font-weight:600;letter-spacing:.4px;color:#111}
.subtitle{margin-top:6px;font-size:14px;color:#888}
.footer-row{margin-top:28px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;color:#999}
.footer-row a{color:#b3261e;text-decoration:none}
.footer-row .links{display:flex;gap:20px;flex-wrap:wrap;align-items:center}
.footer-row .links a{color:#555;font-size:13px}
.footer-row .links a:hover{color:#111}
a{color:var(--brand)}
/* Barre du haut */
.topbar{background:var(--brand-2);color:#fff;position:sticky;top:0;z-index:5}
.topbar .in{max-width:1320px;margin:0 auto;padding:12px 24px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.topbar .logo{font-weight:800;letter-spacing:.12em}
.topbar .ttl{font-size:13px;opacity:.8}
.topbar nav{margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.topbar nav a{color:#fff;text-decoration:none;font-size:13px;padding:6px 10px;border-radius:8px;opacity:.9}
.topbar nav a:hover{background:rgba(255,255,255,.12);opacity:1}
.topbar nav .who{font-size:12px;opacity:.7;margin-left:6px}
.wrap{}
/* En-tête de page */
.head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.head h1{font-size:16px;font-weight:700;color:#222}
.head .sub{color:var(--muted);font-size:13px;margin-top:4px}
.seg-ctl{display:inline-flex;flex-wrap:wrap;gap:4px;background:#f4f4f4;border-radius:999px;padding:4px}
.seg-ctl a{padding:7px 14px;border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;color:#555}
.seg-ctl a.on{background:#111;color:#fff}
.btn{border:1px solid #ddd;background:transparent;color:#555;border-radius:999px;padding:8px 18px;font:inherit;font-size:13px;font-weight:600;cursor:pointer}
.btn:hover{background:#f4f4f4}
/* Sections */
.sec{margin-top:26px}
.sec-h{display:flex;align-items:baseline;gap:10px;margin-bottom:12px}
.sec-h h2{font-size:16px;font-weight:700;color:#222}
.sec-h .hint{color:var(--muted);font-size:12px}
.grid{display:grid;gap:14px}
.g-kpi{grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}
.g-2{grid-template-columns:repeat(auto-fit,minmax(min(420px,100%),1fr))}
.g-3{grid-template-columns:repeat(auto-fit,minmax(min(320px,100%),1fr))}
.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:16px 18px}
.kpi .lbl{font-size:11px;font-weight:700;color:#999;text-transform:uppercase;letter-spacing:.06em}
.kpi .val{font-size:26px;font-weight:800;margin-top:6px;line-height:1.1}
.kpi .val small{font-size:13px;font-weight:600;color:var(--muted)}
.kpi .foot{font-size:12px;color:var(--muted);margin-top:4px}
.kpi.v .val{color:var(--V)}.kpi.r .val{color:var(--R)}.kpi.l .val{color:var(--L)}.kpi.red .val{color:var(--red)}.kpi.ok .val{color:var(--ok)}.kpi.d .val{color:var(--D)}.kpi.b .val{color:var(--brand)}
/* Alertes */
.alerts{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(min(300px,100%),1fr));margin-bottom:6px}
.alert{border-radius:12px;font-weight:500;padding:12px 14px;font-size:13px;border:1px solid}
.alert.red{background:#fff0f0;border-color:#fecaca;color:#b3261e}
.alert.amber{background:#fffbf0;border-color:#fde68a;color:#92400e}
.alert.ok{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
.alert b{font-size:15px}
/* Barres */
.stack{display:flex;height:10px;border-radius:6px;overflow:hidden;background:#f0f0f0}
.seg{display:block;height:100%}
.seg-V{background:var(--V)}.seg-R{background:var(--R)}.seg-L{background:var(--L)}.seg-D{background:var(--D)}
.legend{display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--muted);margin-top:8px}
.legend i{display:inline-block;width:9px;height:9px;border-radius:3px;margin-right:5px;vertical-align:-1px}
.pbar{height:6px;background:#eef2f6;border-radius:4px;overflow:hidden;min-width:80px}.pbar span{display:block;height:100%;background:var(--brand)}
/* Carte projet */
.proj .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.proj .name{font-size:17px;font-weight:800}
.pill{display:inline-block;font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;background:#e0f2fe;color:#0369a1}
.kv{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:12px 0}
.kv>div{background:#fafafa;border:1px solid #f0f0f0;border-radius:14px;padding:8px 10px}
.kv .k{font-size:11px;color:var(--muted)}.kv .v{font-size:16px;font-weight:800}
.kv .v.V{color:var(--V)}.kv .v.R{color:var(--R)}.kv .v.L{color:var(--L)}.kv .v.D{color:var(--D)}
.line{display:flex;justify-content:space-between;padding:6px 0;border-top:1px solid var(--line);font-size:13px}
.line span:first-child{color:var(--muted)}
.line b{font-weight:700}
/* Tables */
table{width:100%;border-collapse:collapse}
th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#999;text-align:left;padding:10px 14px;border-bottom:1px solid #f0f0f0;background:#fafafa;white-space:nowrap}
td{padding:11px 14px;border-bottom:1px solid #f4f4f4;font-size:13px;vertical-align:middle}
tr:hover td{background:#fdfdfd}
tr:last-child td{border-bottom:0}
td.r,th.r{text-align:right}
tfoot td{font-weight:700;background:#fafafa}
.tbl{padding:0;overflow:auto}
.tag{font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px}
.tag.ok{background:#f0fdf4;color:#166534}.tag.warn{background:#fef3c7;color:#92400e}.tag.bad{background:#fff0f0;color:#b3261e}.tag.inf{background:#e0f2fe;color:#0369a1}
.muted{color:var(--muted)}
/* Graphique 12 mois */
.chart{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:8px;align-items:end;height:170px;padding-top:10px}
.chart .col{display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end}
.chart .bars{display:flex;gap:3px;align-items:flex-end;height:130px}
.chart .b1,.chart .b2{width:12px;border-radius:4px 4px 0 0}
.chart .b1{background:var(--brand)}.chart .b2{background:#86d0a0}
.chart .m{font-size:11px;color:var(--muted)}
.empty{color:var(--muted);font-size:13px;padding:14px}
.note{font-size:12px;color:var(--muted);margin-top:8px}
details summary{cursor:pointer;color:var(--brand);font-size:13px;margin-top:8px}
.card{min-width:0}
@media (max-width:700px){body{padding:20px 12px}.shell{padding:24px 16px 20px;border-radius:20px}.chart{gap:2px}.chart .b1,.chart .b2{width:7px}.chart .m{font-size:9px}.g-2,.g-3{grid-template-columns:1fr}.kv{grid-template-columns:repeat(2,1fr)}.wrap{padding:16px}}
@media print{.no-print{display:none!important}body{padding:0}.shell{border:0;box-shadow:none;padding:0;max-width:none}.card{break-inside:avoid}}
</style>
</head>
<body>
<div class="shell">
  <header class="header">
    <div class="logo-main">
      <?php if (is_file(__DIR__ . '/' . $promedLogo)): ?>
        <img src="<?= h($promedLogo) ?>" alt="PROMED">
      <?php else: ?>
        <span style="font-weight:700;font-size:20px;color:#111">PROMED</span>
      <?php endif; ?>
    </div>
    <h1 class="title">Statistiques</h1>
    <p class="subtitle">Tableau de bord direction · ventes, paiements et location</p>
  </header>

<div class="wrap">
  <div class="head">
    <div>
      <h1>Vue d'ensemble</h1>
      <div class="sub">Mis à jour le <?= date('d/m/Y à H:i') ?> · activité <?= h($periodLbl) ?> · lecture seule, aucune donnée n'est modifiée</div>
    </div>
    <div class="no-print" style="display:flex;gap:8px;align-items:center">
      <div class="seg-ctl">
        <?php foreach ($periods as $k => $lbl): ?><a href="?p=<?= $k ?>" class="<?= $k === $p ? 'on' : '' ?>"><?= h($lbl) ?></a><?php endforeach; ?>
      </div>
      <button class="btn" onclick="window.print()">Imprimer</button>
    </div>
  </div>

  <!-- ── Alertes ─────────────────────────────────────────────── -->
  <?php $old = array_filter($resa, fn($r) => $r['jours'] !== null && (int)$r['jours'] > 7); ?>
  <div class="alerts">
    <?php if ($overdue): ?>
    <div class="alert red"><b><?= count($overdue) ?></b> échéance<?= count($overdue) > 1 ? 's' : '' ?> en retard · <b><?= n0($overdueAmount) ?> DT</b> à recouvrer <a href="#retards">voir</a></div>
    <?php else: ?>
    <div class="alert ok">Aucune échéance de paiement en retard.</div>
    <?php endif; ?>
    <div class="alert <?= $old ? 'amber' : 'ok' ?>"><b><?= count($resa) ?></b> réservation<?= count($resa) > 1 ? 's' : '' ?> de vente en cours<?= $old ? ' · <b>' . count($old) . '</b> de plus de 7 jours à vérifier' : '' ?> <a href="#reservations">voir</a></div>
    <?php if ($locTot['R']): ?>
    <div class="alert amber"><b><?= $locTot['R'] ?></b> local<?= $locTot['R'] > 1 ? 'aux' : '' ?> réservé<?= $locTot['R'] > 1 ? 's' : '' ?> en location, en attente de contrat</div>
    <?php endif; ?>
  </div>

  <!-- ── Synthèse ────────────────────────────────────────────── -->
  <div class="sec">
    <div class="sec-h"><h2>Synthèse</h2><span class="hint">tous projets</span></div>
    <div class="grid g-kpi">
      <div class="card kpi"><div class="lbl">Unités en vente</div><div class="val"><?= $T['total'] ?></div><div class="foot">AVA, FLAVA, EVOLO</div></div>
      <div class="card kpi v"><div class="lbl">Vendues</div><div class="val"><?= $T['V'] ?> <small><?= pc($T['V'], $T['total']) ?> %</small></div><div class="foot">taux de commercialisation</div></div>
      <div class="card kpi r"><div class="lbl">Réservées</div><div class="val"><?= $T['R'] ?> <small><?= pc($T['R'], $T['total']) ?> %</small></div></div>
      <div class="card kpi d"><div class="lbl">Disponibles</div><div class="val"><?= $T['D'] ?> <small><?= pc($T['D'], $T['total']) ?> %</small></div><div class="foot">stock <?= money($T['stock']) ?> DT TTC</div></div>
      <div class="card kpi b"><div class="lbl">Contrats signés</div><div class="val"><?= money($fin['total']) ?> <small>DT</small></div><div class="foot"><?= count($contracts) ?> promesse<?= count($contracts) > 1 ? 's' : '' ?></div></div>
      <div class="card kpi ok"><div class="lbl">Encaissé</div><div class="val"><?= money($fin['paid']) ?> <small>DT</small></div><div class="foot"><?= pc($fin['paid'], $fin['total']) ?> % des contrats</div></div>
      <div class="card kpi red"><div class="lbl">Reste à encaisser</div><div class="val"><?= money($fin['remaining']) ?> <small>DT</small></div><div class="foot">dont <?= money($overdueAmount) ?> en retard</div></div>
      <div class="card kpi l"><div class="lbl">Loyers en cours</div><div class="val"><?= money($locTot['loyer'] + $pkLoyer) ?> <small>DT/mois</small></div><div class="foot">occupation <?= pc($locTot['L'], $locTot['total']) ?> % des locaux</div></div>
    </div>
  </div>

  <!-- ── Activité de la période ──────────────────────────────── -->
  <div class="sec">
    <div class="sec-h"><h2>Activité <?= h(mb_strtolower($periods[$p])) ?></h2><span class="hint"><?= h($periodLbl) ?></span></div>
    <div class="grid g-kpi">
      <div class="card kpi"><div class="lbl">Promesses créées</div><div class="val"><?= $act['prom_new'] ?></div></div>
      <div class="card kpi b"><div class="lbl">Promesses signées</div><div class="val"><?= $act['prom_sign'] ?></div><div class="foot"><?= money($act['ca_sign']) ?> DT</div></div>
      <div class="card kpi b"><div class="lbl">Encaissements</div><div class="val"><?= money($act['pay_sum']) ?> <small>DT</small></div><div class="foot"><?= $act['pay_nb'] ?> paiement<?= $act['pay_nb'] > 1 ? 's' : '' ?></div></div>
      <div class="card kpi r"><div class="lbl">Réservations location</div><div class="val"><?= $act['loc_resa'] ?></div></div>
      <div class="card kpi l"><div class="lbl">Locations confirmées</div><div class="val"><?= $act['loc_conf'] ?></div></div>
    </div>
    <div class="grid g-2" style="margin-top:14px">
      <div class="card">
        <div class="sec-h" style="margin-bottom:4px"><h2 style="font-size:14px">12 derniers mois</h2><span class="hint">contrats signés et encaissements (DT)</span></div>
        <div class="chart">
          <?php foreach ($months as $m): ?>
          <div class="col" title="<?= h($m['lbl']) ?> · signés : <?= $m['sign'] ?> (<?= n0($m['ca']) ?> DT) · encaissé : <?= n0($m['pay']) ?> DT">
            <div class="bars"><span class="b1" style="height:<?= round($m['ca'] / $maxCa * 100) ?>%"></span><span class="b2" style="height:<?= round($m['pay'] / $maxPay * 100) ?>%"></span></div>
            <span class="m"><?= h($m['lbl']) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="legend"><span><i style="background:var(--brand)"></i>Montant signé</span><span><i style="background:#7cc4a4"></i>Encaissé</span><span>(échelles séparées · survolez une barre pour les montants)</span></div>
      </div>
      <div class="card tbl">
        <div style="padding:14px 16px 4px" class="sec-h"><h2 style="font-size:14px">Par commercial</h2><span class="hint"><?= h($periodLbl) ?></span></div>
        <?php if (!$team): ?><div class="empty">Aucune activité enregistrée sur la période.</div><?php else: ?>
        <table>
          <thead><tr><th>Commercial</th><th class="r">Promesses</th><th class="r">Signées</th><th class="r">Montant signé</th><th class="r">Encaissé</th><th class="r">Location</th></tr></thead>
          <tbody>
          <?php foreach ($team as $u): ?>
            <tr><td><b><?= h($u['nom']) ?></b> <span class="muted" style="font-size:11px"><?= h($u['role']) ?></span></td>
              <td class="r"><?= (int)$u['prom'] ?></td><td class="r"><?= (int)$u['sign'] ?></td><td class="r"><?= n0($u['ca']) ?></td>
              <td class="r"><?= n0($u['pay']) ?></td><td class="r"><?= (int)$u['loc'] ?> rés. · <?= (int)$u['locL'] ?> loué<?= $u['locL'] > 1 ? 's' : '' ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Vente par projet ────────────────────────────────────── -->
  <div class="sec">
    <div class="sec-h"><h2>Vente par projet</h2><span class="hint">stock au prix de la liste du mois</span></div>
    <div class="grid g-3">
      <?php foreach ($sale as $code => $s): $fp = $fin['byProj'][$code] ?? ['nb' => 0, 'total' => 0, 'paid' => 0, 'remaining' => 0]; ?>
      <div class="card proj">
        <div class="top"><span class="name"><?= h($code) ?></span><span class="pill"><?= $s['total'] ?> unités · <?= pc($s['V'], $s['total']) ?> % vendu</span></div>
        <?= $stackBar($s, ['V', 'R', 'L', 'D']) ?>
        <div class="kv">
          <div><div class="k">Vendus</div><div class="v V"><?= $s['V'] ?></div></div>
          <div><div class="k">Réservés</div><div class="v R"><?= $s['R'] ?></div></div>
          <div><div class="k">Loués</div><div class="v L"><?= $s['L'] ?></div></div>
          <div><div class="k">Disponibles</div><div class="v D"><?= $s['D'] ?></div></div>
        </div>
        <div class="line"><span>Surface vendue</span><b><?= n0($s['surfV']) ?> / <?= n0($s['surf']) ?> m² (<?= pc($s['surfV'], $s['surf']) ?> %)</b></div>
        <div class="line"><span>Stock à vendre (disponible + réservé)</span><b><?= money($s['stock']) ?> DT TTC</b></div>
        <div class="line"><span>Prix moyen du stock</span><b><?= n0($s['stockM2']) ?> DT TTC / m²</b></div>
        <div class="line"><span>Contrats signés</span><b><?= $fp['nb'] ?> · <?= money($fp['total']) ?> DT</b></div>
        <div class="line"><span>Encaissé / reste</span><b><span style="color:var(--ok)"><?= money($fp['paid']) ?></span> / <span style="color:var(--red)"><?= money($fp['remaining']) ?></span></b></div>
        <?php foreach (['PARKING' => 'Parkings', 'CELLIER' => 'Celliers'] as $t => $lbl): $a = $acc[$code . '|' . $t] ?? null; if (!$a) continue; ?>
        <div class="line"><span><?= $lbl ?></span><b><span style="color:var(--V)"><?= $a['V'] ?></span> vendus · <span style="color:var(--R)"><?= $a['R'] ?></span> rés. · <?= $a['D'] ?> dispo / <?= $a['total'] ?></b></div>
        <?php endforeach; ?>
        <?php if ($s['types']): ?>
        <details><summary>Détail par typologie</summary>
          <table style="margin-top:6px"><thead><tr><th>Type</th><th class="r">Total</th><th class="r">V</th><th class="r">R</th><th class="r">D</th></tr></thead><tbody>
          <?php foreach ($s['types'] as $t => $x): ?><tr><td><?= h($t) ?></td><td class="r"><?= $x['total'] ?></td><td class="r"><?= $x['V'] ?></td><td class="r"><?= $x['R'] ?></td><td class="r"><?= $x['D'] + $x['L'] ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </details>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="legend"><span><i style="background:var(--V)"></i>Vendu</span><span><i style="background:var(--R)"></i>Réservé</span><span><i style="background:var(--L)"></i>Loué</span><span><i style="background:var(--D)"></i>Disponible</span></div>
  </div>

  <!-- ── Location ────────────────────────────────────────────── -->
  <div class="sec">
    <div class="sec-h"><h2>Location</h2><span class="hint">un local en plusieurs parties compte pour un · loyers TTC avec la hausse de 5 % par an des biens loués</span></div>
    <div class="grid g-kpi" style="margin-bottom:14px">
      <div class="card kpi"><div class="lbl">Locaux</div><div class="val"><?= $locTot['total'] ?></div><div class="foot"><?= n0($locTot['surf']) ?> m²</div></div>
      <div class="card kpi l"><div class="lbl">Loués</div><div class="val"><?= $locTot['L'] ?> <small><?= pc($locTot['L'], $locTot['total']) ?> %</small></div><div class="foot"><?= n0($locTot['surfL']) ?> m² occupés</div></div>
      <div class="card kpi r"><div class="lbl">Réservés</div><div class="val"><?= $locTot['R'] ?></div></div>
      <div class="card kpi"><div class="lbl">Libres</div><div class="val"><?= $locTot['D'] ?></div><div class="foot">potentiel <?= money($locTot['potentiel']) ?> DT/mois</div></div>
      <div class="card kpi b"><div class="lbl">Loyers locaux</div><div class="val"><?= money($locTot['loyer']) ?> <small>DT/mois</small></div><div class="foot"><?= money($locTot['loyer'] * 12) ?> DT/an</div></div>
      <div class="card kpi b"><div class="lbl">Loyers parkings</div><div class="val"><?= money($pkLoyer) ?> <small>DT/mois</small></div><div class="foot"><?= array_sum(array_column($locPk, 'L')) ?> places louées</div></div>
    </div>
    <div class="card tbl">
      <?php if (!$loc): ?><div class="empty">Aucun bien en location.</div><?php else: ?>
      <table>
        <thead><tr><th>Projet</th><th>Occupation</th><th class="r">Locaux</th><th class="r">Loués</th><th class="r">Réservés</th><th class="r">Libres</th><th class="r">Loyer en cours / mois</th><th class="r">Potentiel libre / mois</th><th class="r">Parkings loués</th></tr></thead>
        <tbody>
        <?php foreach ($loc as $c => $L): $pk = $locPk[$c] ?? null; ?>
          <tr>
            <td><b><?= h($c) ?></b><?php if ($L['sansDate']): ?> <span class="tag warn" title="Biens loués sans date de location dans le CRM : pas de hausse calculée"><?= $L['sansDate'] ?> sans date</span><?php endif; ?></td>
            <td style="min-width:150px"><?= $stackBar($L, ['L', 'R', 'D']) ?><span class="muted" style="font-size:11px"><?= pc($L['L'], $L['total']) ?> % loué</span></td>
            <td class="r"><?= $L['total'] ?></td><td class="r" style="color:var(--L);font-weight:700"><?= $L['L'] ?></td>
            <td class="r" style="color:var(--R)"><?= $L['R'] ?></td><td class="r"><?= $L['D'] ?></td>
            <td class="r"><b><?= n3($L['loyer']) ?></b></td><td class="r muted"><?= n3($L['potentiel']) ?></td>
            <td class="r"><?= $pk ? $pk['L'] . ' / ' . $pk['total'] . ($pk['loyer'] ? ' · ' . n0($pk['loyer']) . ' DT' : '') : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total</td><td><?= pc($locTot['L'], $locTot['total']) ?> % loué</td><td class="r"><?= $locTot['total'] ?></td><td class="r"><?= $locTot['L'] ?></td><td class="r"><?= $locTot['R'] ?></td><td class="r"><?= $locTot['D'] ?></td><td class="r"><?= n3($locTot['loyer']) ?></td><td class="r"><?= n3($locTot['potentiel']) ?></td><td class="r"><?= n0($pkLoyer) ?> DT</td></tr></tfoot>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Finances ────────────────────────────────────────────── -->
  <div class="sec" id="retards">
    <div class="sec-h"><h2>Échéances en retard</h2><span class="hint">à relancer</span></div>
    <div class="card tbl">
      <?php if (!$overdue): ?><div class="empty">Aucune échéance en retard.</div><?php else: ?>
      <table>
        <thead><tr><th>Échéance</th><th>Retard</th><th>Client</th><th>Unité</th><th>Projet</th><th>Promesse</th><th class="r">Reste dû (DT)</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($overdue, 0, 15) as $o): $j = (int)(new DateTime($o['due_date']))->diff(new DateTime('today'))->days; ?>
          <tr><td><?= h(date('d/m/Y', strtotime($o['due_date']))) ?> <span class="muted"><?= h($o['label']) ?></span></td>
            <td><span class="tag <?= $j > 30 ? 'bad' : 'warn' ?>"><?= $j ?> j</span></td>
            <td><?= h($o['client']) ?></td><td><b><?= h($o['unit_code']) ?></b></td><td><?= h($o['proj']) ?></td>
            <td><?php if ($o['promise_id']): ?><a href="promise_view.php?id=<?= (int)$o['promise_id'] ?>"><?= h($o['promise_number']) ?></a><?php endif; ?></td>
            <td class="r" style="color:var(--red);font-weight:700"><?= n3($o['reste']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($overdue) > 15): ?><div class="note" style="padding:0 12px 12px">… et <?= count($overdue) - 15 ?> autre(s). <a href="paiements.php">Voir le suivi des paiements</a></div><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="sec">
    <div class="sec-h"><h2>Contrats signés</h2><span class="hint">promesses signées ou converties en vente · triées par reste à encaisser</span></div>
    <div class="card tbl">
      <?php if (!$contracts): ?><div class="empty">Aucun contrat signé.</div><?php else: ?>
      <table>
        <thead><tr><th>Promesse</th><th>Client</th><th>Unité</th><th>Projet</th><th class="r">Total (DT)</th><th class="r">Encaissé</th><th class="r">Reste</th><th>Avancement</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach ($contracts as $c): $pp = pc($c['paid'], $c['total']); ?>
          <tr><td><a href="promise_view.php?id=<?= (int)$c['id'] ?>"><b><?= h($c['promise_number']) ?></b></a></td>
            <td><?= h($c['client']) ?></td><td><b><?= h($c['unit_code']) ?></b></td><td><?= h($c['proj']) ?></td>
            <td class="r"><?= n0($c['total']) ?></td><td class="r" style="color:var(--ok)"><?= n0($c['paid']) ?></td><td class="r" style="color:var(--red)"><?= n0($c['remaining']) ?></td>
            <td style="min-width:110px"><div class="pbar"><span style="width:<?= min(100, $pp) ?>%"></span></div><span class="muted" style="font-size:11px"><?= $pp ?> %</span></td>
            <td><span class="tag <?= $c['status'] === 'converted_to_sale' ? 'ok' : 'inf' ?>"><?= $c['status'] === 'converted_to_sale' ? 'Vendu' : 'Signée' ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="4">Total · <?= count($contracts) ?> contrat<?= count($contracts) > 1 ? 's' : '' ?></td><td class="r"><?= n0($fin['total']) ?></td><td class="r"><?= n0($fin['paid']) ?></td><td class="r"><?= n0($fin['remaining']) ?></td><td colspan="2"><?= pc($fin['paid'], $fin['total']) ?> % encaissé</td></tr></tfoot>
      </table>
      <?php if ($noProject): ?><div class="note" style="padding:0 12px 12px">⚠ <?= $noProject ?> promesse(s) sans projet enregistré (anciennes fiches) : comptées dans le total mais pas dans un projet.</div><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="sec" id="reservations">
    <div class="sec-h"><h2>Réservations de vente en cours</h2><span class="hint">information seulement : cette page ne libère aucune réservation</span></div>
    <div class="card tbl">
      <?php if (!$resa): ?><div class="empty">Aucune réservation en cours.</div><?php else: ?>
      <table>
        <thead><tr><th>Projet</th><th>Unité</th><th>Réservée le</th><th>Depuis</th><th>Promesse</th><th>À faire</th></tr></thead>
        <tbody>
        <?php foreach ($resa as $r): $j = $r['jours'] === null ? null : (int)$r['jours']; ?>
          <tr><td><?= h($r['proj']) ?></td><td><b><?= h($r['code']) ?></b></td>
            <td><?= $r['reserved_at'] ? h(date('d/m/Y', strtotime($r['reserved_at']))) : '<span class="muted">date inconnue</span>' ?></td>
            <td><?= $j === null ? '—' : $j . ' j' ?></td>
            <td><?= (int)$r['promesses'] ? '<span class="tag ok">oui</span>' : '<span class="tag warn">non</span>' ?></td>
            <td><?php if ($j !== null && $j > 7 && !(int)$r['promesses']): ?><span class="tag bad">Relancer ou libérer</span><?php elseif ($j !== null && $j > 7): ?><span class="tag inf">Promesse en cours</span><?php else: ?><span class="muted">—</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($dataErrors): ?>
  <div class="note no-print" style="margin-top:20px">Certaines données n'ont pas pu être lues (<?= count(array_unique($dataErrors)) ?> requête(s)). Détail : <?= h(implode(' | ', array_slice(array_unique($dataErrors), 0, 3))) ?></div>
  <?php endif; ?>
  <div class="footer-row no-print">
    <div class="links">
      <a href="<?= h(nav_projects_url('vente')) ?>">← Sélection projet</a>
      <a href="<?= h(nav_projects_url('location')) ?>">Projets location</a>
      <a href="paiements.php">Paiements</a>
      <a href="promises.php">Promesses</a>
      <a href="admin_users.php">👥 Utilisateurs</a>
    </div>
    <span><?= h($adminName) ?> · <a href="logout.php">Se déconnecter</a></span>
  </div>
</div>
</div>
</body>
</html>
