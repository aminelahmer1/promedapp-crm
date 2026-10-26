<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { die('Non authentifié'); }

/* ── Helpers ──────────────────────────────────────────────────────────── */

function sql_strip_comments(string $sql): string {
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);  // /* ... */
    $sql = preg_replace('/--[^\n]*/u', '', $sql);      // -- ligne
    return $sql;
}

function sql_split(string $sql): array {
    return array_values(array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== ''
    ));
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $s->execute([$table, $column]);
    return (bool)$s->fetchColumn();
}

function index_exists(PDO $pdo, string $table, string $indexName): bool {
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ");
    $s->execute([$table, $indexName]);
    return (bool)$s->fetchColumn();
}

function run_statement(PDO $pdo, string $stmt): array {
    // Supprimer IF NOT EXISTS résiduels sur ALTER TABLE (PHP gère les checks)
    $exec = trim(preg_replace('/\bIF\s+NOT\s+EXISTS\b/i', '', $stmt));

    // ── Opérations interdites ──────────────────────────────────────────
    if (preg_match('/^\s*DROP\s+TABLE\b/i', $exec)) {
        return ['status' => 'skip', 'msg' => 'DROP TABLE interdit dans les migrations'];
    }
    if (preg_match('/^\s*TRUNCATE\b/i', $exec)) {
        return ['status' => 'skip', 'msg' => 'TRUNCATE interdit dans les migrations'];
    }
    if (preg_match('/^\s*DELETE\s+FROM\b/i', $exec) && !preg_match('/\bWHERE\b/i', $exec)) {
        return ['status' => 'skip', 'msg' => 'DELETE sans WHERE interdit'];
    }
    // CREATE TABLE sans IF NOT EXISTS
    if (preg_match('/^\s*CREATE\s+TABLE\b(?!\s+IF\s+NOT\s+EXISTS)/i', $exec)) {
        return ['status' => 'skip', 'msg' => 'CREATE TABLE sans IF NOT EXISTS interdit — corrigez le fichier SQL'];
    }

    // ── ALTER TABLE ADD COLUMN → vérification PHP ─────────────────────
    if (preg_match(
        '/^\s*ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(?:COLUMN\s+)?`?(\w+)`?/i',
        $exec, $m
    )) {
        if (column_exists($pdo, $m[1], $m[2])) {
            return ['status' => 'skip', 'msg' => "Colonne `{$m[2]}` déjà présente dans `{$m[1]}`"];
        }
    }

    // ── ALTER TABLE ADD INDEX → vérification PHP ───────────────────────
    if (preg_match(
        '/^\s*ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?(\w+)`?/i',
        $exec, $m
    )) {
        if (index_exists($pdo, $m[1], $m[2])) {
            return ['status' => 'skip', 'msg' => "Index `{$m[2]}` déjà présent dans `{$m[1]}`"];
        }
    }

    // ── Exécution ──────────────────────────────────────────────────────
    try {
        $pdo->exec($exec);
        return ['status' => 'ok'];
    } catch (Throwable $e) {
        return ['status' => 'error', 'msg' => $e->getMessage()];
    }
}

/* ── Exécution de la migration ────────────────────────────────────────── */

$sqlFile = __DIR__ . '/sql/migration_crm2.sql';
$raw     = file_get_contents($sqlFile);
$clean   = sql_strip_comments($raw);
$stmts   = sql_split($clean);

$ok = 0; $skipped = 0; $errors = [];

foreach ($stmts as $stmt) {
    $r = run_statement($pdo, $stmt);
    if ($r['status'] === 'ok')        { $ok++; }
    elseif ($r['status'] === 'skip')  { $skipped++; }
    else { $errors[] = ['msg' => $r['msg'], 'stmt' => substr($stmt, 0, 200)]; }
}
?><!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Migration CRM v2</title>
<style>
body{font-family:Arial,sans-serif;padding:30px;background:#f4f6f8}
.card{background:#fff;border:1px solid #dde3ea;border-radius:12px;padding:24px;max-width:860px;margin:0 auto;box-shadow:0 4px 20px rgba(0,0,0,.06)}
h1{color:#145f7d;margin-bottom:4px}
.badge-ok   {display:inline-block;color:#19733e;background:#e6f5ed;padding:3px 12px;border-radius:4px;font-weight:700}
.badge-skip {display:inline-block;color:#92400e;background:#fef3c7;padding:3px 12px;border-radius:4px;font-weight:700;margin-left:8px}
.err-block  {background:#fdecea;border:1px solid #f5c6c6;border-radius:8px;padding:12px;margin-bottom:10px;color:#c03030;font-size:13px}
.err-block code{display:block;margin-top:6px;font-size:11px;color:#555;white-space:pre-wrap;word-break:break-all;background:#fff5f5;padding:6px;border-radius:4px}
</style></head>
<body>
<div class="card">
  <h1>Migration CRM v2</h1>
  <p style="color:#5e6878;margin-bottom:20px">
    Tables : <code>crm_client_needs</code>, <code>crm_client_units</code>, <code>crm_visits</code>
    — Extensions : <code>clients</code>, <code>crm_activities</code>
  </p>

  <div style="margin-bottom:16px">
    <span class="badge-ok"><?= $ok ?> instruction(s) exécutée(s) ✓</span>
    <?php if ($skipped): ?>
      <span class="badge-skip"><?= $skipped ?> ignorée(s) (déjà existante)</span>
    <?php endif; ?>
  </div>

  <?php if ($errors): ?>
    <h3 style="color:#c03030;margin-top:20px"><?= count($errors) ?> erreur(s) :</h3>
    <?php foreach ($errors as $err): ?>
      <div class="err-block">
        <?= htmlspecialchars($err['msg']) ?>
        <code><?= htmlspecialchars($err['stmt']) ?>…</code>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <p style="margin-top:24px">
    <a href="run_crm_fakedata.php" style="display:inline-block;padding:10px 20px;background:#145f7d;color:#fff;border-radius:8px;text-decoration:none;font-weight:700">→ Données de démo</a>
    &nbsp;
    <a href="crm_dashboard.php" style="display:inline-block;padding:10px 20px;background:#e2eef5;color:#145f7d;border-radius:8px;text-decoration:none;font-weight:700">→ Dashboard CRM</a>
  </p>
</div>
</body></html>
