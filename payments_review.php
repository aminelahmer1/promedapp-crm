<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

// ── Action : marquer un paiement pending_review ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['mark_pending'])) {
    $ids = array_map('intval', (array)$_POST['mark_pending']);
    if ($ids) {
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE payments SET status='pending_review' WHERE id IN ($ph) AND (status IS NULL OR status = 'valid')")
                ->execute($ids);
            $marked = $pdo->rowCount();
        } catch (Throwable $e) { $marked = 0; }
    }
    header('Location: payments_review.php?marked=' . ($marked ?? 0));
    exit;
}

// ── Action : rattacher à une promesse ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['attach_promise'])) {
    $payment_id = (int)$_POST['payment_id'];
    $promise_id = (int)$_POST['promise_id_target'];
    if ($payment_id && $promise_id) {
        try {
            $stmt = $pdo->prepare("SELECT id, unit_code, client_id FROM promises WHERE id = ?");
            $stmt->execute([$promise_id]);
            $p = $stmt->fetch();
            if ($p) {
                $pdo->prepare("UPDATE payments SET promise_id=?, unit_code=?, client_id=?, status='valid' WHERE id=?")
                    ->execute([$promise_id, $p['unit_code'], $p['client_id'], $payment_id]);
            }
        } catch (Throwable $e) {}
    }
    header('Location: payments_review.php');
    exit;
}

