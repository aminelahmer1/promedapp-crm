<style>
:root{
  --bg:#f4f6f8;--paper:#fff;--brand:#145f7d;--gold:#a56d2d;
  --text:#151515;--muted:#5e6878;--border:#dde3ea;
  --ok:#19733e;--err:#c03030;--warn:#d97000;
  --ok-lt:#e6f5ed;--err-lt:#fdecea;--warn-lt:#fef3e0;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font-family:'Segoe UI',Arial,Helvetica,sans-serif;font-size:14px}
body{padding:20px}

.app{display:grid;grid-template-columns:250px 1fr;gap:20px;align-items:start;max-width:1600px;margin:0 auto}

/* PANEL */
.panel{background:var(--paper);border:1px solid var(--border);border-radius:14px;padding:16px;box-shadow:0 4px 20px rgba(0,0,0,.05);position:sticky;top:20px;max-height:calc(100vh - 40px);overflow-y:auto}
.panel-title{font-size:17px;font-weight:800;margin-bottom:2px}
.panel-sub{font-size:12px;color:var(--muted);margin-bottom:4px}
.nav-section{border-top:1px solid #eee;padding-top:10px;margin-top:10px}
.nav-section-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:5px;padding:0 4px}
.nav-link{display:block;padding:6px 8px;border-radius:7px;font-size:13px;color:var(--brand);text-decoration:none;transition:background .1s}
.nav-link:hover{background:#edf4f9}
.nav-link.active{background:#e2eef5;font-weight:700}

/* KPI */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:11px;margin-bottom:14px}
.kpi{background:var(--paper);border:1px solid var(--border);border-radius:11px;padding:13px 15px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.kval{font-size:24px;font-weight:800;color:var(--brand);line-height:1;margin-bottom:3px}
.kval.ok{color:var(--ok)}.kval.warn{color:var(--err)}.kval.gold{color:var(--gold)}
.klbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.ksub{font-size:11px;color:var(--muted);margin-top:3px}

/* PIPELINE */
.pipe-box{background:var(--paper);border:1px solid var(--border);border-radius:11px;padding:14px;box-shadow:0 2px 8px rgba(0,0,0,.04);margin-bottom:14px}
.pipe-box h3{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:10px}
.pipe-row{display:flex;gap:7px;flex-wrap:wrap}
.pipe-step{flex:1;min-width:82px;text-align:center;padding:10px 5px;border-radius:9px;cursor:pointer;transition:transform .12s,box-shadow .12s;border:1.5px solid transparent}
.pipe-step:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.1)}
.ps-n{font-size:21px;font-weight:800;margin-bottom:2px}
.ps-l{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.ps-interested{background:#fef3c7;color:#92400e}
.ps-reserved{background:#ede9fe;color:#5b21b6}
.ps-draft{background:#f0f0f0;color:#555}
.ps-signed{background:#d1fae5;color:#065f46}
.ps-sold{background:#dbeafe;color:#1e40af}
.ps-progress{background:#cffafe;color:#155e75}
.ps-paid{background:#d1fae5;color:#065f46}
.ps-closed{background:#e5e7eb;color:#374151}
.ps-cancelled{background:#fee2e2;color:#991b1b}

/* CARD */
.card{background:var(--paper);border:1px solid var(--border);border-radius:11px;box-shadow:0 2px 8px rgba(0,0,0,.04);overflow:hidden;margin-bottom:14px}
.card-head{padding:12px 15px;border-bottom:1px solid #f0f2f5;display:flex;align-items:center;justify-content:space-between}
.card-head h3{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:0;color:var(--muted)}
.card-head a,.card-head button{font-size:12px;color:var(--brand);text-decoration:none;background:none;border:none;cursor:pointer;font-family:inherit}
.card-head a:hover{text-decoration:underline}

/* GRIDS */
.row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
.row-3{display:grid;grid-template-columns:repeat(3,1fr);gap:11px;margin-bottom:14px}
.row-4{display:grid;grid-template-columns:repeat(4,1fr);gap:11px;margin-bottom:14px}

/* TABLE */
table{width:100%;border-collapse:collapse}
thead th{background:#f5f7fa;padding:8px 11px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f2f5;cursor:pointer;transition:background .1s}
tbody tr:last-child{border:none}
tbody tr:hover{background:#f0f6fb}
tbody td{padding:8px 11px;font-size:12.5px;vertical-align:middle}
.tnum{font-family:'Courier New',monospace;font-size:11.5px;font-weight:700;color:var(--brand)}
.t-muted{color:var(--muted);font-size:12px}

/* BADGES STATUT */
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}
.bs-interested{background:#fef3c7;color:#92400e}
.bs-reserved{background:#ede9fe;color:#5b21b6}
.bs-promise_draft{background:#f0f0f0;color:#555}
.bs-promise_signed{background:#d1fae5;color:#065f46}
.bs-sold{background:#dbeafe;color:#1e40af}
.bs-payment_in_progress{background:#cffafe;color:#155e75}
.bs-paid{background:#d1fae5;color:#065f46}
.bs-closed{background:#e5e7eb;color:#374151}
.bs-cancelled{background:#fee2e2;color:#991b1b}
/* kanban */
.bs-new{background:#f0f0f0;color:#555}
.bs-contacted{background:#e0f0ff;color:#145f7d}
.bs-visit{background:#fef9ec;color:#a56d2d}
.bs-negotiation{background:#fef3c7;color:#92400e}
.bs-lost{background:#fee2e2;color:#991b1b}

/* PRIORITÉ */
.pri{display:inline-block;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}
.pri-low{background:#f0f0f0;color:#5a6070}
.pri-medium{background:#e2eef5;color:#145f7d}
.pri-high{background:#fef3c7;color:#92400e}
.pri-hot{background:#fee2e2;color:#991b1b}
.pri-vip{background:#fef9ec;color:#a56d2d;border:1px solid #f0d090}

/* BOUTONS */
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;border:1px solid transparent;cursor:pointer;font-family:inherit;transition:opacity .12s,background .12s}
.btn:hover{opacity:.88}
.btn-brand{background:var(--brand);color:#fff;border-color:var(--brand)}
.btn-outline{background:#fff;color:var(--brand);border-color:var(--border)}
.btn-outline:hover{background:#edf4f9}
.btn-sm{padding:5px 10px;font-size:12px}
.btn-danger{background:var(--err);color:#fff;border-color:var(--err)}

/* FORMULAIRES */
.inp{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;color:var(--text);background:#fff;outline:none;transition:border-color .15s;font-family:inherit}
.inp:focus{border-color:var(--brand)}
.sel{padding:7px 10px;border:1px solid var(--border);border-radius:8px;font-size:12.5px;color:var(--text);background:#fff;font-family:inherit}
.form-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}

/* ALERTES */
.al-row{display:flex;align-items:center;justify-content:space-between;padding:9px 15px;border-bottom:1px solid #f0f2f5;text-decoration:none;color:inherit;transition:background .1s}
.al-row:last-child{border:none}
.al-row:hover{background:#fafafa}
.al-left{display:flex;align-items:center;gap:8px}
.al-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}
.al-red{background:var(--err)}.al-orange{background:var(--warn)}.al-green{background:var(--ok)}
.al-text{font-size:12.5px}
.al-n{font-size:14px;font-weight:800}
.al-n.red{color:var(--err)}.al-n.ok{color:var(--ok)}

/* TODO */
.td-counts{display:grid;grid-template-columns:repeat(2,1fr);gap:7px;padding:11px 14px 9px}
.tdc{background:#f5f7fa;border-radius:8px;padding:8px 11px}
.tdc-n{font-size:18px;font-weight:800}
.tdc-l{font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-top:1px}
.td-list{padding:0 14px 11px}
.td-item{display:flex;align-items:flex-start;gap:7px;padding:7px 0;border-bottom:1px solid #f0f2f5}
.td-item:last-child{border:none}
.td-tag{font-size:10px;font-weight:700;text-transform:uppercase;padding:2px 6px;border-radius:4px;flex-shrink:0;margin-top:1px}
.tt-call{background:#e2eef5;color:#145f7d}
.tt-whatsapp{background:#dcf5e4;color:#19733e}
.tt-meeting{background:#f0ecfa;color:#5b21b6}
.tt-email{background:#fef3c7;color:#92400e}
.tt-task{background:#f0f0f0;color:#555}
.td-name{font-size:12.5px;font-weight:600}
.td-sub{font-size:11px;color:var(--muted);margin-top:1px}

/* SPINNER + EMPTY */
.spin{display:inline-block;width:15px;height:15px;border:2px solid #ddd;border-top-color:var(--brand);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle}
@keyframes spin{to{transform:rotate(360deg)}}
.empty{padding:22px;text-align:center;color:var(--muted);font-size:13px}

/* BADGE COUNT */
.cnt-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:16px;padding:0 5px;border-radius:8px;font-size:10px;font-weight:800;margin-left:5px;vertical-align:middle}
.cb-warn{background:var(--err);color:#fff}
.cb-ok{background:var(--ok);color:#fff}
.cb-grey{background:#ddd;color:#555}

/* KANBAN */
.kanban{display:flex;gap:12px;overflow-x:auto;padding-bottom:8px;align-items:flex-start}
.kb-col{background:#f5f7fa;border:1px solid var(--border);border-radius:11px;min-width:195px;max-width:220px;flex-shrink:0}
.kb-head{padding:10px 13px;border-bottom:1px solid var(--border);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;justify-content:space-between}
.kb-n{font-size:16px;font-weight:800;margin-left:6px}
.kb-body{padding:8px;min-height:120px}
.kb-card{background:var(--paper);border:1px solid var(--border);border-radius:8px;padding:10px 11px;margin-bottom:7px;cursor:grab;transition:box-shadow .12s;font-size:12.5px}
.kb-card:last-child{margin:0}
.kb-card:hover{box-shadow:0 3px 10px rgba(0,0,0,.1)}
.kb-card.dragging{opacity:.5}
.kb-col.drag-over{background:#e8f4fb;border-color:var(--brand)}
.kb-name{font-weight:700;margin-bottom:3px}
.kb-tel{color:var(--muted);font-size:11px}
.kb-project{font-size:11px;margin-top:4px}

/* FICHE CLIENT */
.fiche-header{background:var(--paper);border:1px solid var(--border);border-radius:11px;padding:18px 20px;box-shadow:0 2px 8px rgba(0,0,0,.04);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.fiche-name{font-size:20px;font-weight:800;margin-bottom:4px}
.fiche-meta{display:flex;gap:12px;flex-wrap:wrap;align-items:center;font-size:13px;color:var(--muted)}
.fiche-actions{display:flex;gap:8px;flex-wrap:wrap}
.bloc{background:var(--paper);border:1px solid var(--border);border-radius:11px;box-shadow:0 2px 8px rgba(0,0,0,.04);margin-bottom:14px}
.bloc-head{padding:12px 16px;border-bottom:1px solid #f0f2f5;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);display:flex;align-items:center;justify-content:space-between}
.bloc-body{padding:14px 16px}
.field-row{display:grid;grid-template-columns:140px 1fr;gap:6px 12px;align-items:start;padding:5px 0;border-bottom:1px solid #f5f7fa}
.field-row:last-child{border:none}
.field-lbl{font-size:12px;color:var(--muted);font-weight:600;padding-top:1px}
.field-val{font-size:13px}
.timeline{padding:0}
.tl-item{display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #f0f2f5}
.tl-item:last-child{border:none}
.tl-dot{width:8px;height:8px;border-radius:50%;background:var(--brand);margin-top:5px;flex-shrink:0}
.tl-date{font-size:11px;color:var(--muted);white-space:nowrap;min-width:80px}
.tl-body{flex:1}.tl-type{font-size:10px;font-weight:700;text-transform:uppercase;color:var(--brand)}
.tl-note{font-size:12.5px;margin-top:2px}
.bien-card{display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border:1px solid var(--border);border-radius:9px;margin-bottom:8px;font-size:13px}
.bien-card:last-child{margin:0}
.bien-info .bien-title{font-weight:700}
.bien-info .bien-sub{font-size:11px;color:var(--muted);margin-top:2px}

@media(max-width:1100px){.app{grid-template-columns:1fr}.panel{position:relative;top:auto;max-height:none}.row-2{grid-template-columns:1fr}}
@media(max-width:700px){.row-4{grid-template-columns:repeat(2,1fr)}.kpi-grid{grid-template-columns:repeat(2,1fr)}}
</style>
