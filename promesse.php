<?php
require __DIR__ . '/config.php';


// --- Récupération du code unité ---
$unitCode = isset($_GET['unit_code']) ? trim($_GET['unit_code']) : '';
if ($unitCode === '') {
    die("Code d'unité manquant.");
}

// 1) Récupérer l'unité (bloc, étage, type, surface, etc.)
$sqlUnit = "SELECT * FROM units WHERE code = :code LIMIT 1";
$stmt = $pdo->prepare($sqlUnit);
$stmt->execute([':code' => $unitCode]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unité introuvable dans la base.");
}

// 2) Récupérer la dernière vente + client
//    ⚠️ Ici on s'appuie sur ta vraie structure clients : nom, tel, email...
$sqlVente = "
  SELECT 
      v.*,
      c.nom       AS nom_client,
      c.tel       AS tel_client,
      c.email     AS email_client,
      c.date_naissance,
      c.cin,
      c.cin_delivre_le
  FROM ventes v
  JOIN clients c ON c.id = v.client_id
  WHERE v.unit_code = :code
  ORDER BY v.id DESC
  LIMIT 1
";
$stmt = $pdo->prepare($sqlVente);
$stmt->execute([':code' => $unitCode]);
$vente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vente) {
    die("Aucune vente trouvée pour cette unité. Enregistrez d'abord la fiche client.");
}

// --- Préparation des données --- //
$residence = "FLAVA";

$nomClient = $vente['nom_client'] ?? '';
$phone     = $vente['tel_client'] ?? '';
$email     = $vente['email_client'] ?? '';

$bloc      = $unit['block'] ?? '';
$etageId   = $unit['floor'] ?? '';   // ex: "1", "RDC", "MEZZ"
$composition = $unit['composition'] ?? ''; // si tu ajoutes ce champ dans la table units
$surface   = $unit['surface'] ?? '';

$parking   = $vente['parking'] ?? '';  // peut être vide -> champ à compléter à la main
$cellier   = $vente['cellier'] ?? '';

$prixTTC   = $vente['prix_ttc'] ?? 0;  // prix TTC enregistré dans ventes
$prixTTCStr = number_format((float)$prixTTC, 3, ',', ' '); // ex : 2 100 000,000

// étage lisible
function formatFloor($id) {
    switch ($id) {
        case 'RDC':  return 'RDC';
        case 'MEZZ': return 'Mezzanine';
        case '1':    return '1er étage';
        case '2':    return '2ème étage';
        case '3':    return '3ème étage';
        default:     return $id;
    }
}
$etageLabel = formatFloor($etageId);

// utilisateur connecté (élaboré par)
$elaborePar = $_SESSION['username'] ?? '';

// ===========================
//  DOMPDF
// =========================== 

use Dompdf\Dompdf;
use Dompdf\Options;


$dompdf = new Dompdf();

// --- HTML de la promesse --- //
$html = '
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
  body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 12px;
    line-height: 1.5;
  }
  h1, h2, h3 {
    text-align: center;
    margin-bottom: 8px;
  }
  .header-promed {
    text-align: left;
    font-weight: bold;
    margin-bottom: 12px;
  }
  .label {
    font-weight: bold;
  }
  .block {
    margin-bottom: 10px;
  }
  .box {
    border: 1px solid #000;
    padding: 6px 8px;
    margin-bottom: 8px;
  }
  .field {
    margin-bottom: 4px;
  }
  .signature-zone {
    margin-top: 40px;
    display: flex;
    justify-content: space-between;
  }
  .signature-col {
    width: 45%;
    text-align: left;
  }
  .underline {
    border-bottom: 1px solid #000;
    display: inline-block;
    min-width: 200px;
  }
</style>
</head>
<body>

<div class="header-promed">
  PROMED – Résidence : <strong>'.htmlspecialchars($residence, ENT_QUOTES, "UTF-8").'</strong>
</div>

<h2>FICHE CLIENT (promesse de vente)</h2>

<div class="block box">
  <div class="field">
    <span class="label">Nom complet de l’acquéreur : </span>
    '.htmlspecialchars($nomClient, ENT_QUOTES, "UTF-8").'
  </div>
  <div class="field">
    <span class="label">Téléphone : </span>
    '.htmlspecialchars($phone, ENT_QUOTES, "UTF-8").'
  </div>
  <div class="field">
    <span class="label">Email : </span>
    '.htmlspecialchars($email, ENT_QUOTES, "UTF-8").'
  </div>
</div>

<div class="block box">
  <div class="field">
    <span class="label">Objet de la vente : </span>
    Appartement '.htmlspecialchars($unitCode, ENT_QUOTES, "UTF-8").'
  </div>
  <div class="field">
    <span class="label">Bloc : </span>
    '.htmlspecialchars($bloc, ENT_QUOTES, "UTF-8").'
  </div>
  <div class="field">
    <span class="label">Étage : </span>
    '.htmlspecialchars($etageLabel, ENT_QUOTES, "UTF-8").'
  </div>
  <div class="field">
    <span class="label">Composition : </span>
    '.htmlspecialchars($composition ?: "............................................................", ENT_QUOTES, "UTF-8").'
  </div>
  <div class="field">
    <span class="label">Surface : </span>
    '.htmlspecialchars($surface, ENT_QUOTES, "UTF-8").' m²
  </div>
  <div class="field">
    <span class="label">Parking : </span>
    '.htmlspecialchars($parking ?: "..........................", ENT_QUOTES, "UTF-8").'
  </div>
  <div class="field">
    <span class="label">Cellier : </span>
    '.htmlspecialchars($cellier ?: "..........................", ENT_QUOTES, "UTF-8").'
  </div>
</div>

<div class="block box">
  <div class="field">
    <span class="label">Prix de vente TTC : </span>
    '.($prixTTC ? $prixTTCStr." TND" : "......................").'
  </div>
  <div class="field">
    <span class="label">Modalités de paiement : </span><br>
    ............................................................<br>
    ............................................................<br>
    ............................................................<br>
    (à compléter par l\'administration)
  </div>
</div>

<div class="block">
  <div class="field">
    <span class="label">Élaboré par : </span>
    '.htmlspecialchars($elaborePar, ENT_QUOTES, "UTF-8").'
  </div>
</div>

<div class="signature-zone">
  <div class="signature-col">
    <span class="label">Validation :</span><br><br>
    Signature et cachet<br><br>
    _______________________________
  </div>
  <div class="signature-col" style="text-align:right;">
    <span class="label">Signature de l’acquéreur :</span><br><br>
    _______________________________
  </div>
</div>

</body>
</html>
';

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// envoi au navigateur
$filename = "Promesse_".$unitCode.".pdf";
$dompdf->stream($filename, ["Attachment" => false]); // false = ouvre dans le navigateur
exit;
