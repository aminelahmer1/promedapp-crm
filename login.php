<?php
require __DIR__ . '/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username !== '' && $password !== '') {
        $stmt = $pdo->prepare(
          "SELECT id, username, password_hash, full_name, role, is_active
           FROM users
           WHERE username = :u"
        );
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash']) && ($user['is_active'] ?? 1)) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            
            header('Location: select_project.php');
            exit;
        } elseif ($user && !($user['is_active'] ?? 1)) {
            $error = "Ce compte est désactivé. Contactez l'administrateur.";
        } else {
            $error = "Identifiant ou mot de passe incorrect.";
        }
    } else {
        $error = "Merci de remplir tous les champs.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <title>Connexion – PROMED Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:#f5f5f5;
      display:flex;
      align-items:center;
      justify-content:center;
      min-height:100vh;
      margin:0;
    }
    .login-box {
      background:#fff;
      padding:24px 28px;
      border-radius:12px;
      box-shadow:0 8px 30px rgba(0,0,0,.08);
      max-width:360px;
      width:100%;
      box-sizing:border-box;
    }
    h1 {
      margin:0 0 16px;
      font-size:20px;
    }
    label { display:block; margin-bottom:8px; font-size:13px; }
    input[type="text"], input[type="password"] {
      width:100%;
      padding:8px 10px;
      border-radius:8px;
      border:1px solid #ddd;
      margin-bottom:12px;
      box-sizing:border-box;
    }
    button {
      width:100%;
      padding:10px;
      border:none;
      border-radius:8px;
      background:#111;
      color:#fff;
      font-weight:600;
      cursor:pointer;
    }
    button:hover { background:#000; }
    .error {
      color:#b3261e;
      margin-bottom:10px;
      font-size:13px;
    }
    .hint {
      font-size:11px;
      color:#777;
      margin-top:8px;
    }
  </style>
</head>
<body>
  <form class="login-box" method="post" action="login.php">
    <h1>PROMED – Admin</h1>
    <?php if ($error): ?>
      <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <label>Utilisateur
      <input type="text" name="username" autocomplete="username" required>
    </label>
    <label>Mot de passe
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button type="submit">Se connecter</button>
    <div class="hint">Accès réservé à l'équipe PROMED.</div>
  </form>
</body>
</html>