// ── Lister les paiements orphelins ───────────────────────────────────────
// Orphelin = promise_id NULL, ou installment_id NULL alors que des échéances existent, ou status=pending_review
try {
    $orphans = $pdo->query("
        SELECT pay.id, pay.promise_id, pay.installment_id, pay.client_id,
               pay.unit_code, pay.amount, pay.payment_date, pay.status,
               pay.payment_method, pay.notes,
               c.nom AS client_nom
        FROM payments pay
        LEFT JOIN clients c ON c.id = pay.client_id
        WHERE pay.promise_id IS NULL
           OR pay.status = 'pending_review'
           OR (pay.installment_id IS NULL AND pay.promise_id IS NOT NULL AND EXISTS (
               SELECT 1 FROM payment_schedules ps
               JOIN payment_installments pi ON pi.schedule_id = ps.id
               WHERE ps.promise_id = pay.promise_id
           ))
        ORDER BY pay.payment_date DESC, pay.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $orphans = [];
}

// ── Promesses disponibles pour rattachement ──────────────────────────────
try {
    $all_promises = $pdo->query("
        SELECT p.id, p.promise_number, p.unit_code, c.nom AS client_nom
        FROM promises p
        JOIN clients c ON c.id = p.client_id
        WHERE p.status != 'cancelled'
        ORDER BY p.created_at DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $all_promises = [];
}

$marked = (int)($_GET['marked'] ?? 0);
$fmtM = fn(float $v) => number_format($v, 3, ',', ' ') . ' TND';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vérification paiements orphelins – Promed</title>
<?php require __DIR__ . '/includes/crm_style.php'; ?>
<style>
.card{background:#fff;border:1px solid #dde3ea;border-radius:12px;padding:22px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
table{width:100%;border-collapse:collapse;font-size:13px}
th{background:#f4f6f8;padding:8px 12px;text-align:left;font-weight:700;border-bottom:2px solid #e5e7eb;white-space:nowrap}
td{padding:7px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.badge-warn{background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700}
.badge-review{background:#fef2f2;color:#991b1b;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700}
.btn-sm{padding:5px 12px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer}
</style>
</head>
<body>
<div style="max-width:1100px;margin:0 auto;padding:20px">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
    <h1 style="margin:0;font-size:22px">🔍 Paiements orphelins</h1>
    <a href="paiements.php"><button class="btn-sm" style="background:#e2eef5;color:#145f7d">← Suivi paiements</button></a>
  </div>

  <?php if ($marked): ?>
  <div style="background:#ecfdf5;color:#166534;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-weight:700">
    ✓ <?= $marked ?> paiement(s) marqués en attente de vérification (pending_review).
  </div>
  <?php endif; ?>

  <div class="card">
    <p style="color:#5e6878;margin:0 0 14px;font-size:13px">
      Ces paiements sont soit sans <code>promise_id</code>, soit sans <code>installment_id</code> alors qu'un échéancier existe, soit marqués <code>pending_review</code>.<br>
      <strong>Ils n'entrent pas dans les totaux validés tant qu'ils ne sont pas rattachés.</strong>
    </p>

    <?php if (!$orphans): ?>
      <p style="color:#19733e;font-weight:700">✓ Aucun paiement orphelin détecté.</p>
    <?php else: ?>
      <form method="POST">
        <table>
          <thead>
            <tr>
              <th><input type="checkbox" id="chkAll" onchange="document.querySelectorAll('.chk-row').forEach(c=>c.checked=this.checked)"></th>
              <th>ID</th><th>Client</th><th>Unité</th><th>Montant</th>
              <th>Date</th><th>promise_id</th><th>installment_id</th><th>Statut</th><th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($orphans as $pay): ?>
            <tr>
              <td><input type="checkbox" name="mark_pending[]" value="<?= $pay['id'] ?>" class="chk-row" <?= $pay['status'] === 'pending_review' ? 'disabled' : '' ?>></td>
              <td style="color:#888">#<?= $pay['id'] ?></td>
              <td><?= htmlspecialchars($pay['client_nom'] ?? '—') ?></td>
              <td><code><?= htmlspecialchars($pay['unit_code'] ?? '—') ?></code></td>
              <td style="font-weight:700;color:#145f7d"><?= $fmtM((float)$pay['amount']) ?></td>
              <td><?= $pay['payment_date'] ? date('d/m/Y', strtotime($pay['payment_date'])) : '—' ?></td>
              <td><?= $pay['promise_id'] ? '#'.$pay['promise_id'] : '<span class="badge-warn">NULL</span>' ?></td>
              <td><?= $pay['installment_id'] ? '#'.$pay['installment_id'] : '<span class="badge-warn">NULL</span>' ?></td>
              <td>
                <?php if ($pay['status'] === 'pending_review'): ?>
                  <span class="badge-review">⏸ En attente</span>
                <?php else: ?>
                  <span style="background:#f0fdf4;color:#166534;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700"><?= htmlspecialchars($pay['status'] ?? 'valid') ?></span>
                <?php endif; ?>
              </td>
              <td>
                <!-- Rattacher à une promesse -->
                <form method="POST" style="display:inline-flex;gap:4px;align-items:center">
                  <input type="hidden" name="payment_id" value="<?= $pay['id'] ?>">
                  <select name="promise_id_target" style="font-size:11px;padding:3px 6px;border:1px solid #ddd;border-radius:5px;max-width:140px">
                    <option value="">Choisir promesse…</option>
                    <?php foreach ($all_promises as $pr): ?>
                      <option value="<?= $pr['id'] ?>" <?= $pay['promise_id'] == $pr['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($pr['promise_number']) ?> — <?= htmlspecialchars($pr['unit_code']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" name="attach_promise" value="1" class="btn-sm" style="background:#145f7d;color:#fff">Rattacher</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
          <button type="submit" name="" class="btn-sm" style="background:#fef3c7;color:#92400e;border:1px solid #f59e0b"
            onclick="return confirm('Marquer les paiements sélectionnés en attente de vérification ?')">
            ⏸ Marquer sélection : pending_review
          </button>
          <span style="font-size:12px;color:#888"><?= count($orphans) ?> paiement(s) orphelin(s)</span>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <div class="card" style="background:#f8fafc">
    <h3 style="margin:0 0 8px;font-size:14px;color:#145f7d">Règle 1 paiement = 1 promesse = 1 unité = 1 échéance</h3>
    <ul style="font-size:12px;color:#5e6878;margin:0;padding-left:18px;line-height:1.8">
      <li>Un paiement <code>valid</code> doit toujours avoir <code>promise_id</code> + <code>installment_id</code> renseignés.</li>
      <li>Les totaux affichés dans "Suivi des paiements" n'incluent jamais les paiements <code>cancelled</code> ni <code>pending_review</code>.</li>
      <li>Rattacher un paiement à la bonne promesse le réintègre automatiquement dans les calculs.</li>
    </ul>
  </div>
</div>
</body>
</html>
