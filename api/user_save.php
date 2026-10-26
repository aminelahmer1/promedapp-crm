<?php
ini_set('display_errors', '0');
error_reporting(0);
require __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Accès refusé — réservé aux administrateurs']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$id            = isset($body['id']) ? (int)$body['id'] : null;
$toggle_active = $body['toggle_active'] ?? null;
$delete_user   = $body['delete_user']   ?? false;

// ── Suppression ─────────────────────────────────────────────────────────────
if ($delete_user && $id) {
    if ($id === (int)$_SESSION['user_id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Impossible de supprimer votre propre compte']);
        exit;
    }
    try {
        $pdo->prepare("DELETE FROM agence_unit_access WHERE user_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur serveur']);
    }
    exit;
}

// ── Activation / désactivation ──────────────────────────────────────────────
if ($toggle_active !== null && $id) {
    if ($id === (int)$_SESSION['user_id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Impossible de modifier votre propre compte']);
        exit;
    }
    try {
        $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")
            ->execute([$toggle_active ? 1 : 0, $id]);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erreur serveur']);
    }
    exit;
}

// ── Création / modification ─────────────────────────────────────────────────
$username     = trim($body['username']  ?? '');
$full_name    = trim($body['full_name'] ?? '');
$email        = trim($body['email']     ?? '') ?: null;
$phone        = trim($body['phone']     ?? '') ?: null;
$role         = in_array($body['role'] ?? '', ['admin', 'commercial', 'agence'], true) ? $body['role'] : 'commercial';
$password     = $body['password']     ?? null;
$agence_units = $body['agence_units'] ?? null; // ['AVA'=>['A1-1',...], 'FLAVA'=>[...]]

if ($username === '' || $full_name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Identifiant et nom complet sont obligatoires']);
    exit;
}
if (!$id && !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Le mot de passe est obligatoire pour un nouvel utilisateur']);
    exit;
}
if ($password !== null && strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['error' => 'Le mot de passe doit contenir au moins 6 caractères']);
    exit;
}

try {
    $savedId = null;

    if ($id) {
        // Modification
        if ($password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("
                UPDATE users SET username=?, full_name=?, email=?, phone=?, role=?, password_hash=?
                WHERE id=?
            ")->execute([$username, $full_name, $email, $phone, $role, $hash, $id]);
        } else {
            $pdo->prepare("
                UPDATE users SET username=?, full_name=?, email=?, phone=?, role=?
                WHERE id=?
            ")->execute([$username, $full_name, $email, $phone, $role, $id]);
        }
        $savedId = $id;
    } else {
        // Création — vérifier unicité du login
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$username]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Cet identifiant est déjà utilisé']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("
            INSERT INTO users (username, password_hash, full_name, email, phone, role, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ")->execute([$username, $hash, $full_name, $email, $phone, $role]);

        $savedId = (int)$pdo->lastInsertId();
    }

    // ── Synchronisation des unités agence ───────────────────────────────────
    // Toujours effacer les anciens accès puis réinsérer si le rôle est agence
    $pdo->prepare("DELETE FROM agence_unit_access WHERE user_id = ?")->execute([$savedId]);

    if ($role === 'agence' && is_array($agence_units)) {
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO agence_unit_access (user_id, project_code, unit_code) VALUES (?, ?, ?)"
        );
        foreach ($agence_units as $projCode => $unitCodes) {
            $projCode = strtoupper(trim((string)$projCode));
            if (!in_array($projCode, ['AVA','FLAVA'], true)) continue;
            foreach ((array)$unitCodes as $uc) {
                $uc = trim((string)$uc);
                if ($uc !== '') $ins->execute([$savedId, $projCode, $uc]);
            }
        }
    }

    echo json_encode(['success' => true, 'id' => $savedId]);

} catch (Throwable $e) {
    error_log('[user_save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}
