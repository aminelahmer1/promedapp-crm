<?php
require __DIR__ . '/config.php';

// Remplit units.plan_image et unit_accessoires.plan_image pour FLAVA
// en scannant les fichiers existants sous :
// - uploads/plans/units/flava
// - uploads/plans/accessories/flava

function realpath_rel(string $relative): string {
  $root = realpath(__DIR__ . '/.');
  $full = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
  $fullReal = realpath($full);
  return $fullReal ?: $full;
}

function scanFilesToMap(string $absDir, string $relDir): array {
  if (!is_dir($absDir)) return [];
  $map = [];
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS));
  foreach ($iterator as $fileInfo) {
    /** @var SplFileInfo $fileInfo */
    if (!$fileInfo->isFile()) continue;
    $name = $fileInfo->getFilename(); // e.g. a1-1.pdf
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $base = pathinfo($name, PATHINFO_FILENAME); // e.g. a1-1

    // clé = ref_code/code en minuscules
    $key = mb_strtolower(trim((string)$base), 'UTF-8');
    $relPath = rtrim($relDir, '/\\') . '/' . $name;
    $map[$key] = $relPath;
  }
  return $map;
}

$unitsDirRel = 'uploads/plans/units/flava';
$accDirRel = 'uploads/plans/accessories/flava';
$unitsDirAbs = realpath_rel($unitsDirRel);
$accDirAbs = realpath_rel($accDirRel);

$unitsMap = scanFilesToMap($unitsDirAbs, $unitsDirRel);
$accMap = scanFilesToMap($accDirAbs, $accDirRel);

if (!$unitsMap && !$accMap) {
  fwrite(STDERR, "Aucun fichier scanné (répertoires absents ?). Fermeture.\n");
  exit(1);
}

$stmtUnits = $pdo->query("
  SELECT u.id, u.ref_code, u.plan_image
  FROM units u
  JOIN projects p ON p.id = u.project_id
  WHERE UPPER(p.code)='FLAVA'
    AND (u.plan_image IS NULL OR u.plan_image='' )
");

$stmtAcc = $pdo->query("
  SELECT a.id, a.code, a.plan_image
  FROM unit_accessoires a
  JOIN projects p ON p.id = a.project_id
  WHERE UPPER(p.code)='FLAVA'
    AND (a.plan_image IS NULL OR a.plan_image='' )
");

$updUnits = $pdo->prepare("UPDATE units SET plan_image = :img WHERE id = :id");
$updAcc = $pdo->prepare("UPDATE unit_accessoires SET plan_image = :img WHERE id = :id");

$createdUnits = 0;
$createdAcc = 0;
$missingUnits = 0;
$missingAcc = 0;

$pdo->beginTransaction();
try {
  foreach ($stmtUnits->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $key = mb_strtolower(trim((string)($u['ref_code'] ?? '')), 'UTF-8');
    $img = $unitsMap[$key] ?? null;
    if ($img) {
      $updUnits->execute([':img' => $img, ':id' => (int)$u['id']]);
      $createdUnits++;
    } else {
      $missingUnits++;
    }
  }

  foreach ($stmtAcc->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $key = mb_strtolower(trim((string)($a['code'] ?? '')), 'UTF-8');
    $img = $accMap[$key] ?? null;
    if ($img) {
      $updAcc->execute([':img' => $img, ':id' => (int)$a['id']]);
      $createdAcc++;
    } else {
      $missingAcc++;
    }
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  fwrite(STDERR, "Erreur: " . $e->getMessage() . "\n");
  exit(1);
}

echo "FLAVA plan_image units mises à jour: $createdUnits (missing=$missingUnits)\n";
echo "FLAVA plan_image accessoires mises à jour: $createdAcc (missing=$missingAcc)\n";

