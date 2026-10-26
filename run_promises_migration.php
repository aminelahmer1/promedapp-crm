<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { die('Non authentifié'); }

function sql_strip_comments(string $sql): string {
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $sql = preg_replace('/--[^\n]*/u', '', $sql);
    return $sql;
}

function sql_split(string $sql): array {
    $result = array();
    foreach (explode(';', $sql) as $s) {
        $s = trim($s);
        if ($s !== '') $result[] = $s;
    }
    return $result;
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table, $column]);
    return (bool)$s->fetchColumn();
}

function index_exists(PDO $pdo, string $table, string $index): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
    $s->execute([$table, $index]);
    return (bool)$s->fetchColumn();
}

function run_statement(PDO $pdo, string $stmt): array {
    $exec = trim($stmt);
    if ($exec === '') return array('status' => 'skip', 'msg' => 'vide');
    if (preg_match('/^\s*DROP\s+TABLE\b/i', $exec))  return array('status' => 'skip', 'msg' => 'DROP TABLE interdit');
    if (preg_match('/^\s*TRUNCATE\b/i', $exec))       return array('status' => 'skip', 'msg' => 'TRUNCATE interdit');
    if (preg_match('/^\s*DELETE\s+FROM\b/i', $exec) && !preg_match('/\bWHERE\b/i', $exec))
        return array('status' => 'skip', 'msg' => 'DELETE sans WHERE interdit');

    // ALTER TABLE ADD COLUMN → vérification PHP
    if (preg_match('/^\s*ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $exec, $m)) {
        if (column_exists($pdo, $m[1], $m[2]))
            return array('status' => 'skip', 'msg' => "Colonne `{$m[2]}` déjà présente dans `{$m[1]}`");
        $exec = preg_replace('/\bIF\s+NOT\s+EXISTS\b/i', '', $exec);
    }

    // ALTER TABLE ADD INDEX → vérification PHP
    if (preg_match('/^\s*ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $exec, $m)) {
        if (index_exists($pdo, $m[1], $m[2]))
            return array('status' => 'skip', 'msg' => "Index `{$m[2]}` déjà présent dans `{$m[1]}`");
        $exec = preg_replace('/\bIF\s+NOT\s+EXISTS\b/i', '', $exec);
    }

    try {
        $pdo->exec($exec);
        return array('status' => 'ok');
    } catch (Throwable $e) {
        return array('status' => 'error', 'msg' => $e->getMessage());
    }
}

$sqlFile = __DIR__ . '/sql/migration_promises_columns.sql';
if (!file_exists($sqlFile)) die('Fichier SQL introuvable.');

$stmts = sql_split(sql_strip_comments(file_get_contents($sqlFile)));
$ok = 0; $skipped = 0; $errors = array();

foreach ($stmts as $stmt) {
    $r = run_statement($pdo, $stmt);
    if ($r['status'] === 'ok')        { $ok++; }
    elseif ($r['status'] === 'skip')  { $skipped++; }
    else { $errors[] = array('msg' => $r['msg'], 'stmt' => substr($stmt, 0, 200)); }
}
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><title>Migration Colonnes Promises</title>
<style>body{font-family:Arial,sans-serif;padding:30px;background:#f4f6f8}.card{background:#fff;border:1px solid #dde3ea;border-radius:12px;padding:28px;max-width:860px;margin:0 auto;box-shadow:0 4px 20px rgba(0,0,0,.06)}h1{color:#145f7d;margin:0 0 6px}.sub{color:#5e6878;margin-bottom:22px;font-size:13px}.badge-ok{display:inline-block;color:#19733e;background:#e6f5ed;padding:4px 14px;border-radius:4px;font-weight:700}.badge-skip{display:inline-block;color:#92400e;background:#fef3c7;padding:4px 14px;border-radius:4px;font-weight:700;margin-left:8px}.err-block{background:#fdecea;border:1px solid #f5c6c6;border-radius:8px;padding:12px 16px;margin-bottom:10px;color:#c03030;font-size:13px}.err-block code{display:block;margin-top:8px;font-size:11px;color:#555;white-space:pre-wrap;background:#fff5f5;padding:8px;border-radius:4px}.actions{margin-top:26px;display:flex;gap:10px}.btn-p{padding:10px 20px;background:#145f7d;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px}.btn-s{padding:10px 20px;background:#e2eef5;color:#145f7d;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px}</style></head>
<body><div class="card">
  <h1>Migration Colonnes Promises & Paiements</h1>
  <p class="sub">Tables : <code>promises</code>, <code>payment_installments</code>, <code>payments</code></p>
  <div style="margin-bottom:20px">
    <span class="badge-ok"><?= $ok ?> instruction(s) exécutée(s) ✓</span>
    <?php if ($skipped > 0): ?><span class="badge-skip"><?= $skipped ?> ignorée(s) (déjà existante)</span><?php endif; ?>
  </div>
  <?php if ($errors): ?>
    <h3 style="color:#c03030"><?= count($errors) ?> erreur(s) :</h3>
    <?php foreach ($errors as $err): ?>
      <div class="err-block"><?= htmlspecialchars($err['msg']) ?><code><?= htmlspecialchars($err['stmt']) ?>…</code></div>
    <?php endforeach; ?>
  <?php else: ?>
    <p style="color:#19733e;font-weight:700">✓ Aucune erreur SQL.</p>
  <?php endif; ?>
  <div class="actions">
    <a href="paiements.php" class="btn-p">→ Suivi des paiements</a>
    <a href="fiche_client.php" class="btn-s">→ Fiche client</a>
  </div>
</div></body></html>
