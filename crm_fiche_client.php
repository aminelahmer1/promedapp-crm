<?php
// DEPRECATED — remplacé par crm_client.php
// Cette page redirige automatiquement vers le profil client unifié.
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$id = (int)($_GET['id'] ?? 0);
header('Location: ' . ($id ? "crm_client.php?id=$id" : 'crm_clients.php'));
exit;

$projects = $pdo->query("SELECT id,code,nom FROM projects ORDER BY code")->fetchAll();
try { $users = $pdo->query("SELECT id,full_name AS nom FROM users ORDER BY full_name")->fetchAll(); }
catch(Throwable $e){ $users = []; }
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Fiche Client CRM – PromedApp</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php include __DIR__.'/includes/crm_style.php'; ?>
<style>
/* Header fiche */
.fh{background:var(--paper);border:1px solid var(--border);border-radius:12px;padding:18px 22px;margin-bottom:14px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.fh-top{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap}
.fh-name{font-size:22px;font-weight:800;margin:0 0 5px}
.fh-meta{display:flex;gap:10px;flex-wrap:wrap;align-items:center;font-size:13px;color:var(--muted);margin-bottom:8px}
.fh-meta a{color:var(--brand);text-decoration:none}
.fh-badges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}
.fh-actions{display:flex;gap:6px;flex-wrap:wrap}
/* Blocks */
.blocks{display:grid;grid-template-columns:1fr 340px;gap:14px;align-items:start}
.bloc{background:var(--paper);border:1px solid var(--border);border-radius:11px;box-shadow:0 2px 8px rgba(0,0,0,.04);margin-bottom:14px;overflow:hidden}
.bloc-head{padding:11px 16px;border-bottom:1px solid #f0f2f5;display:flex;align-items:center;justify-content:space-between}
.bloc-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
.bloc-body{padding:14px 16px}
.fl-row{display:grid;grid-template-columns:140px 1fr;gap:4px 10px;padding:5px 0;border-bottom:1px solid #f5f7fa;align-items:start}
.fl-row:last-child{border:none}
.fl-l{font-size:12px;color:var(--muted);font-weight:600;padding-top:2px}
.fl-v{font-size:13px}
/* Timeline */
.tl{padding:0}
.tl-item{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid #f0f2f5}
.tl-item:last-child{border:none}
.tl-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;background:#edf4f9}
.tl-body{flex:1}
.tl-top{display:flex;align-items:baseline;gap:6px;flex-wrap:wrap}
.tl-type{font-size:10px;font-weight:700;text-transform:uppercase;color:var(--brand)}
.tl-subj{font-size:13px;font-weight:600}
.tl-desc{font-size:12px;color:var(--muted);margin-top:2px}
.tl-meta{font-size:11px;color:var(--muted);margin-top:3px}
/* Unit card */
.uc{border:1px solid var(--border);border-radius:9px;padding:11px 14px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.uc:last-child{margin:0}
.uc.fav{border-color:#a56d2d;background:#fffbf2}
.uc-code{font-weight:700;font-size:14px}
.uc-sub{font-size:11px;color:var(--muted);margin-top:2px}
/* Options checkboxes */
.opts{display:flex;flex-wrap:wrap;gap:6px}
.opt{padding:3px 9px;border-radius:5px;font-size:11px;font-weight:700;background:#e2eef5;color:var(--brand)}
/* Follow-up form */
.fu-form{background:#f5f7fa;border:1px solid var(--border);border-radius:9px;padding:14px;margin-top:10px;display:none}
.fu-form.open{display:block}
/* Sidebar sticky */
.side{position:sticky;top:20px}
/* Edit mode */
.edit-wrap{display:none}.edit-wrap.open{display:block}
.view-wrap.hidden{display:none}
/* Interest colors */
.st-new{background:#f0f0f0;color:#555}
.st-contacted{background:#e0f0ff;color:#145f7d}
.st-interested{background:#fef3c7;color:#92400e}
.st-visit{background:#f0ecfa;color:#5b21b6}
.st-negotiation{background:#fef3c7;color:#b45309}
.st-reserved{background:#d1fae5;color:#065f46}
.st-lost{background:#fee2e2;color:#991b1b}
.st-sold{background:#dbeafe;color:#1e40af}
.act-quick{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
.flash2{padding:8px 12px;border-radius:8px;font-size:13px;font-weight:700;margin-bottom:10px;display:none}
.flash2.ok{background:#e6f5ed;color:#19733e}.flash2.err{background:#fdecea;color:#c03030}
</style>
</head>
<body>
<div class="app">

<!-- ══ PANEL GAUCHE ══ -->
<aside class="panel">
  <div class="panel-title">CRM Promed</div>
  <div class="panel-sub">Fiche prospect</div>
  <div class="nav-section">
    <div class="nav-section-title">Navigation</div>
    <a class="nav-link" href="crm_dashboard.php">⊟ Dashboard</a>
    <a class="nav-link" href="crm_clients.php">← Liste clients</a>
    <a class="nav-link" href="crm_pipeline.php">Pipeline Kanban</a>
    <a class="nav-link" href="crm_dossiers.php">Dossiers</a>
    <a class="nav-link" href="crm_units.php">Biens</a>
  </div>
  <div class="nav-section">
    <div class="nav-section-title">Actions rapides</div>
    <a class="nav-link" href="#" onclick="openFollowup('call')">📞 Appel</a>
    <a class="nav-link" href="#" onclick="openFollowup('whatsapp')">💬 WhatsApp</a>
    <a class="nav-link" href="#" onclick="openFollowup('meeting')">📅 RDV</a>
    <a class="nav-link" href="#" onclick="openFollowup('visit')">🏠 Visite</a>
    <a class="nav-link" href="#" onclick="openFollowup('note')">📝 Note</a>
  </div>
  <div class="nav-section">
    <div class="nav-section-title">Projets</div>
    <?php foreach($projects as $pj): ?>
    <a class="nav-link" href="crm_clients.php?project=<?=urlencode($pj['code'])?>"><?=htmlspecialchars($pj['code'])?></a>
    <?php endforeach; ?>
  </div>
</aside>

<!-- ══ CONTENU ══ -->
<div>
  <div id="topFlash" class="flash2"></div>

  <!-- HEADER ──────────────────────────────────────────── -->
  <div class="fh" id="ficheHeader">
    <div class="fh-top">
      <div>
        <div class="fh-name" id="hName"><span class="spin"></span></div>
        <div class="fh-badges" id="hBadges"></div>
        <div class="fh-meta" id="hMeta"></div>
      </div>
      <div class="fh-actions" id="hActions"></div>
    </div>
  </div>

  <!-- CONTENT GRID ────────────────────────────────────── -->
  <div class="blocks">

    <!-- LEFT MAIN COL -->
    <div>

      <!-- Bloc 1: Info client -->
      <div class="bloc">
        <div class="bloc-head">
          <span class="bloc-title">Informations client</span>
          <button class="btn btn-outline btn-sm" onclick="toggleEdit('infoEdit')">✏ Modifier</button>
        </div>
        <div class="bloc-body">
          <div id="infoView" class="view-wrap"></div>
          <div id="infoEdit" class="edit-wrap">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 14px">
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Prénom</label><input class="inp" id="e_first_name" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Nom</label><input class="inp" id="e_last_name" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Téléphone</label><input class="inp" id="e_tel" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">WhatsApp</label><input class="inp" id="e_whatsapp" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Email</label><input class="inp" id="e_email" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">CIN/Passeport</label><input class="inp" id="e_cin" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Nationalité</label><input class="inp" id="e_nationality" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Profession</label><input class="inp" id="e_profession" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Source</label><input class="inp" id="e_source" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Campagne</label><input class="inp" id="e_campaign" style="width:100%;margin-top:3px"></div>
              <div style="grid-column:1/-1"><label style="font-size:11px;font-weight:700;color:var(--muted)">Adresse</label><input class="inp" id="e_address" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Commercial</label>
                <select class="sel" id="e_assigned_to" style="width:100%;margin-top:3px">
                  <option value="">— Aucun —</option>
                  <?php foreach($users as $u): ?><option value="<?=(int)$u['id']?>"><?=htmlspecialchars($u['nom'])?></option><?php endforeach; ?>
                </select>
              </div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Niveau d'intérêt</label>
                <select class="sel" id="e_interest_level" style="width:100%;margin-top:3px">
                  <option value="low">Froid</option><option value="medium">Moyen</option>
                  <option value="high">Chaud</option><option value="hot">Très chaud</option><option value="vip">VIP</option>
                </select>
              </div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Statut pipeline</label>
                <select class="sel" id="e_crm_status" style="width:100%;margin-top:3px">
                  <option value="new">Nouveau</option><option value="contacted">Contacté</option>
                  <option value="interested">Intéressé</option><option value="visit">Visite</option>
                  <option value="negotiation">Négociation</option><option value="reserved">Réservé</option>
                  <option value="lost">Perdu</option><option value="sold">Vendu</option>
                </select>
              </div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Prochain suivi</label>
                <input type="datetime-local" class="inp" id="e_next_followup_at" style="width:100%;margin-top:3px">
              </div>
            </div>
            <div style="margin-top:10px">
              <label style="font-size:11px;font-weight:700;color:var(--muted)">Motivation</label>
              <textarea class="inp" id="e_motivation" style="width:100%;margin-top:3px;height:60px;resize:vertical"></textarea>
            </div>
            <div style="margin-top:10px">
              <label style="font-size:11px;font-weight:700;color:var(--muted)">Notes internes</label>
              <textarea class="inp" id="e_notes" style="width:100%;margin-top:3px;height:50px;resize:vertical"></textarea>
            </div>
            <div style="display:flex;gap:8px;margin-top:10px">
              <button class="btn btn-brand btn-sm" onclick="saveInfo()">✓ Enregistrer</button>
              <button class="btn btn-outline btn-sm" onclick="toggleEdit('infoEdit')">Annuler</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Bloc 2: Besoins -->
      <div class="bloc">
        <div class="bloc-head">
          <span class="bloc-title">Besoins & recherche</span>
          <button class="btn btn-outline btn-sm" onclick="toggleEdit('needsEdit')">✏ Modifier</button>
        </div>
        <div class="bloc-body">
          <div id="needsView"></div>
          <div id="needsEdit" class="edit-wrap">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 14px">
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Projet souhaité</label>
                <select class="sel" id="n_project_id" style="width:100%;margin-top:3px">
                  <option value="">— Tous —</option>
                  <?php foreach($projects as $pj): ?><option value="<?=(int)$pj['id']?>"><?=htmlspecialchars($pj['code'].' — '.$pj['nom'])?></option><?php endforeach; ?>
                </select>
              </div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Type de bien</label>
                <select class="sel" id="n_property_type" style="width:100%;margin-top:3px">
                  <option value="">— Tous —</option>
                  <option>S1</option><option>S2</option><option>S3</option><option>S4</option>
                  <option>Duplex</option><option>Villa</option><option>Bureau</option>
                </select>
              </div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Surface min (m²)</label><input type="number" class="inp" id="n_surface_min" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Surface max (m²)</label><input type="number" class="inp" id="n_surface_max" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Budget min (TND)</label><input type="number" class="inp" id="n_budget_min" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Budget max (TND)</label><input type="number" class="inp" id="n_budget_max" style="width:100%;margin-top:3px"></div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Délai d'achat</label>
                <select class="sel" id="n_buying_delay" style="width:100%;margin-top:3px">
                  <option value="">—</option><option value="immediate">Immédiat</option>
                  <option value="3months">3 mois</option><option value="6months">6 mois</option>
                  <option value="1year">1 an</option><option value="not_decided">Pas décidé</option>
                </select>
              </div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Financement</label>
                <select class="sel" id="n_financing_mode" style="width:100%;margin-top:3px">
                  <option value="">—</option><option value="cash">Cash</option>
                  <option value="bank_loan">Crédit bancaire</option><option value="both">Les deux</option>
                </select>
              </div>
              <div><label style="font-size:11px;font-weight:700;color:var(--muted)">Étage préféré</label>
                <select class="sel" id="n_preferred_floor" style="width:100%;margin-top:3px">
                  <option value="">—</option><option>RDC</option><option>1er</option>
                  <option>2ème</option><option>3ème</option><option>Haut</option>
                </select>
              </div>
            </div>
            <div style="margin-top:10px">
              <label style="font-size:11px;font-weight:700;color:var(--muted);display:block;margin-bottom:6px">Options souhaitées</label>
              <div class="opts">
                <?php foreach(['parking'=>'Parking','cellier'=>'Cellier','pool'=>'Piscine','independent_kitchen'=>'Cuisine indép.','terrace'=>'Terrasse','garden'=>'Jardin','open_view'=>'Vue dégagée'] as $k=>$l): ?>
                <label style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;padding:4px 8px;background:#f5f7fa;border-radius:5px">
                  <input type="checkbox" id="n_wants_<?=$k?>"> <?=htmlspecialchars($l)?>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:10px">
              <button class="btn btn-brand btn-sm" onclick="saveNeeds()">✓ Enregistrer</button>
              <button class="btn btn-outline btn-sm" onclick="toggleEdit('needsEdit')">Annuler</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Bloc 3: Unités intéressées -->
      <div class="bloc">
        <div class="bloc-head">
          <span class="bloc-title">Unités intéressées</span>
          <button class="btn btn-outline btn-sm" onclick="openAddUnit()">+ Ajouter</button>
        </div>
        <div class="bloc-body" id="unitsBlock"></div>
      </div>

      <!-- Bloc 4: Historique suivis -->
      <div class="bloc">
        <div class="bloc-head">
          <span class="bloc-title">Historique des suivis</span>
          <button class="btn btn-brand btn-sm" onclick="openFollowup('call')">+ Suivi</button>
        </div>
        <div class="bloc-body">
          <!-- Quick actions -->
          <div class="act-quick">
            <button class="btn btn-outline btn-sm" onclick="openFollowup('call')">📞 Appel</button>
            <button class="btn btn-outline btn-sm" onclick="openFollowup('whatsapp')">💬 WhatsApp</button>
            <button class="btn btn-outline btn-sm" onclick="openFollowup('email')">✉ Email</button>
            <button class="btn btn-outline btn-sm" onclick="openFollowup('meeting')">🗓 RDV</button>
            <button class="btn btn-outline btn-sm" onclick="openFollowup('visit')">🏠 Visite</button>
            <button class="btn btn-outline btn-sm" onclick="openFollowup('note')">📝 Note</button>
          </div>
          <!-- Follow-up form -->
          <div class="fu-form" id="fuForm">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px">
              <div>
                <label style="font-size:11px;font-weight:700;color:var(--muted)">Type</label>
                <select class="sel" id="fu_type" style="width:100%;margin-top:2px">
                  <option value="call">📞 Appel</option><option value="whatsapp">💬 WhatsApp</option>
                  <option value="email">✉ Email</option><option value="meeting">🗓 RDV</option>
                  <option value="visit">🏠 Visite</option><option value="note">📝 Note</option>
                  <option value="reminder">🔔 Relance</option><option value="negotiation">🤝 Négociation</option>
                </select>
              </div>
              <div>
                <label style="font-size:11px;font-weight:700;color:var(--muted)">Date & heure</label>
                <input type="datetime-local" class="inp" id="fu_date" style="width:100%;margin-top:2px">
              </div>
              <div>
                <label style="font-size:11px;font-weight:700;color:var(--muted)">Planifier pour</label>
                <input type="datetime-local" class="inp" id="fu_due" style="width:100%;margin-top:2px">
              </div>
            </div>
            <div style="margin-bottom:8px">
              <label style="font-size:11px;font-weight:700;color:var(--muted)">Objet</label>
              <input type="text" class="inp" id="fu_subject" style="width:100%;margin-top:2px" placeholder="Ex: Relance S3 Flava, RDV bureau…">
            </div>
            <div style="margin-bottom:8px">
              <label style="font-size:11px;font-weight:700;color:var(--muted)">Description</label>
              <textarea class="inp" id="fu_desc" style="width:100%;margin-top:2px;height:60px;resize:vertical" placeholder="Résumé de l'échange, réponse client…"></textarea>
            </div>
            <div style="margin-bottom:8px">
              <label style="font-size:11px;font-weight:700;color:var(--muted)">Résultat / Prochaine action</label>
              <input type="text" class="inp" id="fu_result" style="width:100%;margin-top:2px" placeholder="Ex: Client rappelle vendredi, envoi brochure…">
            </div>
            <div style="display:flex;gap:8px">
              <button class="btn btn-brand btn-sm" onclick="saveFollowup()">✓ Enregistrer</button>
              <button class="btn btn-outline btn-sm" onclick="document.getElementById('fuForm').classList.remove('open')">Annuler</button>
            </div>
          </div>
          <div id="activitiesBlock" style="margin-top:10px"></div>
        </div>
      </div>

      <!-- Bloc 5: Commentaires -->
      <div class="bloc">
        <div class="bloc-head">
          <span class="bloc-title">Commentaires commerciaux</span>
          <button class="btn btn-outline btn-sm" onclick="toggleEdit('commEdit')">✏ Modifier</button>
        </div>
        <div class="bloc-body">
          <div id="commView"></div>
          <div id="commEdit" class="edit-wrap">
            <?php foreach(['motivation'=>'Motivation client','notes'=>'Notes internes','private_notes'=>'Notes privées (direction)'] as $k=>$l): ?>
            <div style="margin-bottom:10px">
              <label style="font-size:11px;font-weight:700;color:var(--muted)"><?=htmlspecialchars($l)?></label>
              <textarea class="inp" id="c_<?=$k?>" style="width:100%;margin-top:3px;height:60px;resize:vertical"></textarea>
            </div>
            <?php endforeach; ?>
            <div style="display:flex;gap:8px;margin-top:8px">
              <button class="btn btn-brand btn-sm" onclick="saveComments()">✓ Enregistrer</button>
              <button class="btn btn-outline btn-sm" onclick="toggleEdit('commEdit')">Annuler</button>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /main col -->

    <!-- RIGHT SIDEBAR ───────────────────────────────────── -->
    <div class="side">

      <!-- Statut rapide -->
      <div class="bloc">
        <div class="bloc-head"><span class="bloc-title">Statut pipeline</span></div>
        <div class="bloc-body" style="display:flex;flex-direction:column;gap:6px" id="statusButtons"></div>
      </div>

      <!-- Dossiers -->
      <div class="bloc">
        <div class="bloc-head"><span class="bloc-title">Dossiers CRM</span>
          <a href="crm_dossiers.php?new=1&client_id=<?=$id?>" class="btn btn-outline btn-sm">+ Nouveau</a>
        </div>
        <div class="bloc-body" id="dossiersBlock"></div>
      </div>

      <!-- Visites -->
      <div class="bloc">
        <div class="bloc-head"><span class="bloc-title">Visites planifiées</span></div>
        <div class="bloc-body" id="visitsBlock"></div>
      </div>

    </div>
  </div>
</div>
</div>

<!-- ══ MODAL AJOUT UNITÉ ══ -->
<div id="unitModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:14px;padding:26px;width:440px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.2)">
    <h3 style="margin:0 0 16px;font-size:16px;color:var(--brand)">Ajouter une unité intéressée</h3>
    <label style="font-size:12px;font-weight:700;color:var(--muted)">Projet</label>
    <select class="sel" id="mu_project" style="width:100%;margin:4px 0 10px">
      <?php foreach($projects as $pj): ?><option value="<?=(int)$pj['id']?>"><?=htmlspecialchars($pj['code'])?></option><?php endforeach; ?>
    </select>
    <label style="font-size:12px;font-weight:700;color:var(--muted)">Code unité</label>
    <input type="text" class="inp" id="mu_code" style="width:100%;margin:4px 0 10px" placeholder="Ex: S3-A-201">
    <label style="font-size:12px;font-weight:700;color:var(--muted)">Niveau d'intérêt</label>
    <select class="sel" id="mu_interest" style="width:100%;margin:4px 0 10px">
      <option value="low">Froid</option><option value="medium" selected>Moyen</option>
      <option value="high">Chaud</option><option value="hot">Très chaud</option><option value="vip">VIP</option>
    </select>
    <label style="font-size:12px;font-weight:700;color:var(--muted)">Favori</label>
    <input type="checkbox" id="mu_fav" style="margin:4px 0 10px 6px">
    <div style="display:flex;gap:8px;margin-top:10px">
      <button class="btn btn-brand" style="flex:1" onclick="saveUnit()">✓ Enregistrer</button>
      <button class="btn btn-outline" style="flex:1" onclick="document.getElementById('unitModal').style.display='none'">Annuler</button>
    </div>
  </div>
</div>

<script>
<?php include __DIR__.'/includes/crm_js_helpers.php'; ?>

const CLIENT_ID = <?=$id?>;
let _data = {};

const STS = {new:'Nouveau',contacted:'Contacté',interested:'Intéressé',visit:'Visite',negotiation:'Négociation',reserved:'Réservé',lost:'Perdu',sold:'Vendu'};
const ICONS = {call:'📞',whatsapp:'💬',email:'✉',meeting:'🤝',visit:'🏠',note:'📝',task:'✅',reminder:'🔔',negotiation:'🤝',brochure:'📄',plan:'🗺'};
const ACTLBL = {call:'Appel',whatsapp:'WhatsApp',email:'Email',meeting:'RDV',visit:'Visite',note:'Note',task:'Tâche',reminder:'Relance',negotiation:'Négociation',brochure:'Brochure',plan:'Plan'};
const DELAYL = {immediate:'Immédiat','3months':'3 mois','6months':'6 mois','1year':'1 an',not_decided:'Pas décidé'};
const FINL   = {cash:'Cash',bank_loan:'Crédit bancaire',both:'Cash + Crédit'};

// ── Load data ──────────────────────────────────────────────
async function load() {
  const r = await fetch('api/crm_client_get.php?id='+CLIENT_ID).then(r=>r.json());
  if (r.error) { document.getElementById('hName').textContent = r.error; return; }
  _data = r;
  renderHeader(r.client);
  renderInfo(r.client);
  renderNeeds(r.needs);
  renderUnits(r.units);
  renderActivities(r.activities);
  renderDossiers(r.dossiers);
  renderVisits(r.visits);
  renderComments(r.client);
  renderStatusButtons(r.client.crm_status || 'new');
  hydrateInfoForm(r.client);
  hydrateNeedsForm(r.needs);
  hydrateCommentForm(r.client);
}

// ── Header ────────────────────────────────────────────────
function renderHeader(c) {
  document.getElementById('hName').textContent = c.nom || '—';
  document.title = (c.nom || 'Client') + ' – CRM Promed';
  document.getElementById('hBadges').innerHTML =
    (c.crm_status ? `<span class="badge st-${esc(c.crm_status)}">${esc(STS[c.crm_status]||c.crm_status)}</span>` : '') +
    pbadge(c.interest_level);
  const lc = c.last_contact_at ? ago(c.last_contact_at) : '—';
  const nf = c.next_followup_at ? c.next_followup_at.substring(0,10) : '—';
  document.getElementById('hMeta').innerHTML =
    (c.tel ? `<span>📞 <a href="tel:${esc(c.tel)}">${esc(c.tel)}</a></span>` : '') +
    (c.whatsapp ? `<span>💬 ${esc(c.whatsapp)}</span>` : '') +
    (c.email ? `<span>✉ <a href="mailto:${esc(c.email)}">${esc(c.email)}</a></span>` : '') +
    `<span>👤 ${esc(c.commercial_nom||'—')}</span>` +
    `<span>Dernier contact : ${lc}</span>` +
    (c.next_followup_at ? `<span style="color:var(--warn)">🔔 Suivi : ${nf}</span>` : '');
  document.getElementById('hActions').innerHTML = `
    <a href="tel:${esc(c.tel||'')}" class="btn btn-outline btn-sm">📞 Appeler</a>
    ${c.whatsapp||c.tel?`<a href="https://wa.me/${esc((c.whatsapp||c.tel).replace(/\D/g,''))}" target="_blank" class="btn btn-outline btn-sm">💬 WhatsApp</a>`:''}
    <button class="btn btn-outline btn-sm" onclick="openFollowup('meeting')">📅 RDV</button>
    <a href="crm_dossiers.php?new=1&client_id=${CLIENT_ID}" class="btn btn-brand btn-sm">+ Dossier</a>`;
}

// ── Info view ──────────────────────────────────────────────
function renderInfo(c) {
  const fields = [
    ['Nom complet', c.nom],['Prénom', c.first_name],['Nom', c.last_name],
    ['Téléphone', c.tel],['WhatsApp', c.whatsapp],['Email', c.email],
    ['CIN/Passeport', c.cin],['Nationalité', c.nationality],
    ['Profession', c.profession],['Adresse', c.address],
    ['Source', c.source],['Campagne', c.campaign],
    ['Commercial', c.commercial_nom],
    ['Créé le', (c.created_at||'').substring(0,10)],
  ];
  document.getElementById('infoView').innerHTML = fields.filter(([,v])=>v).map(([l,v])=>
    `<div class="fl-row"><div class="fl-l">${esc(l)}</div><div class="fl-v">${esc(v)}</div></div>`
  ).join('') || '<div style="color:var(--muted);font-size:13px">Aucune information renseignée.</div>';
}

// ── Needs view ────────────────────────────────────────────
function renderNeeds(n) {
  if (!n || !Object.keys(n).length) {
    document.getElementById('needsView').innerHTML = '<div style="color:var(--muted);font-size:13px">Aucun besoin renseigné.</div>';
    return;
  }
  const opts = [];
  if (n.wants_parking)             opts.push('Parking');
  if (n.wants_cellier)             opts.push('Cellier');
  if (n.wants_pool)                opts.push('Piscine');
  if (n.wants_independent_kitchen) opts.push('Cuisine indép.');
  if (n.wants_terrace)             opts.push('Terrasse');
  if (n.wants_garden)              opts.push('Jardin');
  if (n.wants_open_view)           opts.push('Vue dégagée');
  const fields = [
    ['Projet souhaité', n.project_code],
    ['Type de bien', n.property_type],
    ['Surface', n.surface_min||n.surface_max ? `${n.surface_min||'?'} – ${n.surface_max||'?'} m²` : null],
    ['Budget', n.budget_min||n.budget_max ? `${fmtK(n.budget_min)} – ${fmtK(n.budget_max)}` : null],
    ['Délai', DELAYL[n.buying_delay]||n.buying_delay],
    ['Financement', FINL[n.financing_mode]||n.financing_mode],
    ['Étage', n.preferred_floor],
  ];
  document.getElementById('needsView').innerHTML =
    fields.filter(([,v])=>v).map(([l,v])=>
      `<div class="fl-row"><div class="fl-l">${esc(l)}</div><div class="fl-v">${esc(v)}</div></div>`
    ).join('') +
    (opts.length ? `<div class="fl-row"><div class="fl-l">Options</div><div class="fl-v"><div class="opts">${opts.map(o=>`<span class="opt">${esc(o)}</span>`).join('')}</div></div></div>` : '');
}

// ── Units ─────────────────────────────────────────────────
function renderUnits(units) {
  if (!units||!units.length) {
    document.getElementById('unitsBlock').innerHTML = '<div style="color:var(--muted);font-size:13px">Aucune unité liée. Cliquez + Ajouter.</div>';
    return;
  }
  document.getElementById('unitsBlock').innerHTML = units.map(u => `
    <div class="uc${u.is_favorite=='1'?' fav':''}">
      <div>
        <div class="uc-code">${esc(u.unit_code)}</div>
        <div class="uc-sub">${esc(u.project_code||'')} ${u.is_favorite=='1'?'⭐':''}</div>
      </div>
      <div style="display:flex;gap:5px;align-items:center">
        ${pbadge(u.interest_level)}
        <a href="crm_units.php?q=${esc(u.unit_code)}" class="btn btn-outline btn-sm">👁</a>
      </div>
    </div>`).join('');
}

// ── Activities ────────────────────────────────────────────
function renderActivities(acts) {
  if (!acts||!acts.length) {
    document.getElementById('activitiesBlock').innerHTML = '<div style="color:var(--muted);font-size:13px">Aucun suivi enregistré.</div>';
    return;
  }
  document.getElementById('activitiesBlock').innerHTML = `<div class="tl">${acts.slice(0,40).map(a => {
    const dt = (a.activity_date||a.created_at||'').substring(0,16).replace('T',' ');
    return `<div class="tl-item">
      <div class="tl-icon">${ICONS[a.activity_type]||'📌'}</div>
      <div class="tl-body">
        <div class="tl-top">
          <span class="tl-type">${esc(ACTLBL[a.activity_type]||a.activity_type)}</span>
          ${a.subject?`<span class="tl-subj">${esc(a.subject)}</span>`:''}
        </div>
        ${a.description?`<div class="tl-desc">${esc(a.description)}</div>`:''}
        ${a.result?`<div class="tl-desc" style="color:var(--ok)">→ ${esc(a.result)}</div>`:''}
        <div class="tl-meta">${esc(dt)} — ${esc(a.user_nom||'?')}${a.dossier_number?' · '+esc(a.dossier_number):''}</div>
      </div>
    </div>`;
  }).join('')}</div>`;
}

// ── Dossiers ──────────────────────────────────────────────
function renderDossiers(dos) {
  if (!dos||!dos.length) {
    document.getElementById('dossiersBlock').innerHTML = '<div style="color:var(--muted);font-size:12px">Aucun dossier.</div>';
    return;
  }
  document.getElementById('dossiersBlock').innerHTML = dos.map(d=>
    `<a href="crm_dossier.php?id=${d.id}" class="al-row" style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f0f2f5;text-decoration:none;color:inherit">
      <div>
        <div style="font-size:12px;font-weight:700;color:var(--brand)">${esc(d.dossier_number)}</div>
        <div style="font-size:11px;color:var(--muted)">${esc(d.project_code||'')} ${esc(d.unit_code||'')}</div>
      </div>
      ${sbadge(d.status)}
    </a>`
  ).join('');
}

// ── Visits ────────────────────────────────────────────────
function renderVisits(vis) {
  if (!vis||!vis.length) {
    document.getElementById('visitsBlock').innerHTML = '<div style="color:var(--muted);font-size:12px">Aucune visite.</div>';
    return;
  }
  const VSTL = {planned:'Planifiée',done:'Effectuée',cancelled:'Annulée',no_show:'Non venu'};
  document.getElementById('visitsBlock').innerHTML = vis.map(v=>
    `<div style="padding:8px 0;border-bottom:1px solid #f0f2f5">
      <div style="font-size:12px;font-weight:700">${esc(v.visit_date)} ${v.visit_time?esc(v.visit_time.substring(0,5)):''}</div>
      <div style="font-size:11px;color:var(--muted)">${esc(v.project_code||'')} ${esc(v.unit_code||'')} · ${esc(VSTL[v.status]||v.status)}</div>
    </div>`
  ).join('');
}

// ── Comments ──────────────────────────────────────────────
function renderComments(c) {
  const items = [['Motivation', c.motivation],['Notes', c.notes],['Notes privées', c.private_notes]];
  document.getElementById('commView').innerHTML = items.filter(([,v])=>v).map(([l,v])=>
    `<div class="fl-row"><div class="fl-l">${esc(l)}</div><div class="fl-v" style="font-size:12px;white-space:pre-wrap">${esc(v)}</div></div>`
  ).join('') || '<div style="color:var(--muted);font-size:13px">Aucun commentaire.</div>';
}

// ── Status buttons ────────────────────────────────────────
function renderStatusButtons(current) {
  const statuses = ['new','contacted','interested','visit','negotiation','reserved','lost'];
  document.getElementById('statusButtons').innerHTML = statuses.map(s =>
    `<button class="btn btn-sm ${s===current?'btn-brand':'btn-outline'}"
        style="text-align:left;${s==='lost'?'color:var(--err);border-color:var(--err);':''}"
        onclick="setStatus('${s}')">${esc(STS[s])}</button>`
  ).join('');
}

async function setStatus(s) {
  if (s==='lost') {
    const r = prompt('Raison de perte :');
    if (r===null) return;
    const res = await fetch('api/crm_client_status.php',{method:'POST',body:JSON.stringify({id:CLIENT_ID,status:s,lost_reason:r})});
    await res.json();
  } else {
    await fetch('api/crm_client_status.php',{method:'POST',body:JSON.stringify({id:CLIENT_ID,status:s})});
  }
  load();
}

// ── Hydrate forms ─────────────────────────────────────────
function hydrateInfoForm(c) {
  const map = {first_name:'e_first_name',last_name:'e_last_name',tel:'e_tel',whatsapp:'e_whatsapp',
               email:'e_email',cin:'e_cin',nationality:'e_nationality',profession:'e_profession',
               source:'e_source',campaign:'e_campaign',address:'e_address'};
  for (const [k,id] of Object.entries(map)) {
    const el = document.getElementById(id); if(el) el.value = c[k]||'';
  }
  const int = document.getElementById('e_interest_level'); if(int) int.value = c.interest_level||'medium';
  const st  = document.getElementById('e_crm_status');    if(st)  st.value  = c.crm_status||'new';
  const as  = document.getElementById('e_assigned_to');   if(as)  as.value  = c.assigned_to||'';
  const nf  = document.getElementById('e_next_followup_at');
  if(nf && c.next_followup_at) nf.value = c.next_followup_at.substring(0,16);
  const mot = document.getElementById('e_motivation'); if(mot) mot.value = c.motivation||'';
  const nt  = document.getElementById('e_notes');      if(nt)  nt.value  = c.notes||'';
}

function hydrateNeedsForm(n) {
  if (!n) return;
  const map = {project_id:'n_project_id',property_type:'n_property_type',surface_min:'n_surface_min',
               surface_max:'n_surface_max',budget_min:'n_budget_min',budget_max:'n_budget_max',
               preferred_floor:'n_preferred_floor'};
  for (const [k,id] of Object.entries(map)) {
    const el = document.getElementById(id); if(el) el.value = n[k]||'';
  }
  const dl = document.getElementById('n_buying_delay');    if(dl) dl.value = n.buying_delay||'';
  const fm = document.getElementById('n_financing_mode');  if(fm) fm.value = n.financing_mode||'';
  const opts = ['parking','cellier','pool','independent_kitchen','terrace','garden','open_view'];
  opts.forEach(o => { const el=document.getElementById('n_wants_'+o); if(el) el.checked = n['wants_'+o]=='1'||n['wants_'+o]===1; });
}

function hydrateCommentForm(c) {
  ['motivation','notes','private_notes'].forEach(k => {
    const el = document.getElementById('c_'+k); if(el) el.value = c[k]||'';
  });
}

// ── Save info ─────────────────────────────────────────────
async function saveInfo() {
  const body = {id: CLIENT_ID,
    first_name:       document.getElementById('e_first_name').value.trim(),
    last_name:        document.getElementById('e_last_name').value.trim(),
    tel:              document.getElementById('e_tel').value.trim(),
    whatsapp:         document.getElementById('e_whatsapp').value.trim(),
    email:            document.getElementById('e_email').value.trim(),
    cin:              document.getElementById('e_cin').value.trim(),
    nationality:      document.getElementById('e_nationality').value.trim(),
    profession:       document.getElementById('e_profession').value.trim(),
    source:           document.getElementById('e_source').value.trim(),
    campaign:         document.getElementById('e_campaign').value.trim(),
    address:          document.getElementById('e_address').value.trim(),
    assigned_to:      document.getElementById('e_assigned_to').value||null,
    interest_level:   document.getElementById('e_interest_level').value,
    crm_status:       document.getElementById('e_crm_status').value,
    next_followup_at: document.getElementById('e_next_followup_at').value||null,
    motivation:       document.getElementById('e_motivation').value.trim(),
    notes:            document.getElementById('e_notes').value.trim(),
  };
  const nom = [body.first_name, body.last_name].filter(Boolean).join(' ');
  if (nom) body.nom = nom;
  const r = await fetch('api/crm_client_save.php',{method:'POST',body:JSON.stringify(body)}).then(r=>r.json());
  if (r.error) { flash(r.error,'err'); return; }
  flash('Informations enregistrées','ok');
  toggleEdit('infoEdit');
  load();
}

async function saveNeeds() {
  const opts = ['parking','cellier','pool','independent_kitchen','terrace','garden','open_view'];
  const needs = {
    project_id:    document.getElementById('n_project_id').value||null,
    property_type: document.getElementById('n_property_type').value||null,
    surface_min:   parseFloat(document.getElementById('n_surface_min').value)||null,
    surface_max:   parseFloat(document.getElementById('n_surface_max').value)||null,
    budget_min:    parseFloat(document.getElementById('n_budget_min').value)||null,
    budget_max:    parseFloat(document.getElementById('n_budget_max').value)||null,
    buying_delay:  document.getElementById('n_buying_delay').value||null,
    financing_mode:document.getElementById('n_financing_mode').value||null,
    preferred_floor:document.getElementById('n_preferred_floor').value||null,
  };
  opts.forEach(o => needs['wants_'+o] = document.getElementById('n_wants_'+o)?.checked ? 1 : 0);
  const r = await fetch('api/crm_client_save.php',{method:'POST',body:JSON.stringify({id:CLIENT_ID,needs})}).then(r=>r.json());
  if (r.error) { flash(r.error,'err'); return; }
  flash('Besoins enregistrés','ok');
  toggleEdit('needsEdit');
  load();
}

async function saveComments() {
  const body = {id: CLIENT_ID,
    motivation:    document.getElementById('c_motivation').value.trim(),
    notes:         document.getElementById('c_notes').value.trim(),
    private_notes: document.getElementById('c_private_notes').value.trim(),
  };
  const r = await fetch('api/crm_client_save.php',{method:'POST',body:JSON.stringify(body)}).then(r=>r.json());
  if (r.error) { flash(r.error,'err'); return; }
  flash('Commentaires enregistrés','ok');
  toggleEdit('commEdit');
  load();
}

// ── Follow-up ─────────────────────────────────────────────
function openFollowup(type) {
  document.getElementById('fu_type').value = type;
  const now = new Date();
  const pad = n=>String(n).padStart(2,'0');
  const local = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
  const d = document.getElementById('fu_date'); if(d&&!d.value) d.value=local;
  document.getElementById('fuForm').classList.add('open');
  document.getElementById('fu_subject').focus();
}

async function saveFollowup() {
  const subj = document.getElementById('fu_subject').value.trim();
  const desc = document.getElementById('fu_desc').value.trim();
  if (!subj && !desc) { alert('Objet ou description obligatoire'); return; }
  const body = {
    client_id:     CLIENT_ID,
    activity_type: document.getElementById('fu_type').value,
    subject:       subj, description: desc,
    result:        document.getElementById('fu_result').value.trim(),
    followup_date: document.getElementById('fu_date').value||null,
    due_date:      document.getElementById('fu_due').value||null,
  };
  const r = await fetch('api/crm_followup_save.php',{method:'POST',body:JSON.stringify(body)}).then(r=>r.json());
  if (r.error) { flash(r.error,'err'); return; }
  flash('Suivi enregistré','ok');
  document.getElementById('fu_subject').value='';
  document.getElementById('fu_desc').value='';
  document.getElementById('fu_result').value='';
  document.getElementById('fu_due').value='';
  document.getElementById('fuForm').classList.remove('open');
  load();
}

// ── Unit modal ────────────────────────────────────────────
function openAddUnit() {
  document.getElementById('mu_code').value='';
  document.getElementById('unitModal').style.display='flex';
}
async function saveUnit() {
  const code = document.getElementById('mu_code').value.trim();
  if (!code) { alert('Code unité obligatoire'); return; }
  const r = await fetch('api/crm_client_unit_add.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      client_id:      CLIENT_ID,
      project_id:     document.getElementById('mu_project').value||null,
      unit_code:      code,
      interest_level: document.getElementById('mu_interest').value,
      is_favorite:    document.getElementById('mu_fav').checked ? 1 : 0,
    })
  }).then(r=>r.json());
  if (r.error) { flash(r.error,'err'); return; }
  document.getElementById('unitModal').style.display='none';
  flash('Unité ajoutée','ok');
  load();
}

// ── Helpers ───────────────────────────────────────────────
function toggleEdit(id) {
  const el = document.getElementById(id);
  el.classList.toggle('open');
  const view = document.getElementById(id.replace('Edit','View'));
  if (view) view.classList.toggle('hidden');
}

function flash(msg, type) {
  const el = document.getElementById('topFlash');
  el.textContent = msg; el.className = 'flash2 '+type; el.style.display='block';
  setTimeout(()=>{ el.style.display='none'; }, 4000);
}

load();
</script>
</body></html>
