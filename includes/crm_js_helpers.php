const SL={
  interested:'Intéressé',reserved:'Réservé',promise_draft:'PV Brouillon',
  promise_signed:'PV Signé',sold:'Vendu',payment_in_progress:'Paiement',
  paid:'Soldé',closed:'Clôturé',cancelled:'Annulé',
  new:'Nouveau',contacted:'Contacté',visit:'Visite',negotiation:'Négociation',lost:'Perdu'
};
const PRI={low:'Froid',medium:'Moyen',high:'Chaud',hot:'Très chaud',vip:'VIP'};
const ACT={call:'Appel',whatsapp:'WhatsApp',meeting:'RDV',email:'Email',task:'Tâche',note:'Note'};

function esc(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
function fmt(n,z='—'){if(!n)return z;n=parseFloat(n);if(isNaN(n)||n<=0)return z;return Math.round(n).toLocaleString('fr-FR')+' TND';}
function fmtK(n){if(!n||n<=0)return '—';if(n>=1000000)return(n/1000000).toFixed(1)+' M';if(n>=1000)return Math.round(n/1000)+' K';return Math.round(n).toLocaleString('fr-FR');}
function sbadge(s){return `<span class="badge bs-${esc(s)}">${esc(SL[s]||s)}</span>`;}
function pbadge(p){if(!p||p==='medium')return '';return `<span class="pri pri-${esc(p)}">${esc(PRI[p]||p)}</span>`;}
function ago(dt){
  if(!dt)return '—';
  const d=Math.floor((Date.now()-new Date(dt))/86400000);
  if(d===0)return 'Aujourd\'hui';if(d===1)return 'Hier';if(d<7)return d+'j';
  if(d<30)return Math.floor(d/7)+'sem';return Math.floor(d/30)+'mois';
}
