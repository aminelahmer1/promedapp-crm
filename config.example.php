<?php
// Copier ce fichier en config.php et remplir les accès à la base.
// Ne jamais mettre config.php sur GitHub.
$host = 'localhost';
$db   = 'promed';
$user = 'CHANGER';
$pass = 'CHANGER';
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
