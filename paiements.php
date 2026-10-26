<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

function tableExists(PDO $pdo, string $t): bool {
    try { $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?"); $s->execute([$t]); return (bool)$s->fetchColumn(); } catch(Throwable $e){ return false; }
}

$filter_promise   = (int)($_GET['promise_id'] ?? 0);
$filter_status    = trim($_GET['status'] ?? '');
$filter_unit_code = trim($_GET['unit_code'] ?? '');

// ── Charger les promesses avec schedule ──────────────────────────────────
$promises = [];
if (tableExists($pdo, 'promises') && tableExists($pdo, 'payment_schedules')) {
    $where = ['1=1'];
    $params = [];
    if ($filter_promise)   { $where[] = 'p.id = ?';                       $params[] = $filter_promise; }
    if ($filter_status)    { $where[] = 'p.status = ?';                   $params[] = $filter_status; }
    if ($filter_unit_code) { $where[] = 'UPPER(p.unit_code) = UPPER(?)';  $params[] = $filter_unit_code; }
    $where_sql = implode(' AND ', $where);
    try {
        // paid_amount calculé en live depuis payments (promise_id scopé),
        // jamais depuis client_id — évite tout mélange entre unités du même client.
        $stmt = $pdo->prepare("
            SELECT p.id, p.promise_number, p.status, p.unit_code, p.sale_price,
                   p.promise_date, p.signed_date, p.is_locked,
                   c.nom AS client_nom, c.email AS client_email, c.tel AS client_tel,
                   ps.id AS schedule_id, ps.total_amount, ps.status AS sched_status,
                   pay_live.paid_amount,
                   GREATEST(0, COALESCE(ps.total_amount, 0) - pay_live.paid_amount) AS remaining_amount,
                   CASE WHEN COALESCE(ps.total_amount, 0) > 0
                        THEN LEAST(100, ROUND(pay_live.paid_amount / ps.total_amount * 100, 2))
                        ELSE 0 END AS progress_percent
            FROM promises p
            JOIN clients c ON c.id = p.client_id
            LEFT JOIN payment_schedules ps ON ps.promise_id = p.id
            LEFT JOIN (
                SELECT promise_id,
                       COALESCE(SUM(COALESCE(amount, montant_paye, 0)), 0) AS paid_amount
                FROM payments
                WHERE status IS NULL OR status NOT IN ('cancelled','pending_review')
                GROUP BY promise_id
            ) pay_live ON pay_live.promise_id = p.id
            WHERE $where_sql
            ORDER BY p.created_at DESC
        ");
        $stmt->execute($params);
        $promises = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e) {}
}

// ── Charger les échéances par schedule ──────────────────────────────────
$installments_by_schedule = [];
if ($promises && tableExists($pdo, 'payment_installments')) {
    // Ensure sort_order column exists
    try { $pdo->exec("ALTER TABLE payment_installments ADD COLUMN sort_order INT NOT NULL DEFAULT 0"); } catch(Throwable $_) {}

    $schedule_ids = array_filter(array_column($promises, 'schedule_id'));
    if ($schedule_ids) {
        $ph = implode(',', array_fill(0, count($schedule_ids), '?'));
        try {
            // paid_amount calculé en live par (promise_id + installment_id) depuis payments,
            // jamais depuis la valeur stockée qui peut être stale.
            $stmt = $pdo->prepare("
                SELECT pi.*,
                    COALESCE((
                        SELECT SUM(COALESCE(pay.amount, pay.montant_paye, 0))
                        FROM payments pay
                        WHERE pay.promise_id    = pi.promise_id
                          AND pay.installment_id = pi.id
                          AND (pay.status IS NULL OR pay.status NOT IN ('cancelled','pending_review'))
                    ), 0) AS paid_amount_live
                FROM payment_installments pi
                WHERE pi.schedule_id IN ($ph)
                ORDER BY pi.sort_order ASC, pi.id ASC
            ");
            $stmt->execute($schedule_ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $inst) {
                // Utiliser paid_amount_live en priorité ; fallback sur paid_amount stocké si promise_id absent
                $inst['paid_amount']     = $inst['promise_id']
                    ? $inst['paid_amount_live']
                    : $inst['paid_amount'];
                $inst['remaining_amount'] = max(0, (float)$inst['expected_amount'] - (float)$inst['paid_amount']);
                $installments_by_schedule[$inst['schedule_id']][] = $inst;
            }
        } catch(Throwable $e) {}
    }
}

// ── Paiements libres (installment_id NULL) par promise_id ─────────────────
$free_payments_by_promise = [];
if ($promises) {
    $promise_ids = array_column($promises, 'id');
    if ($promise_ids) {
        $ph2 = implode(',', array_fill(0, count($promise_ids), '?'));
        try {
            $fp = $pdo->prepare("
                SELECT p.id, p.promise_id, p.amount, p.payment_date, p.payment_method,
                       p.reference_number, p.status, p.created_at,
                       u.full_name AS created_by_name
                FROM payments p
                LEFT JOIN users u ON u.id = p.created_by
                WHERE p.promise_id IN ($ph2)
                  AND (p.installment_id IS NULL OR p.installment_id = 0)
                  AND (p.status IS NULL OR p.status NOT IN ('cancelled','pending_review'))
                ORDER BY p.payment_date ASC, p.id ASC
            ");
            $fp->execute($promise_ids);
            foreach ($fp->fetchAll(PDO::FETCH_ASSOC) as $fpay) {
                $free_payments_by_promise[(int)$fpay['promise_id']][] = $fpay;
            }
        } catch(Throwable $e) {}
    }
}

// ── KPIs ─────────────────────────────────────────────────────────────────
$kpi = ['total'=>count($promises),'signed'=>0,'paid_total'=>0,'remaining_total'=>0,'overdue'=>0];
foreach ($promises as $p) {
    if ($p['status'] === 'signed') $kpi['signed']++;
    $kpi['paid_total'] += (float)($p['paid_amount'] ?? 0);
    $kpi['remaining_total'] += (float)($p['remaining_amount'] ?? 0);
    if ($p['sched_status'] === 'overdue') $kpi['overdue']++;
}

function fmtMoney(float $v): string {
    // Affiche les centimes seulement s'ils sont significatifs (41 806,48 TND vs 500 000 TND)
    $dec = ($v != floor($v)) ? 2 : 0;
    return number_format($v, $dec, ',', ' ') . ' TND';
}
function fmtPct(float $v): string { return number_format($v, 2, ',', '.') . ' %'; }
function statusBadge(string $s): string {
    $map = ['draft'=>['Brouillon','#e2eef5','#145f7d'],'signed'=>['Signée','#ecfdf5','#166534'],'cancelled'=>['Annulée','#fef2f2','#991b1b'],'converted_to_sale'=>['Vendue','#fef3c7','#92400e']];
    $d = $map[$s] ?? [$s,'#f4f6f8','#555'];
    return "<span style='background:{$d[1]};color:{$d[2]};padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700'>{$d[0]}</span>";
}
function instBadge(string $s): string {
    $map = ['pending'=>['🕐 En attente','#f4f6f8','#555'],'partially_paid'=>['◑ Partiel','#fff8e1','#92400e'],'paid'=>['✅ C\'est bon','#ecfdf5','#166534'],'overdue'=>['⚠ En retard','#fef2f2','#991b1b']];
    $d = $map[$s] ?? [$s,'#f4f6f8','#555'];
    return "<span style='background:{$d[1]};color:{$d[2]};padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700'>{$d[0]}</span>";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Suivi des paiements – Promed</title>
<?php require __DIR__ . '/includes/crm_style.php'; ?>
<style>
.progress-bar-wrap{height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;margin-top:6px}
.progress-bar-fill{height:100%;border-radius:4px;transition:width .3s}
.inst-table{width:100%;border-collapse:collapse;margin-top:8px;font-size:12px}
.inst-table th{background:#f4f6f8;padding:6px 10px;text-align:left;font-weight:700;border-bottom:2px solid #e5e7eb}
.inst-table td{padding:6px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.inst-table tr.overdue{background:#fef2f2}
.inst-table tr.paid-row{background:#f0fdf4}
.inst-table tr.next-due{background:#fffbeb;font-weight:700}
.promise-card{background:#fff;border:1px solid #dde3ea;border-radius:12px;margin-bottom:18px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.promise-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;gap:8px;cursor:pointer}
.promise-body{padding:14px 16px}
.kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:18px}
.kpi-box{background:#fff;border:1px solid #dde3ea;border-radius:10px;padding:12px 14px}
.kval{font-size:22px;font-weight:800;color:#145f7d}
.klbl{font-size:10px;font-weight:700;text-transform:uppercase;color:#5e6878}
h1{margin:0 0 6px;font-size:22px}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px}
.filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.filters select,.filters input{padding:6px 10px;border:1px solid #dde3ea;border-radius:8px;font-size:13px}
.btn-sm{padding:6px 14px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer}
.btn-brand{background:#145f7d;color:#fff}
.empty{text-align:center;color:#888;padding:40px;font-size:15px}
@media(max-width:700px){.promise-head{flex-direction:column;align-items:flex-start}}
</style>
</head>
<body>
<div style="max-width:1200px;margin:0 auto;padding:20px">

  <div class="topbar">
    <div>
      <h1>📊 Suivi des paiements</h1>
      <div style="color:#5e6878;font-size:13px"><?= count($promises) ?> promesse<?= count($promises)>1?'s':'' ?> — Aujourd'hui : <?= date('d/m/Y') ?></div>
    </div>
    <div style="display:flex;gap:8px">
      <a href="crm_clients.php" style="text-decoration:none"><button class="btn-sm" style="background:#ececec;color:#111">← CRM</button></a>
      <a href="fiche_client.php" style="text-decoration:none"><button class="btn-sm btn-brand">+ Nouvelle fiche</button></a>
    </div>
  </div>

  <!-- KPIs -->
  <div class="kpi-row">
    <div class="kpi-box"><div class="kval"><?= $kpi['total'] ?></div><div class="klbl">Promesses</div></div>
    <div class="kpi-box"><div class="kval" style="color:#166534"><?= $kpi['signed'] ?></div><div class="klbl">Signées</div></div>
    <div class="kpi-box"><div class="kval" style="color:#145f7d"><?= fmtMoney($kpi['paid_total']) ?></div><div class="klbl">Encaissé</div></div>
    <div class="kpi-box"><div class="kval" style="color:#92400e"><?= fmtMoney($kpi['remaining_total']) ?></div><div class="klbl">Restant dû</div></div>
    <?php if ($kpi['overdue']): ?>
    <div class="kpi-box"><div class="kval" style="color:#c03030"><?= $kpi['overdue'] ?></div><div class="klbl">En retard</div></div>
    <?php endif; ?>
  </div>

  <!-- Filtres -->
  <div class="filters" style="margin-bottom:16px">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <select name="status" onchange="this.form.submit()">
        <option value="">Tous les statuts</option>
        <option value="draft" <?= $filter_status==='draft'?'selected':'' ?>>Brouillon</option>
        <option value="signed" <?= $filter_status==='signed'?'selected':'' ?>>Signée</option>
        <option value="converted_to_sale" <?= $filter_status==='converted_to_sale'?'selected':'' ?>>Vendue</option>
      </select>
      <?php if ($filter_promise || $filter_status): ?>
      <a href="paiements.php"><button type="button" class="btn-sm" style="background:#ececec;color:#111">✕ Effacer filtres</button></a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Liste promesses -->
  <?php if (!$promises): ?>
    <div class="empty">Aucune promesse trouvée.</div>
  <?php endif; ?>

  <?php foreach ($promises as $p):
    $sched_id  = $p['schedule_id'];
    $insts     = $installments_by_schedule[$sched_id] ?? [];
    $total     = (float)($p['total_amount'] ?? 0);
    $paid      = $total > 0 ? min((float)($p['paid_amount'] ?? 0), $total) : (float)($p['paid_amount'] ?? 0);
    $progress  = $total > 0 ? min(100, round($paid / $total * 100, 2)) : 0;
    $remaining = max(0, $total - $paid);
    $pcolor    = $progress >= 100 ? '#19733e' : ($progress > 0 ? '#145f7d' : '#e5e7eb');
    $today     = date('Y-m-d');
    $nextDue   = null;
    foreach ($insts as $inst) {
        if (in_array($inst['status'], ['pending','partially_paid']) && $inst['due_date']) {
            $nextDue = $inst; break;
        }
    }
  ?>
  <div class="promise-card">
    <div class="promise-head" onclick="toggleBody('pb<?= $p['id'] ?>')">
      <div>
        <span style="font-size:15px;font-weight:800"><?= htmlspecialchars($p['promise_number']) ?></span>
        <span style="margin-left:10px"><?= statusBadge($p['status']) ?></span>
        <?php if ($p['is_locked']): ?><span style="margin-left:6px;font-size:11px;color:#888">🔒</span><?php endif; ?>
      </div>
      <div style="text-align:right">
        <div style="font-size:14px;font-weight:700"><?= htmlspecialchars($p['client_nom']) ?></div>
        <div style="font-size:12px;color:#5e6878">Unité : <?= htmlspecialchars($p['unit_code']) ?></div>
      </div>
      <div style="width:100%;min-width:200px">
        <div style="display:flex;justify-content:space-between;font-size:12px;color:#555;margin-bottom:2px">
          <span><?= fmtMoney($paid) ?> encaissé</span>
          <span style="font-weight:700;color:<?= $pcolor ?>"><?= round($progress,1) ?>%</span>
        </div>
        <div class="progress-bar-wrap">
          <div class="progress-bar-fill" style="width:<?= min(100,$progress) ?>%;background:<?= $pcolor ?>"></div>
        </div>
        <div style="font-size:11px;color:#888;margin-top:2px"><?= fmtMoney($remaining) ?> restant</div>
      </div>
      <?php if ($nextDue): ?>
      <div style="text-align:right;font-size:12px">
        <div style="color:#92400e;font-weight:700">Prochaine échéance</div>
        <div style="color:#c03030"><?= date('d/m/Y', strtotime($nextDue['due_date'])) ?></div>
        <div><?= fmtMoney((float)$nextDue['remaining_amount']) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <div id="pb<?= $p['id'] ?>" style="display:<?= ($filter_promise == $p['id']) ? 'block' : 'none' ?>">
    <div class="promise-body">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;margin-bottom:12px;font-size:12px">
        <div><span style="color:#888">Client :</span> <?= htmlspecialchars($p['client_nom']) ?></div>
        <div><span style="color:#888">Email :</span> <?= htmlspecialchars($p['client_email'] ?? '—') ?></div>
        <div><span style="color:#888">Tél :</span> <?= htmlspecialchars($p['client_tel'] ?? '—') ?></div>
        <div><span style="color:#888">Prix vente :</span> <?= fmtMoney((float)$p['sale_price']) ?></div>
        <div><span style="color:#888">Promesse :</span> <?= $p['promise_date'] ? date('d/m/Y',strtotime($p['promise_date'])) : '—' ?></div>
        <div><span style="color:#888">Signée :</span> <?= $p['signed_date'] ? date('d/m/Y',strtotime($p['signed_date'])) : '—' ?></div>
      </div>

      <?php if (!$insts): ?>
        <div style="color:#888;font-size:13px;padding:10px 0">Aucun échéancier enregistré.</div>
      <?php else: ?>
      <table class="inst-table">
        <thead>
          <tr>
            <th>#</th><th>Tranche</th><th style="text-align:right">%</th><th>Date limite</th>
            <th style="text-align:right">Montant prévu</th>
            <th style="text-align:right">Payé</th>
            <th style="text-align:right">Reste</th>
            <th>Statut</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php
          $schedTotal = (float)($p['total_amount'] ?? $p['sale_price'] ?? 0);
          foreach ($insts as $i => $inst):
          $rowClass = '';
          if ($inst['status'] === 'overdue') $rowClass = 'overdue';
          elseif ($inst['status'] === 'paid') $rowClass = 'paid-row';
          elseif ($nextDue && $inst['id'] == $nextDue['id']) $rowClass = 'next-due';
          $dueInDays = $inst['due_date'] ? (int)((strtotime($inst['due_date'])-time())/86400) : null;
          $iLabel    = addslashes($inst['label'] ?: 'Tranche '.($i+1));
          $iid       = (int)$inst['id'];
          $iRemaining = (float)$inst['remaining_amount'];
          $iPaid      = (float)$inst['paid_amount'];
          $iExpected  = (float)$inst['expected_amount'];
          $iPct       = $schedTotal > 0 ? round($iExpected / $schedTotal * 100, 2) : 0;
        ?>
          <tr class="<?= $rowClass ?>">
            <td style="color:#999;font-size:11px"><?= $i+1 ?></td>
            <td style="font-weight:700"><?= htmlspecialchars($inst['label'] ?: 'Tranche '.($i+1)) ?></td>
            <td style="text-align:right;color:#6b7280;font-size:12px"><?= fmtPct($iPct) ?></td>
            <td>
              <?= $inst['due_date'] ? date('d/m/Y',strtotime($inst['due_date'])) : '—' ?>
              <?php if ($dueInDays !== null && $dueInDays >= 0 && $dueInDays <= 7 && $inst['status'] !== 'paid'): ?>
                <span style="color:<?= $dueInDays<=2?'#c03030':'#92400e' ?>;font-weight:700;font-size:10px"> J<?= $dueInDays>0?'-'.$dueInDays:' aujourd\'hui' ?></span>
              <?php endif; ?>
            </td>
            <td style="text-align:right"><?= fmtMoney($iExpected) ?></td>
            <td style="text-align:right;color:#166534;font-weight:<?= $iPaid>0?'700':'400' ?>"><?= $iPaid > 0 ? fmtMoney($iPaid) : '—' ?></td>
            <td style="text-align:right;color:<?= $iRemaining>0?'#c03030':'#166534' ?>;font-weight:700"><?= fmtMoney($iRemaining) ?></td>
            <td><?= instBadge($inst['status']) ?></td>
            <td style="white-space:nowrap">
              <?php if ($inst['status'] !== 'paid'): ?>
              <button onclick="payInstallment(<?= (int)$p['id'] ?>, <?= $iid ?>, <?= $iRemaining ?>, '<?= $iLabel ?>')"
                style="background:#145f7d;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;margin-right:4px">
                💳 Payer
              </button>
              <?php endif; ?>
              <button onclick="toggleHistory(<?= $iid ?>)"
                style="background:#f4f6f8;color:#374151;border:1px solid #dde3ea;border-radius:6px;padding:4px 8px;font-size:11px;cursor:pointer"
                id="hist-btn-<?= $iid ?>" title="Voir l'historique des virements">
                🧾 Historique
              </button>
            </td>
          </tr>
          <!-- Ligne historique paiements — chargée dynamiquement -->
          <tr id="hist-row-<?= $iid ?>" data-promise-id="<?= (int)$p['id'] ?>" style="display:none">
            <td colspan="9" style="padding:0;background:#f8fafc">
              <div id="hist-content-<?= $iid ?>" style="padding:12px 16px">
                <div style="color:#888;font-size:12px">Chargement…</div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <?php
        $freePays = $free_payments_by_promise[(int)$p['id']] ?? [];
        if ($freePays):
          $freeTotal = array_sum(array_column($freePays,'amount'));
      ?>
      <div style="margin-top:12px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 14px">
        <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:8px">
          ⚠ Paiements non affectés à une tranche — <?= fmtMoney((float)$freeTotal) ?> comptabilisés dans le total encaissé mais invisibles dans l'échéancier
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:12px">
          <thead>
            <tr style="background:#fef3c7">
              <th style="padding:4px 8px;text-align:left">Date</th>
              <th style="padding:4px 8px;text-align:right">Montant</th>
              <th style="padding:4px 8px;text-align:left">Mode</th>
              <th style="padding:4px 8px;text-align:left">Référence</th>
              <th style="padding:4px 8px;text-align:left">Opérateur</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($freePays as $fp): ?>
            <tr style="border-top:1px solid #fde68a">
              <td style="padding:4px 8px"><?= $fp['payment_date'] ? date('d/m/Y',strtotime($fp['payment_date'])) : '—' ?></td>
              <td style="padding:4px 8px;text-align:right;font-weight:700;color:#92400e"><?= fmtMoney((float)$fp['amount']) ?></td>
              <td style="padding:4px 8px"><?= htmlspecialchars($fp['payment_method'] ?? '—') ?></td>
              <td style="padding:4px 8px;color:#6b7280"><?= htmlspecialchars($fp['reference_number'] ?? '—') ?></td>
              <td style="padding:4px 8px;color:#6b7280"><?= htmlspecialchars($fp['created_by_name'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div style="font-size:11px;color:#92400e;margin-top:6px">
          💡 Ces paiements sont inclus dans le total encaissé. Pour les rattacher à une tranche, utilisez le bouton "Sync échéancier" ou modifiez manuellement dans la base.
        </div>
      </div>
      <?php endif; ?>

      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        <?php if (!$insts): ?>
        <button class="btn-sm" style="background:#ecfdf5;color:#166534;border:1px solid #bbf7d0" onclick="addPayment(<?= $p['id'] ?>)">
          + Enregistrer paiement libre
        </button>
        <?php endif; ?>
        <button class="btn-sm" style="background:#f4f6f8;color:#145f7d;border:1px solid #c7d9e5"
                id="sync-btn-<?= (int)$p['id'] ?>"
                onclick="syncSchedule(<?= (int)$p['id'] ?>, this)"
                title="Recalculer les totaux payé / reste / statut depuis la table des paiements">
          🔄 Sync échéancier
        </button>
      </div>
    </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Modal ajout paiement -->
<div id="payModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:24px;max-width:460px;width:95%;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <h3 id="payModalTitle" style="margin:0 0 4px;font-size:15px">Enregistrer un paiement</h3>
    <div id="payModalSubtitle" style="font-size:12px;color:#6b7280;margin-bottom:14px"></div>
    <input type="hidden" id="payPromiseId">
    <input type="hidden" id="payInstallmentId">
    <input type="hidden" id="payMaxAmount">
    <div style="display:grid;gap:12px">
      <div>
        <label style="font-size:12px;font-weight:700">Montant encaissé (TND)</label>
        <input id="payAmount" type="number" step="0.001" min="0.001" placeholder="Montant du virement"
               style="width:100%;padding:8px 10px;border:1px solid #dde3ea;border-radius:8px;font-size:14px;box-sizing:border-box">
        <div id="payRemaining" style="font-size:11px;color:#6b7280;margin-top:3px"></div>
      </div>
      <div>
        <label style="font-size:12px;font-weight:700">Date de réception</label>
        <input id="payDate" type="date" value="<?= date('Y-m-d') ?>"
               style="width:100%;padding:8px 10px;border:1px solid #dde3ea;border-radius:8px;font-size:14px;box-sizing:border-box">
      </div>
      <div>
        <label style="font-size:12px;font-weight:700">Mode de paiement</label>
        <select id="payMethod" style="width:100%;padding:8px 10px;border:1px solid #dde3ea;border-radius:8px;font-size:14px;box-sizing:border-box">
          <option value="bank_transfer">Virement bancaire</option>
          <option value="cheque">Chèque</option>
          <option value="cash">Espèces</option>
          <option value="traite">Traite</option>
        </select>
      </div>
      <div>
        <label style="font-size:12px;font-weight:700">Référence / N° chèque / N° virement</label>
        <input id="payRef" type="text" placeholder="optionnel"
               style="width:100%;padding:8px 10px;border:1px solid #dde3ea;border-radius:8px;font-size:14px;box-sizing:border-box">
      </div>
      <div>
        <label style="font-size:12px;font-weight:700">Justificatif (PDF, JPG, PNG — max 10 Mo)</label>
        <input id="payDoc" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp"
               style="width:100%;font-size:12px">
      </div>
      <div id="payError" style="color:#c03030;font-size:12px;background:#fff1f2;padding:8px 10px;border-radius:6px;display:none"></div>
      <div style="display:flex;gap:8px">
        <button onclick="submitPayment()" id="paySubmitBtn" class="btn-sm btn-brand" style="flex:1">💾 Enregistrer le virement</button>
        <button onclick="closePayModal()" class="btn-sm" style="flex:1;background:#ececec;color:#111">Annuler</button>
      </div>
    </div>
  </div>
</div>

<script>
function toggleBody(id){ const el=document.getElementById(id); el.style.display=el.style.display==='none'?'block':'none'; }

/* ── Historique des paiements par échéance ── */
const _histLoaded = {};

function toggleHistory(iid) {
  const row = document.getElementById('hist-row-' + iid);
  const btn = document.getElementById('hist-btn-' + iid);
  const visible = row.style.display !== 'none';
  row.style.display = visible ? 'none' : 'table-row';
  btn.textContent   = visible ? '🧾 Historique' : '🔼 Masquer';
  if (!visible && !_histLoaded[iid]) loadHistory(iid);
}

async function loadHistory(iid) {
  const container = document.getElementById('hist-content-' + iid);
  try {
    const res  = await fetch('api/schedule_payments_get.php?installment_id=' + iid);
    const data = await res.json();
    if (data.error) { container.innerHTML = '<span style="color:#c03030">Erreur : ' + data.error + '</span>'; return; }
    _histLoaded[iid] = true;
    renderHistory(iid, data);
  } catch(e) {
    container.innerHTML = '<span style="color:#c03030">Erreur de chargement</span>';
  }
}

function fmtAmt(n){ return Number(n).toLocaleString('fr-FR',{minimumFractionDigits:0,maximumFractionDigits:0}) + ' TND'; }
function fmtDate(d){ if(!d) return '—'; const p=d.split('-'); return p[2]+'/'+p[1]+'/'+p[0]; }

const METHOD_LABELS = {bank_transfer:'Virement',cheque:'Chèque',cash:'Espèces',traite:'Traite'};

function renderHistory(iid, data) {
  const container = document.getElementById('hist-content-' + iid);
  const payments  = data.payments || [];

  if (!payments.length) {
    container.innerHTML = '<div style="color:#9ca3af;font-size:12px;padding:4px 0">Aucun paiement enregistré pour cette échéance.</div>';
    return;
  }

  let html = '<div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px">Historique des virements (' + payments.length + ')</div>';
  html += '<div style="display:flex;flex-direction:column;gap:6px">';

  payments.forEach((pay, idx) => {
    const docs = pay.documents || [];
    const methodLabel = METHOD_LABELS[pay.payment_method] || pay.payment_method || '—';
    html += `
      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px">
          <div>
            <span style="font-weight:700;color:#145f7d;font-size:13px">${fmtAmt(pay.amount)}</span>
            <span style="color:#6b7280;font-size:11px;margin-left:8px">${fmtDate(pay.payment_date)} · ${methodLabel}</span>
            ${pay.reference_number ? '<span style="color:#6b7280;font-size:11px;margin-left:6px">Réf : ' + escHtml(pay.reference_number) + '</span>' : ''}
          </div>
          <div>
            <span style="font-size:10px;color:#9ca3af">${escHtml(pay.created_by_name || '—')}</span>
          </div>
        </div>
        ${pay.notes ? '<div style="font-size:11px;color:#6b7280;margin-top:4px">' + escHtml(pay.notes) + '</div>' : ''}
        ${docs.length ? renderDocs(docs) : '<div style="font-size:11px;color:#9ca3af;margin-top:4px">Aucun justificatif</div>'}
      </div>`;
  });

  html += '</div>';
  container.innerHTML = html;
}

function renderDocs(docs) {
  let h = '<div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:6px">';
  docs.forEach(doc => {
    const isImg = doc.mime_type && doc.mime_type.startsWith('image/');
    const icon  = isImg ? '🖼' : '📄';
    h += `<a href="${escHtml(doc.file_path)}" target="_blank"
              style="font-size:11px;color:#145f7d;padding:3px 8px;border:1px solid #c7d9e5;
                     border-radius:5px;background:#f0f6fb;text-decoration:none;white-space:nowrap">
            ${icon} ${escHtml(doc.original_name || 'Document')}
          </a>`;
  });
  h += '</div>';
  return h;
}

function escHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function uploadJustifForPayment(input, paymentId, installmentId) {
  const file = input.files[0];
  if (!file) return;
  const histRow   = document.getElementById('hist-row-' + installmentId);
  const promiseId = histRow ? histRow.dataset.promiseId : '0';
  const fd = new FormData();
  fd.append('document', file);
  fd.append('payment_id', paymentId);
  fd.append('installment_id', installmentId);
  fd.append('promise_id', promiseId);
  try {
    const res  = await fetch('api/payment_document_upload.php', {method:'POST', body:fd});
    const json = await res.json();
    if (json.success) {
      delete _histLoaded[installmentId];
      loadHistory(installmentId);
    } else {
      alert('Erreur upload : ' + (json.error || 'inconnu'));
    }
  } catch(e) {
    alert('Erreur réseau : ' + e.message);
  }
}

/* ── Modal paiement ── */
function addPayment(pid) {
  document.getElementById('payPromiseId').value      = pid;
  document.getElementById('payInstallmentId').value  = '';
  document.getElementById('payMaxAmount').value      = '';
  document.getElementById('payModalTitle').textContent = 'Enregistrer un paiement';
  document.getElementById('payModalSubtitle').textContent = '';
  document.getElementById('payRemaining').textContent = '';
  document.getElementById('payAmount').value = '';
  document.getElementById('payAmount').max   = '';
  document.getElementById('payDoc').value    = '';
  document.getElementById('payError').style.display = 'none';
  document.getElementById('payModal').style.display = 'flex';
}

function payInstallment(pid, iid, remaining, label) {
  document.getElementById('payPromiseId').value     = pid;
  document.getElementById('payInstallmentId').value = iid;
  document.getElementById('payMaxAmount').value     = remaining;
  document.getElementById('payModalTitle').textContent    = '💳 ' + label;
  document.getElementById('payModalSubtitle').textContent = 'Reste à payer : ' + fmtAmt(remaining);
  document.getElementById('payRemaining').textContent     = 'Maximum autorisé : ' + fmtAmt(remaining);
  document.getElementById('payAmount').value = '';
  document.getElementById('payAmount').max   = remaining;
  document.getElementById('payDoc').value    = '';
  document.getElementById('payError').style.display = 'none';
  document.getElementById('payModal').style.display = 'flex';
}

function closePayModal() { document.getElementById('payModal').style.display = 'none'; }

async function submitPayment() {
  const pid    = document.getElementById('payPromiseId').value;
  const iid    = document.getElementById('payInstallmentId').value;
  const maxAmt = parseFloat(document.getElementById('payMaxAmount').value || 0);
  const amount = parseFloat(document.getElementById('payAmount').value || 0);
  const date   = document.getElementById('payDate').value;
  const method = document.getElementById('payMethod').value;
  const ref    = document.getElementById('payRef').value.trim();
  const err    = document.getElementById('payError');
  const btn    = document.getElementById('paySubmitBtn');

  err.style.display = 'none';

  if (!amount || amount <= 0) {
    err.style.display = 'block';
    err.textContent   = 'Le montant doit être supérieur à 0.';
    return;
  }
  if (!date) {
    err.style.display = 'block';
    err.textContent   = 'La date est obligatoire.';
    return;
  }
  if (iid && maxAmt > 0 && amount > maxAmt + 0.005) {
    err.style.display = 'block';
    err.textContent   = 'Le montant saisi (' + fmtAmt(amount) + ') dépasse le reste à payer de cette échéance (' + fmtAmt(maxAmt) + ').';
    return;
  }

  btn.disabled     = true;
  btn.textContent  = '⏳ Enregistrement…';

  const body = { promise_id: parseInt(pid), amount, payment_date: date, payment_method: method };
  if (ref)  body.reference_number = ref;
  if (iid)  body.installment_id   = parseInt(iid);

  try {
    const res  = await fetch('api/payment_add.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
    const json = await res.json();

    if (!json.success) {
      err.style.display = 'block';
      err.textContent   = json.error || 'Erreur inconnue';
      btn.disabled      = false;
      btn.textContent   = '💾 Enregistrer le virement';
      return;
    }

    // Upload justificatif lié à ce paiement précis
    const file = document.getElementById('payDoc').files[0];
    if (file && json.payment_id) {
      const fd = new FormData();
      fd.append('document',       file);
      fd.append('promise_id',     pid);
      fd.append('payment_id',     json.payment_id);
      if (iid) fd.append('installment_id', iid);
      const upRes  = await fetch('api/payment_document_upload.php', {method:'POST', body:fd});
      const upJson = await upRes.json();
      if (!upJson.success) {
        console.warn('Upload justificatif échoué :', upJson.error);
      }
    }

    closePayModal();
    const toast = document.createElement('div');
    toast.textContent = '✅ Paiement enregistré — rechargement…';
    toast.style.cssText = 'position:fixed;top:20px;right:20px;background:#145f7d;color:#fff;padding:12px 20px;border-radius:10px;font-weight:700;z-index:9999;font-size:14px';
    document.body.appendChild(toast);
    setTimeout(() => location.reload(), 900);

  } catch(e) {
    err.style.display = 'block';
    err.textContent   = 'Erreur serveur : ' + e.message;
    btn.disabled      = false;
    btn.textContent   = '💾 Enregistrer le virement';
  }
}

async function syncSchedule(promiseId, btn) {
  btn.disabled    = true;
  btn.textContent = '⏳ Sync…';
  try {
    const res  = await fetch('api/schedule_sync.php', {
      method:  'POST',
      headers: {'Content-Type':'application/json'},
      body:    JSON.stringify({promise_id: promiseId})
    });
    const json = await res.json();
    if (json.success) {
      btn.textContent = '✅ Synchronisé';
      btn.style.background = '#ecfdf5';
      btn.style.color      = '#166534';
      btn.style.border     = '1px solid #bbf7d0';
      setTimeout(() => location.reload(), 700);
    } else {
      btn.textContent = '❌ Erreur';
      btn.disabled    = false;
      alert('Erreur sync : ' + (json.error || 'inconnue'));
    }
  } catch(e) {
    btn.textContent = '❌ Erreur';
    btn.disabled    = false;
    alert('Erreur réseau : ' + e.message);
  }
}
</script>
</body>
</html>
