<?php
require __DIR__ . '/config.php';

/**
 * Recalcule les positions x/y/w/h du plan Sous-sol AVA (ACCESSORY)
 * en fonction de la taille réelle de l'image de fond actuelle.
 *
 * Hypothèse: les positions actuelles ont été prises sur une ancienne image plus petite,
 * donc on scale en se basant sur max(x+w) et max(y+h) existants comme "ancienne taille".
 */

$imagePath = __DIR__ . '/assets/plans/ava/sous_sol.png';
if (!is_file($imagePath)) {
  fwrite(STDERR, "Image AVA sous_sol introuvable: $imagePath\n");
  exit(1);
}

$size = @getimagesize($imagePath);
if (!$size) {
  fwrite(STDERR, "Impossible de lire la taille de l'image: $imagePath\n");
  exit(1);
}
$newW = (int)$size[0];
$newH = (int)$size[1];

// Trouver le floor_plan_id AVA Sous-sol ACCESSORIES
$stmt = $pdo->prepare("
  SELECT fp.id
  FROM floor_plans fp
  JOIN projects p ON p.id = fp.project_id
  WHERE UPPER(p.code)='AVA' AND fp.floor_name='Sous-sol'
  LIMIT 1
");
$stmt->execute();
$floorPlanId = (int)$stmt->fetchColumn();
if (!$floorPlanId) {
  fwrite(STDERR, "floor_plan AVA Sous-sol introuvable.\n");
  exit(1);
}

// Mesurer "ancienne taille" d'après les positions existantes
$stmt = $pdo->prepare("
  SELECT
    MAX(app.x + app.w) AS maxX,
    MAX(app.y + app.h) AS maxY,
    COUNT(*) AS cnt
  FROM asset_plan_positions app
  WHERE app.floor_plan_id = :fp
    AND app.asset_type='ACCESSORY'
");
$stmt->execute([':fp' => $floorPlanId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$oldW = (int)($row['maxX'] ?? 0);
$oldH = (int)($row['maxY'] ?? 0);
$cnt  = (int)($row['cnt'] ?? 0);

if ($cnt <= 0 || $oldW <= 0 || $oldH <= 0) {
  fwrite(STDERR, "Aucune position ACCESSORY trouvée pour AVA sous-sol.\n");
  exit(1);
}

$scaleX = $newW / $oldW;
$scaleY = $newH / $oldH;

// Mise à jour avec arrondi entier
$stmt = $pdo->prepare("
  UPDATE asset_plan_positions
  SET
    x = ROUND(x * :sx),
    y = ROUND(y * :sy),
    w = GREATEST(1, ROUND(w * :sx)),
    h = GREATEST(1, ROUND(h * :sy))
  WHERE floor_plan_id = :fp AND asset_type='ACCESSORY'
");

$pdo->beginTransaction();
try {
  $stmt->execute([
    ':sx' => $scaleX,
    ':sy' => $scaleY,
    ':fp' => $floorPlanId
  ]);
  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  fwrite(STDERR, "Erreur update: " . $e->getMessage() . "\n");
  exit(1);
}

echo "OK rescale AVA sous-sol ACCESSORY.\n";
echo "old ~ {$oldW}x{$oldH} -> new {$newW}x{$newH}\n";
echo "scaleX={$scaleX} scaleY={$scaleY} (count={$cnt})\n";

