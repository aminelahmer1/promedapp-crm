<?php
/* Navigation commune : accueil du projet, page des projets (en gardant Vente / Location),
   bouton « Retour » qui revient à la page d'avant seulement si elle fait partie du CRM. */

function nav_set_mode(string $mode): void {
    $_SESSION['nav_mode'] = $mode === 'location' ? 'location' : 'vente';
}

function nav_mode(): string {
    return ($_SESSION['nav_mode'] ?? 'vente') === 'location' ? 'location' : 'vente';
}

/** Page des projets, ouverte sur l'onglet Vente ou Location */
function nav_projects_url(?string $mode = null): string {
    return ($mode ?? nav_mode()) === 'location' ? 'select_project.php?mode=location' : 'select_project.php';
}

/** Accueil du projet (AVA, FLAVA, EVOLO) ; sinon la page des projets */
function nav_home_url(?string $code = null): string {
    $c = strtoupper(trim((string)($code ?? ($_SESSION['current_project_code'] ?? ''))));
    switch ($c) {
        case 'AVA':   return 'home_ava.php';
        case 'FLAVA': return 'home_flava.php';
        case 'EVOLO': return 'prix.php?mode=sale&project=EVOLO';
        default:      return nav_projects_url();
    }
}

/** Script du bouton « Retour » : page précédente si elle vient du CRM, sinon $fallback */
function nav_back_script(): string {
    return <<<'JS'
<script>
function navBack(fallback) {
  try {
    const r = document.referrer ? new URL(document.referrer) : null;
    if (r && r.origin === location.origin && r.pathname !== location.pathname && history.length > 1) { history.back(); return false; }
  } catch (e) {}
  location.href = fallback;
  return false;
}
</script>
JS;
}
