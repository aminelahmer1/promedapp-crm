<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$search  = trim((string)($_GET['q']       ?? ''));
$status  = trim((string)($_GET['status']  ?? ''));
$project = trim((string)($_GET['project'] ?? ''));
$limit   = 40;
$offset  = (int)($_GET['offset'] ?? 0);

$where  = ['1=1'];
$params = [];
if($search!==''){
    $like="%$search%";
    $where[]='(p.promise_number LIKE ? OR p.unit_code LIKE ? OR c.nom LIKE ? OR c.tel LIKE ?)';
    $params=array_merge($params,[$like,$like,$like,$like]);
}
if($status!==''){  $where[]='p.status=?';           $params[]=$status;  }
if($project!==''){ $where[]='UPPER(pj.code)=UPPER(?)'; $params[]=$project; }
$wSql = implode(' AND ',$where);

try{
    $stmt=$pdo->prepare("
        SELECT p.id,p.promise_number,p.unit_code,p.sale_price,p.advance_amount,p.vat_rate,
               p.status,p.is_locked,p.promise_date,p.created_at,
               c.id AS client_id,c.nom AS client_nom,c.tel AS client_tel,
               pj.code AS project_code,
               ps.paid_amount,ps.remaining_amount,ps.progress_percent,ps.status AS sched_status
        FROM promises p
        LEFT JOIN clients c ON c.id=p.client_id
        LEFT JOIN projects pj ON pj.id=p.project_id
        LEFT JOIN payment_schedules ps ON ps.promise_id=p.id
        WHERE $wSql ORDER BY p.created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $promises=$stmt->fetchAll();

    $cs=$pdo->prepare("SELECT COUNT(*) FROM promises p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN projects pj ON pj.id=p.project_id WHERE $wSql");
    $cs->execute($params);
    $total=(int)$cs->fetchColumn();

    $projects=$pdo->query("SELECT id,code,nom FROM projects ORDER BY code")->fetchAll();
}catch(Throwable $e){ $promises=[];$total=0;$projects=[]; }

$SL=['draft'=>'Brouillon','signed'=>'Signé','converted_to_sale'=>'Converti','cancelled'=>'Annulé'];
$SC=['draft'=>'b-draft','signed'=>'b-signed','converted_to_sale'=>'b-conv','cancelled'=>'b-cancel'];
$DSL=['pending'=>'En attente','in_progress'=>'En cours','paid'=>'Soldé','overdue'=>'Retard'];
$DSC=['pending'=>'b-pend','in_progress'=>'b-prog','paid'=>'b-paid','overdue'=>'b-over'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Promesses – PromedApp</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--bg:#f4f4f4;--paper:#fff;--line:#bdbdbd;--text:#151515;--muted:#5e5e5e;--soft:#efefef;--brand:#145f7d;--brand2:#a56d2d}
  *{box-sizing:border-box} html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif} body{padding:24px}
  .app{display:grid;grid-template-columns:280px 1fr;gap:24px;align-items:start;max-width:1600px;margin:0 auto}
  .panel{background:#fff;border:1px solid #ddd;border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);position:sticky;top:18px;max-height:calc(100vh - 36px);overflow:auto}
  .panel h1{font-size:20px;margin:0 0 4px}
  .panel .sub{color:var(--muted);font-size:13px;margin-bottom:16px}
  .form-section{border-top:1px solid #e5e5e5;padding-top:14px;margin-top:14px}
  .form-section h2{font-size:14px;margin:0 0 10px;text-transform:uppercase;letter-spacing:.4px}
  label{display:block;font-size:12px;font-weight:700;color:#202020;margin-bottom:4px}
  input,select,button{font:inherit}
  input,select{width:100%;border:1px solid #d7d7d7;border-radius:10px;padding:10px 12px;background:#fff;font-size:13px;margin-bottom:8px}
  input:focus,select:focus{outline:none;border-color:var(--brand)}
  .btn{border:0;border-radius:12px;padding:11px 14px;font-weight:700;cursor:pointer;font-size:13px;width:100%;display:block;text-align:center;text-decoration:none;margin-top:4px}
  .btn-brand{background:var(--brand);color:#fff}
  .btn-secondary{background:var(--soft);color:var(--text)}
  .nav-link{display:block;color:var(--brand);font-size:13px;text-decoration:none;padding:6px 0;border-bottom:1px solid #f0f0f0}
  .nav-link:hover{text-decoration:underline}
  .nav-link:last-child{border:none}

  /* Table */
  .table-card{background:#fff;border:1px solid #ddd;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.05);overflow:hidden}
  table{width:100%;border-collapse:collapse}
  thead th{background:#f8f8f8;padding:11px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #ebebeb}
  tbody tr{border-bottom:1px solid #f0f0f0;cursor:pointer;transition:background .1s}
  tbody tr:hover{background:#f5fbfd}
  tbody td{padding:11px 14px;font-size:13px;vertical-align:middle}
  .badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700}
  .b-draft{background:#f0f0f0;color:#555}
  .b-signed{background:#d1fae5;color:#065f46}
  .b-conv{background:#dbeafe;color:#1e40af}
  .b-cancel{background:#fee2e2;color:#991b1b}
  .b-pend{background:#fef3c7;color:#92400e}
  .b-prog{background:#dbeafe;color:#1e40af}
  .b-paid{background:#d1fae5;color:#065f46}
  .b-over{background:#fee2e2;color:#991b1b}
  .prom-num{font-family:monospace;font-size:12px;font-weight:700;color:var(--brand)}
  .prog-wrap{display:flex;align-items:center;gap:6px}
  .prog-bar{width:64px;height:6px;background:#e5e5e5;border-radius:3px;overflow:hidden;flex-shrink:0}
  .prog-fill{height:100%;background:var(--brand);border-radius:3px}
  .amount{font-family:monospace;font-size:12px}
  .pager{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-top:1px solid #f0f0f0;font-size:12px;color:var(--muted)}
  .pager-btns{display:flex;gap:6px}
  .pager-btns a{padding:5px 12px;border:1px solid #d7d7d7;border-radius:8px;background:#fff;color:var(--text);text-decoration:none;font-size:12px;font-weight:700}
  .pager-btns a:hover{background:var(--soft)}
  .pager-btns a.off{opacity:.4;pointer-events:none}
  @media(max-width:1100px){.app{grid-template-columns:1fr}.panel{position:relative;top:auto;max-height:none}}
</style>
</head>
<body>
<div class="app">

  <!-- ══ PANEL GAUCHE ══ -->
  <aside class="panel">
    <h1>Promesses de vente</h1>
    <p class="sub">Suivi des promesses et paiements</p>

    <div class="form-section">
      <h2>Navigation</h2>
      <a class="nav-link" href="clients.php">← Dossiers clients</a>
      <a class="nav-link" href="promise_view.php?new=1">+ Nouvelle promesse</a>
    </div>

    <form method="get" class="form-section">
      <h2>Filtres</h2>
      <div>
        <label>Recherche</label>
        <input type="text" name="q" value="<?=htmlspecialchars($search)?>" placeholder="N° PV, unité, client…">
      </div>
      <div>
        <label>Statut</label>
        <select name="status">
          <option value="">Tous les statuts</option>
          <?php foreach(['draft'=>'Brouillon','signed'=>'Signé','converted_to_sale'=>'Converti en vente','cancelled'=>'Annulé'] as $v=>$l): ?>
          <option value="<?=$v?>" <?=$status===$v?'selected':''?>><?=$l?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Projet</label>
        <select name="project">
          <option value="">Tous projets</option>
          <?php foreach($projects as $pj): ?>
          <option value="<?=htmlspecialchars($pj['code'])?>" <?=strtoupper($project)===strtoupper($pj['code'])?'selected':''?>><?=htmlspecialchars($pj['code'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-brand">Filtrer</button>
      <?php if($search||$status||$project): ?>
      <a href="promises.php" class="btn btn-secondary">Effacer les filtres</a>
      <?php endif; ?>
    </form>

    <div class="form-section">
      <div style="font-size:13px;color:var(--muted)"><?=$total?> résultat<?=$total>1?'s':''?> trouvé<?=$total>1?'s':''?></div>
    </div>
  </aside>

  <!-- ══ CONTENU DROIT ══ -->
  <div>
    <div class="table-card">
      <table>
        <thead>
          <tr>
            <th>N° Promesse</th>
            <th>Unité / Projet</th>
            <th>Client</th>
            <th>Prix TTC</th>
            <th>Encaissé</th>
            <th>Progression</th>
            <th>Statut</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php if(!$promises): ?>
          <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">Aucune promesse trouvée</td></tr>
          <?php else: foreach($promises as $r):
            $ht   = (float)$r['sale_price'];
            $vat  = (float)($r['vat_rate']??19);
            $ttc  = $ht * (1 + $vat/100);
            $prog = (float)($r['progress_percent']??0);
            $date = $r['promise_date'] ?: substr($r['created_at'],0,10);
            $sc   = $SC[$r['status']] ?? 'b-draft';
            $sl   = $SL[$r['status']] ?? $r['status'];
          ?>
          <tr onclick="window.location='promise_view.php?id=<?=(int)$r['id']?>'">
            <td>
              <span class="prom-num"><?=htmlspecialchars($r['promise_number'])?></span>
              <?php if($r['is_locked']): ?><span style="font-size:11px" title="Verrouillé">🔒</span><?php endif; ?>
            </td>
            <td>
              <div style="font-weight:700"><?=htmlspecialchars($r['unit_code'])?></div>
              <div style="font-size:11px;color:var(--muted)"><?=htmlspecialchars($r['project_code']??'')?></div>
            </td>
            <td>
              <div style="font-weight:700"><?=htmlspecialchars($r['client_nom']??'—')?></div>
              <div style="font-size:11px;color:var(--muted)"><?=htmlspecialchars($r['client_tel']??'')?></div>
            </td>
            <td class="amount"><?=$ttc ? number_format($ttc,0,',',' ').' TND' : '—'?></td>
            <td class="amount" style="color:#19733e"><?=$r['paid_amount'] ? number_format((float)$r['paid_amount'],0,',',' ').' TND' : '—'?></td>
            <td>
              <div class="prog-wrap">
                <div class="prog-bar"><div class="prog-fill" style="width:<?=min(100,$prog)?>%"></div></div>
                <span style="font-size:11px;color:var(--muted)"><?=number_format($prog,0)?>%</span>
              </div>
            </td>
            <td>
              <span class="badge <?=$sc?>"><?=htmlspecialchars($sl)?></span>
              <?php if($r['sched_status']): $dc=$DSC[$r['sched_status']]??''; $dl=$DSL[$r['sched_status']]??$r['sched_status']; ?>
              <br><span class="badge <?=$dc?>" style="margin-top:3px"><?=htmlspecialchars($dl)?></span>
              <?php endif; ?>
            </td>
            <td style="color:var(--muted);font-size:12px"><?=htmlspecialchars(substr($date,0,10))?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>

      <?php if($total > $limit || $offset > 0):
        $q2 = http_build_query(array_filter(['q'=>$search,'status'=>$status,'project'=>$project]));
      ?>
      <div class="pager">
        <span>Page <?=floor($offset/$limit)+1?>/<?=max(1,ceil($total/$limit))?> — <?=$total?> résultat<?=$total>1?'s':''?></span>
        <div class="pager-btns">
          <a href="?<?=$q2?>&offset=<?=max(0,$offset-$limit)?>" class="<?=$offset===0?'off':''?>">← Préc.</a>
          <a href="?<?=$q2?>&offset=<?=$offset+$limit?>" class="<?=($offset+$limit>=$total)?'off':''?>">Suiv. →</a>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>
</body>
</html>
