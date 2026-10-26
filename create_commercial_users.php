<?php
require __DIR__ . '/config.php';

/**
 * Usage:
 *   php create_commercial_users.php users.json
 *
 * Format users.json:
 * [
 *   {"username":"hiba.jemai@promedgroup.tn","full_name":"Hiba Jemai","password":"..."},
 *   {"username":"prenom.nom@promedgroup.tn","full_name":"Prenom Nom","password":"..."}
 * ]
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(400);
  echo "Ce script doit être lancé en ligne de commande.\n";
  exit(1);
}

$jsonPath = $argv[1] ?? '';
if ($jsonPath === '' || !is_file($jsonPath)) {
  fwrite(STDERR, "Fichier JSON manquant. Exemple: php create_commercial_users.php users.json\n");
  exit(1);
}

$raw = file_get_contents($jsonPath);
$users = json_decode($raw, true);
if (!is_array($users)) {
  fwrite(STDERR, "JSON invalide.\n");
  exit(1);
}

$pdo->beginTransaction();
try {
  $select = $pdo->prepare("SELECT id FROM users WHERE username = :u");
  $insert = $pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role)
     VALUES (:u, :p, :f, 'commercial')"
  );
  $update = $pdo->prepare(
    "UPDATE users
     SET password_hash = :p, full_name = :f, role = 'commercial'
     WHERE username = :u"
  );

  $created = 0;
  $updated = 0;

  foreach ($users as $i => $u) {
    $username = trim((string)($u['username'] ?? ''));
    $fullName = trim((string)($u['full_name'] ?? ''));
    $password = (string)($u['password'] ?? '');

    if ($username === '' || $fullName === '' || $password === '') {
      throw new RuntimeException("Entrée invalide à l'index $i (username/full_name/password requis).");
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) {
      throw new RuntimeException("Échec de hash du mot de passe pour '$username'.");
    }

    $select->execute([':u' => $username]);
    $row = $select->fetch();

    if ($row) {
      $update->execute([':u' => $username, ':p' => $hash, ':f' => $fullName]);
      $updated++;
    } else {
      $insert->execute([':u' => $username, ':p' => $hash, ':f' => $fullName]);
      $created++;
    }
  }

  $pdo->commit();
  echo "OK. Créés: $created, mis à jour: $updated\n";
} catch (Throwable $e) {
  $pdo->rollBack();
  fwrite(STDERR, "Erreur: " . $e->getMessage() . "\n");
  exit(1);
}

