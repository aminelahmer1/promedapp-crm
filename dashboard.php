<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$code = $_SESSION['current_project_code'] ?? 'FLAVA';

// Sécurité : si "commercial", on ne laisse voir que AVA + FLAVA
$role = strtolower(trim((string)($_SESSION['role'] ?? '')));
if ($role === 'commercial' || $role === 'agence') {
  $allowed = ['AVA', 'FLAVA'];
  if (!in_array((string)$code, $allowed, true)) {
    header('Location: select_project.php');
    exit;
  }
}

if ($code === 'AVA') {
    header('Location: dashboard_ava.php');
    exit;
}

header('Location: dashboard_flava.php');
exit;
