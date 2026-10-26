const FLAVA_PROJECT_CODE = "FLAVA";
const FLAVA_DETAILS_URL = "api/flava_asset_details.php";
const FLAVA_STATUS_URL = "api/flava_update_status.php";
const FLAVA_CLIENT_URL = "api/client_get.php";
const FLAVA_DEFAULT_BLOCK = "A";
const FLAVA_DEFAULT_FLOOR = "RDC";

const flavaState = {
  block: FLAVA_DEFAULT_BLOCK,
  floor: FLAVA_DEFAULT_FLOOR,
  type: "ALL",
  viewMode: "plan",
  onlyAvailable: true,
  selectedCode: null,
  currentDetail: null,
  currentPlanSrc: ""
};

const flavaEls = {};

function flavaQ(id) {
  return document.getElementById(id);
}

function flavaFmtMoney(value) {
  return (Math.round((Number(value) || 0) * 100) / 100).toLocaleString("fr-FR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

function flavaFmtSurface(value) {
  return `${(Math.round((Number(value) || 0) * 100) / 100).toLocaleString("fr-FR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  })} m²`;
}

function flavaWithCache(url) {
  return url + (url.includes("?") ? "&" : "?") + "_=" + Date.now();
}

function flavaIsPdf(path) {
  const clean = (path || "").split("#")[0].split("?")[0].toLowerCase();
  return clean.endsWith(".pdf");
}

function flavaAbsoluteUrl(path) {
  try {
    return new URL(path, window.location.href).href;
  } catch (e) {
    return path;
  }
}

function flavaCurrentFloorConfig() {
  return FLOORS.find((floor) => floor.id === flavaState.floor) || FLOORS[0];
}

function flavaFormatFloorLabel(id) {
  const floor = FLOORS.find((item) => item.id === id);
  return floor ? floor.label : id;
}

function flavaFloorRank(id) {
  if (id === "SOUS-SOL") return -2;
  if (id === "RDC") return -1;
  if (id === "MEZZ") return 0;
  const parsed = parseInt(id, 10);
  return Number.isFinite(parsed) ? parsed : 50;
}

function flavaIsMultiFloorMode() {
  return flavaState.floor === "ALL" || flavaState.block === "ALL";
}

function flavaResolveBlockLabel(primary, fallback = "") {
  const normalized = normalizeBlockValue(primary || fallback || "");
  return normalized || primary || fallback || "-";
}

function flavaResolveFloorLabel(primary, fallback = "") {
  if (primary) return primary;
  const normalized = normalizeFloorValue(fallback || "");
  return normalized ? flavaFormatFloorLabel(normalized) : (fallback || "-");
}

function flavaEnsurePlanSelection() {
  if (flavaState.block === "ALL") {
    flavaState.block = FLAVA_DEFAULT_BLOCK;
    if (flavaEls.selectBlock) flavaEls.selectBlock.value = FLAVA_DEFAULT_BLOCK;
  }
  const allFloorIds = FLOORS.map((f) => f.id);
  if (!flavaState.floor || flavaState.floor === "ALL" || !allFloorIds.includes(flavaState.floor)) {
    const preferred = allFloorIds.includes(FLAVA_DEFAULT_FLOOR) ? FLAVA_DEFAULT_FLOOR : (allFloorIds[0] || "");
    flavaInitFloorSelect(preferred);
  }
}

function flavaGetFloorImage(block, floor) {
  if (FLOOR_IMAGES[block] && FLOOR_IMAGES[block][floor]) {
    return FLOOR_IMAGES[block][floor];
  }
  return FLOOR_IMAGES[FLAVA_DEFAULT_BLOCK]?.[FLAVA_DEFAULT_FLOOR] || "assets/plans/flava/RDCB.png";
}

function flavaGetUnitByCode(code) {
  return UNITS.find((unit) => unit.code === code) || null;
}

function flavaGetDbCode(target) {
  if (target && typeof target === "object") {
    return target.db_code || (typeof getFlavaCanonicalUnitCode === "function" ? getFlavaCanonicalUnitCode(target.code || "") : (target.code || ""));
  }
  return typeof getFlavaCanonicalUnitCode === "function" ? getFlavaCanonicalUnitCode(target || "") : (target || "");
}

function flavaGetUnitsForPlan() {
  return UNITS.filter((unit) => {
    if (!unit.rect || unit.kind === "ACCESSORY") return false;
    const blockOk = flavaState.block === "ALL" ? true : unit.block === flavaState.block;
    const floorOk = !flavaState.floor || flavaState.floor === "ALL" ? true : unit.floor === flavaState.floor;
    const typeOk = flavaState.type === "ALL" ? true : unit.type === flavaState.type;
    return blockOk && floorOk && typeOk;
  });
}

function flavaGetUnitsForList() {
  let list = [...UNITS];

  list = list.filter((unit) => {
    const blockOk = flavaState.block === "ALL" ? true : unit.block === flavaState.block;
    const floorOk = !flavaState.floor || flavaState.floor === "ALL" ? true : unit.floor === flavaState.floor;
    const typeOk = flavaState.type === "ALL" ? true : unit.type === flavaState.type;
    return blockOk && floorOk && typeOk;
  });

  if (flavaState.onlyAvailable) {
    list = list.filter((unit) => normalizeStatusValue(unit.status) === "Disponible");
  }

  list.sort((left, right) => {
    const leftRank = flavaFloorRank(left.floor || "");
    const rightRank = flavaFloorRank(right.floor || "");
    if (leftRank !== rightRank) return leftRank - rightRank;
    if ((left.block || "") !== (right.block || "")) {
      return (left.block || "").localeCompare(right.block || "", "fr");
    }
    return (left.code || "").localeCompare(right.code || "", "fr");
  });

  return list;
}

function flavaStatusToRectClass(status) {
  switch (normalizeStatusValue(status)) {
    case "Disponible":
      return "unit-dispo";
    case "Réservé":
      return "unit-reserve";
    case "Vendu":
      return "unit-vendu";
    default:
      return null;
  }
}

function flavaStatusToBadgeClass(status) {
  switch (normalizeStatusValue(status)) {
    case "Disponible":
      return "badge-dispo";
    case "Réservé":
      return "badge-reserve";
    case "Vendu":
      return "badge-vendu";
    default:
      return "";
  }
}

function flavaCacheDom() {
  [
    "selectBlock",
    "selectFloor",
    "selectType",
    "viewPlanBtn",
    "viewListBtn",
    "listWrapper",
    "onlyAvailable",
    "unitsTbody",
    "planImage",
    "unitOverlay",
    "unitEmpty",
    "unitCard",
    "uCode",
    "uMeta",
    "uBlock",
    "uFloor",
    "uType",
    "uSurface",
    "clientSection",
    "cName",
    "cPhone",
    "cEmail",
    "promiseSection",
    "promiseBtn",
    "uPriceBase",
    "uPriceMonth",
    "uTotalMonth",
    "uStatusBadge",
    "uStatusSelect",
    "uSaveBtn",
    "unitPreviewBox",
    "unitPreviewImg",
    "unitPreviewPdf",
    "unitPreviewEmpty",
    "planModal",
    "planModalImg",
    "planModalPdf",
    "planModalEmpty",
    "planModalClose",
    "planModalDownload",
    "planModalPrint",
    "planModalSend",
    "planModalShare",
    "multiFloorContainer",
    "sousSolPanel"
  ].forEach((id) => {
    flavaEls[id] = flavaQ(id);
  });
}

function flavaGetAvailableFloors(block = flavaState.block) {
  const floors = new Set();
  UNITS.forEach((unit) => {
    if (unit.kind === "ACCESSORY") return;
    if (block !== "ALL" && unit.block !== block) return;
    if (!unit.floor) return;
    floors.add(unit.floor);
  });
  return Array.from(floors).sort((left, right) => {
    const leftRank = flavaFloorRank(left);
    const rightRank = flavaFloorRank(right);
    if (leftRank !== rightRank) return leftRank - rightRank;
    return left.localeCompare(right, "fr");
  });
}

function flavaInitTypeSelect() {
  flavaEls.selectType.innerHTML = "";

  const all = document.createElement("option");
  all.value = "ALL";
  all.textContent = "Toutes";
  flavaEls.selectType.appendChild(all);

  const types = Array.from(new Set(
    UNITS.filter((unit) => unit.kind !== "ACCESSORY").map((unit) => unit.type).filter(Boolean)
  )).sort((a, b) => a.localeCompare(b, "fr"));
  types.forEach((type) => {
    const option = document.createElement("option");
    option.value = type;
    option.textContent = type;
    flavaEls.selectType.appendChild(option);
  });
}

function flavaInitFloorSelect(preferredFloor = "") {
  // Keep the fixed "ALL" option, only rebuild floor-specific options
  flavaEls.selectFloor.querySelectorAll("option:not([value='ALL'])").forEach((o) => o.remove());

  // Always show all floors from FLOORS definition (ensures Sous-sol is always present)
  FLOORS.forEach((floor) => {
    const option = document.createElement("option");
    option.value = floor.id;
    option.textContent = floor.label;
    flavaEls.selectFloor.appendChild(option);
  });

  const floorIds = FLOORS.map((f) => f.id);
  let selectedFloor;
  if (preferredFloor === "ALL") {
    selectedFloor = "ALL";
  } else {
    selectedFloor = floorIds.includes(preferredFloor)
      ? preferredFloor
      : (floorIds.includes(FLAVA_DEFAULT_FLOOR) ? FLAVA_DEFAULT_FLOOR : (floorIds[0] || ""));
  }

  flavaEls.selectFloor.value = selectedFloor;
  flavaState.floor = selectedFloor;
}

function flavaSyncView() {
  const planMode = flavaState.viewMode === "plan";
  document.querySelector(".plan-wrapper")?.classList.toggle("hidden", !planMode);
  flavaEls.listWrapper.classList.toggle("hidden", planMode);
  flavaEls.viewPlanBtn.classList.toggle("is-active", planMode);
  flavaEls.viewListBtn.classList.toggle("is-active", !planMode);
}

function flavaRenderPlan() {
  if (flavaState.viewMode !== "plan") return;

  if (flavaIsMultiFloorMode()) {
    flavaEls.planImage.closest(".plan-inner").classList.add("hidden");
    flavaEls.planImage.closest(".plan-wrapper").classList.remove("is-sous-sol");
    flavaEls.multiFloorContainer.classList.remove("hidden");
    flavaEls.sousSolPanel.classList.add("hidden");
    flavaRenderMultiFloor();
    return;
  }

  flavaEls.planImage.closest(".plan-inner").classList.remove("hidden");
  flavaEls.multiFloorContainer.classList.add("hidden");

  flavaEnsurePlanSelection();
  const imageSrc = flavaGetFloorImage(flavaState.block, flavaState.floor);
  flavaEls.planImage.src = imageSrc;
  const draw = () => flavaDrawUnits();
  if (flavaEls.planImage.complete) {
    draw();
  } else {
    flavaEls.planImage.onload = draw;
  }

  const isSousSol = flavaState.floor === "SOUS-SOL";
  flavaEls.planImage.closest(".plan-wrapper").classList.toggle("is-sous-sol", isSousSol);
  if (isSousSol) {
    flavaRenderSousSol();
  } else {
    flavaEls.sousSolPanel.classList.add("hidden");
  }
}

function flavaDrawUnits() {
  if (flavaIsMultiFloorMode()) {
    flavaRedrawMultiFloorOverlays();
    return;
  }
  const image = flavaEls.planImage;
  const overlay = flavaEls.unitOverlay;
  overlay.innerHTML = "";

  const units = flavaGetUnitsForPlan();
  if (!units.length || !image.naturalWidth || !image.naturalHeight) return;

  const scaleX = image.clientWidth / image.naturalWidth;
  const scaleY = image.clientHeight / image.naturalHeight;

  overlay.style.width = image.clientWidth + "px";
  overlay.style.height = image.clientHeight + "px";

  units.forEach((unit) => {
    const node = document.createElement("div");
    node.className = "unit-rect";

    const statusClass = flavaStatusToRectClass(unit.status);
    if (statusClass) node.classList.add(statusClass);
    if (flavaState.selectedCode === unit.code) node.classList.add("is-selected");

    node.style.left = unit.rect.x * scaleX + "px";
    node.style.top = unit.rect.y * scaleY + "px";
    node.style.width = unit.rect.width * scaleX + "px";
    node.style.height = unit.rect.height * scaleY + "px";

    const label = document.createElement("span");
    label.textContent = unit.code;
    node.appendChild(label);

    node.addEventListener("click", (event) => {
      event.stopPropagation();
      flavaSelectUnit(unit.code);
    });

    overlay.appendChild(node);
  });
}

function flavaRenderMultiFloor() {
  const container = flavaEls.multiFloorContainer;
  container.innerHTML = "";

  const matchingUnits = UNITS.filter((u) => {
    if (!u.rect || u.kind === "ACCESSORY") return false;
    const blockOk = flavaState.block === "ALL" ? true : u.block === flavaState.block;
    const floorOk = flavaState.floor === "ALL" ? true : u.floor === flavaState.floor;
    const typeOk  = flavaState.type  === "ALL" ? true : u.type  === flavaState.type;
    return blockOk && floorOk && typeOk;
  });

  if (!matchingUnits.length) {
    container.innerHTML = '<p style="text-align:center;color:#9ca3af;padding:32px 0;font-size:13px;">Aucune unité pour cette sélection.</p>';
    return;
  }

  const groupMap = new Map();
  matchingUnits.forEach((u) => {
    const key = `${u.block}|${u.floor}`;
    if (!groupMap.has(key)) groupMap.set(key, { block: u.block, floor: u.floor, units: [] });
    groupMap.get(key).units.push(u);
  });

  const groups = Array.from(groupMap.values()).sort((a, b) => {
    const floorDiff = flavaFloorRank(a.floor) - flavaFloorRank(b.floor);
    if (floorDiff !== 0) return floorDiff;
    return a.block.localeCompare(b.block, "fr");
  });

  groups.forEach(({ block, floor, units }) => {
    const imageSrc = flavaGetFloorImage(block, floor);
    const floorLabel = flavaFormatFloorLabel(floor);

    const section = document.createElement("div");
    section.className = "mf-section";
    section.dataset.block = block;
    section.dataset.floor = floor;
    section.innerHTML = `
      <div class="mf-label">${floorLabel} – Bloc ${block}</div>
      <div class="mf-plan-inner">
        <img class="mf-plan-img" src="${imageSrc}" alt="Plan Bloc ${block} – ${floorLabel}" />
        <div class="mf-overlay"></div>
      </div>
    `;
    container.appendChild(section);

    const img = section.querySelector(".mf-plan-img");
    const overlay = section.querySelector(".mf-overlay");
    const draw = () => flavaDrawMfOverlay(img, overlay, units);
    if (img.complete && img.naturalWidth) {
      draw();
    } else {
      img.onload = draw;
    }
  });
}

function flavaDrawMfOverlay(img, overlay, units) {
  overlay.innerHTML = "";
  if (!img.naturalWidth || !img.naturalHeight) return;

  const scaleX = img.clientWidth / img.naturalWidth;
  const scaleY = img.clientHeight / img.naturalHeight;
  overlay.style.width = img.clientWidth + "px";
  overlay.style.height = img.clientHeight + "px";

  units.forEach((unit) => {
    const node = document.createElement("div");
    node.className = "unit-rect";
    const statusClass = flavaStatusToRectClass(unit.status);
    if (statusClass) node.classList.add(statusClass);
    if (flavaState.selectedCode === unit.code) node.classList.add("is-selected");

    node.style.left   = unit.rect.x * scaleX + "px";
    node.style.top    = unit.rect.y * scaleY + "px";
    node.style.width  = unit.rect.width * scaleX + "px";
    node.style.height = unit.rect.height * scaleY + "px";

    const label = document.createElement("span");
    label.textContent = unit.code;
    node.appendChild(label);

    node.addEventListener("click", (e) => {
      e.stopPropagation();
      flavaSelectUnit(unit.code);
    });
    overlay.appendChild(node);
  });
}

function flavaRedrawMultiFloorOverlays() {
  const container = flavaEls.multiFloorContainer;
  container.querySelectorAll(".mf-section").forEach((section) => {
    const block = section.dataset.block;
    const floor = section.dataset.floor;
    const img = section.querySelector(".mf-plan-img");
    const overlay = section.querySelector(".mf-overlay");
    const units = UNITS.filter((u) =>
      u.block === block && u.floor === floor &&
      (flavaState.type === "ALL" || u.type === flavaState.type) &&
      u.rect && u.kind !== "ACCESSORY"
    );
    flavaDrawMfOverlay(img, overlay, units);
  });
}

function flavaRenderSousSol() {
  const panel = flavaEls.sousSolPanel;
  panel.innerHTML = "";

  const parkings = UNITS.filter((u) => u.kind === "ACCESSORY" && u.type === "Parking")
    .sort((a, b) => (a.code || "").localeCompare(b.code || "", "fr", { numeric: true }));
  const celliers = UNITS.filter((u) => u.kind === "ACCESSORY" && u.type === "Cellier")
    .sort((a, b) => (a.code || "").localeCompare(b.code || "", "fr", { numeric: true }));

  function statusCls(s) {
    if (s === "Vendu")   return "v";
    if (s === "Réservé") return "r";
    return "d";
  }

  if (parkings.length) {
    const dynHt  = typeof flavaParkingHT  === "function" ? flavaParkingHT()  : 0;
    const dynTtc = typeof flavaParkingTTC === "function" ? flavaParkingTTC() : 0;

    const sec = document.createElement("div");
    sec.className = "ss-section";
    sec.innerHTML = `
      <div class="ss-section-head">
        <span class="ss-section-title">Parkings (${parkings.length})</span>
        <span class="ss-price-tag">${flavaFmtMoney(dynHt)} TND HT · ${flavaFmtMoney(dynTtc)} TND TTC</span>
      </div>
      <div class="ss-park-grid"></div>`;
    panel.appendChild(sec);

    const grid = sec.querySelector(".ss-park-grid");
    parkings.forEach((p) => {
      const cls = statusCls(p.status);
      const pHt = p.status === "Vendu" ? Number(p.price_ht || 0) : dynHt;
      const btn = document.createElement("button");
      btn.className = `ss-acc-btn ${cls}`;
      btn.type = "button";
      btn.title = `${p.status} — ${flavaFmtMoney(pHt)} TND HT`;
      btn.innerHTML = `<span class="ss-code">${p.code}</span><span class="ss-sub">${flavaFmtMoney(pHt)}</span>`;
      btn.addEventListener("click", () => flavaSelectUnit(p.code));
      grid.appendChild(btn);
    });
  }

  if (celliers.length) {
    const sec = document.createElement("div");
    sec.className = "ss-section";
    sec.innerHTML = `
      <div class="ss-section-head">
        <span class="ss-section-title">Celliers (${celliers.length})</span>
      </div>
      <div class="ss-cell-list"></div>`;
    panel.appendChild(sec);

    const list = sec.querySelector(".ss-cell-list");
    celliers.forEach((c) => {
      const cls = statusCls(c.status);
      const surf = c.surface ? `${Number(c.surface).toLocaleString("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} m²` : "–";
      const card = document.createElement("div");
      card.className = `ss-cell-card ${cls}`;
      card.innerHTML = `
        <span class="ss-code">${c.code}</span>
        <span class="ss-surf">${surf}</span>
        <span class="ss-sub">${flavaFmtMoney(c.price_ht)} TND HT</span>
        <span class="ss-badge">${c.status}</span>`;
      card.style.cursor = "pointer";
      card.addEventListener("click", () => flavaSelectUnit(c.code));
      list.appendChild(card);
    });
  }

  flavaEls.unitEmpty.style.display = "none";
  flavaEls.unitCard.classList.add("hidden");
  panel.classList.remove("hidden");
}

function flavaRenderList() {
  flavaEls.unitsTbody.innerHTML = "";

  flavaGetUnitsForList().forEach((unit) => {
    const row = document.createElement("tr");
    const priceM2 = priceForCurrentMonth(unit.price_m2_base || 0);
    const isParkRow = unit.kind === "ACCESSORY" && (unit.type || "").toUpperCase() === "PARKING";
    const totalHt = isParkRow && unit.status !== "Vendu" && typeof flavaParkingHT === "function"
      ? flavaParkingHT()
      : (unit.price_ht
          ? Number(unit.price_ht || 0)
          : priceM2 * Number(unit.surface || 0));

    row.innerHTML = `
      <td>${unit.code}</td>
      <td>${flavaResolveBlockLabel(unit.block, "")}</td>
      <td>${flavaFormatFloorLabel(unit.floor)}</td>
      <td>${unit.type || ""}</td>
      <td>${flavaFmtSurface(unit.surface)}</td>
      <td>${flavaFmtMoney(priceM2)} TND</td>
      <td>${flavaFmtMoney(totalHt)} TND</td>
      <td><span class="row-badge ${flavaStatusToRectClass(unit.status)?.replace("unit-", "") || ""}">${normalizeStatusValue(unit.status)}</span></td>
    `;
    row.addEventListener("click", () => flavaSelectUnit(unit.code));
    flavaEls.unitsTbody.appendChild(row);
  });
}

function flavaSetViewMode(mode) {
  flavaState.viewMode = mode;
  flavaSyncView();
  if (mode === "plan") {
    flavaRenderPlan();
  } else {
    flavaRenderList();
  }
}

function flavaResetPreview() {
  flavaState.currentPlanSrc = "";
  flavaEls.unitPreviewBox.classList.remove("is-clickable");
  flavaEls.unitPreviewBox.onclick = null;

  flavaEls.unitPreviewImg.removeAttribute("src");
  flavaEls.unitPreviewImg.classList.add("hidden");

  flavaEls.unitPreviewPdf.removeAttribute("src");
  flavaEls.unitPreviewPdf.classList.add("hidden");

  flavaEls.unitPreviewEmpty.classList.remove("hidden");
}

function flavaSetPreviewSource(src) {
  flavaResetPreview();
  if (!src) return;

  flavaState.currentPlanSrc = src;
  flavaEls.unitPreviewBox.classList.add("is-clickable");
  flavaEls.unitPreviewBox.onclick = () => flavaOpenPlanModal(src);

  if (flavaIsPdf(src)) {
    flavaEls.unitPreviewPdf.src = src;
    flavaEls.unitPreviewPdf.classList.remove("hidden");
  } else {
    flavaEls.unitPreviewImg.src = src;
    flavaEls.unitPreviewImg.classList.remove("hidden");
  }

  flavaEls.unitPreviewEmpty.classList.add("hidden");
}

function flavaClosePlanModal() {
  flavaEls.planModal.classList.add("hidden");
  flavaEls.planModalImg.removeAttribute("src");
  flavaEls.planModalImg.classList.add("hidden");
  flavaEls.planModalPdf.removeAttribute("src");
  flavaEls.planModalPdf.classList.add("hidden");
  flavaEls.planModalEmpty.classList.add("hidden");
}

function flavaOpenPlanModal(src) {
  if (!src) return;

  const isPdf = flavaIsPdf(src);
  flavaEls.planModal.classList.remove("hidden");
  flavaEls.planModalEmpty.classList.add("hidden");

  if (isPdf) {
    flavaEls.planModalImg.removeAttribute("src");
    flavaEls.planModalImg.classList.add("hidden");
    flavaEls.planModalPdf.src = src;
    flavaEls.planModalPdf.classList.remove("hidden");
  } else {
    flavaEls.planModalPdf.removeAttribute("src");
    flavaEls.planModalPdf.classList.add("hidden");
    flavaEls.planModalImg.src = src;
    flavaEls.planModalImg.classList.remove("hidden");
  }

  flavaEls.planModalDownload.onclick = () => {
    const anchor = document.createElement("a");
    anchor.href = src;
    anchor.download = src.split("/").pop() || (isPdf ? "plan.pdf" : "plan");
    anchor.click();
  };

  flavaEls.planModalPrint.onclick = () => {
    if (isPdf) {
      const win = window.open(src, "_blank");
      if (win) win.focus();
      return;
    }

    const win = window.open("", "_blank");
    if (!win) return;
    win.document.head.innerHTML = "<title>Plan</title><style>body{margin:0;display:flex;justify-content:center;align-items:center;min-height:100vh;}img{max-width:100%;}</style>";
    const image = win.document.createElement("img");
    image.src = src;
    image.onload = () => {
      win.print();
      win.close();
    };
    win.document.body.appendChild(image);
  };

  flavaEls.planModalSend.onclick = () => {
    const fullUrl = flavaAbsoluteUrl(src);
    const subject = encodeURIComponent("Plan - " + (src.split("/").pop() || "unité"));
    const body = encodeURIComponent("Veuillez trouver le plan de l'unité.\n\nLien : " + fullUrl);
    window.location.href = `mailto:?subject=${subject}&body=${body}`;
  };

  flavaEls.planModalShare.style.display = "";
  flavaEls.planModalShare.onclick = async () => {
    const fileName = src.split("/").pop() || "plan.jpg";
    const ext  = fileName.split(".").pop().toLowerCase();
    const mime = ext === "png" ? "image/png" : "image/jpeg";

    /* Récupère l'image en mémoire */
    let blob = null;
    try {
      const resp = await fetch(encodeURI(src));
      if (resp.ok) blob = await resp.blob();
    } catch (e) {}

    if (blob) {
      const file = new File([blob], fileName, { type: mime });
      /* Essaie le partage natif avec fichier (mobile) */
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        try { await navigator.share({ files: [file], title: "Plan FLAVA" }); return; } catch (e) {}
      }
      /* Sinon : télécharge l'image → l'agent partage depuis sa galerie */
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url; a.download = fileName; a.click();
      setTimeout(() => URL.revokeObjectURL(url), 2000);
    } else {
      /* Dernier recours : ouvre dans un nouvel onglet */
      window.open(src, "_blank");
    }
  };
}

async function flavaFetchDetails(code) {
  const item = flavaGetUnitByCode(code);
  const kind = item?.kind || "UNIT";
  const response = await fetch(flavaWithCache(`${FLAVA_DETAILS_URL}?kind=${encodeURIComponent(kind)}&code=${encodeURIComponent(code)}`));
  if (!response.ok) {
    throw new Error("Impossible de charger les détails FLAVA");
  }
  return response.json();
}

async function flavaFillClient(code, kind) {
  if (kind === "ACCESSORY") {
    flavaEls.clientSection.style.display = "none";
    flavaEls.cName.textContent = "";
    flavaEls.cPhone.textContent = "";
    flavaEls.cEmail.textContent = "";
    return;
  }

  try {
    const response = await fetch(flavaWithCache(`${FLAVA_CLIENT_URL}?project=${FLAVA_PROJECT_CODE}&unit_code=${encodeURIComponent(code)}`));
    const payload = await response.json();
    if (!payload || payload.error) {
      flavaEls.clientSection.style.display = "none";
      return;
    }

    flavaEls.clientSection.style.display = "";
    flavaEls.cName.textContent = payload.nom || "-";
    flavaEls.cPhone.textContent = payload.tel || "-";
    flavaEls.cEmail.textContent = payload.email || "-";
  } catch (error) {
    flavaEls.clientSection.style.display = "none";
  }
}

function flavaClearSelection() {
  flavaState.selectedCode = null;
  flavaState.currentDetail = null;
  flavaEls.unitCard.classList.add("hidden");
  flavaResetPreview();
  flavaEls.clientSection.style.display = "none";
  flavaEls.promiseSection.style.display = "none";

  const sousSolActive = flavaState.floor === "SOUS-SOL" && flavaState.viewMode === "plan" && !flavaIsMultiFloorMode();
  if (sousSolActive) {
    flavaEls.unitEmpty.style.display = "none";
    flavaRenderSousSol();
  } else {
    flavaEls.unitEmpty.style.display = "grid";
    flavaEls.sousSolPanel.classList.add("hidden");
  }

  if (flavaState.viewMode === "plan") {
    flavaDrawUnits();
  } else {
    flavaRenderList();
  }
}

function flavaUpdateLocalStatuses(codes, status) {
  const normalized = normalizeStatusValue(status);
  UNITS.forEach((unit) => {
    if (codes.includes(unit.code)) {
      unit.status = normalized;
    }
  });
}

async function flavaSaveStatus(code, kind, oldStatus) {
  const newStatus = flavaEls.uStatusSelect.value;
  const codes = kind === "UNIT" ? [code, ...getLinkedCodes(code)] : [code];

  const results = await Promise.all(codes.map(async (linkedCode) => {
    const linkedUnit = flavaGetUnitByCode(linkedCode);
    const linkedKind = linkedUnit?.kind || kind;
    const response = await fetch(FLAVA_STATUS_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        kind: linkedKind,
        code: linkedKind === "ACCESSORY" ? linkedCode : flavaGetDbCode(linkedUnit || linkedCode),
        status: newStatus
      })
    });
    let payload = {};
    try {
      payload = await response.json();
    } catch (error) {
      payload = {};
    }
    return { response, payload, code: linkedCode };
  }));

  const failed = results.find(({ response, payload }) => !response.ok || payload.error);
  if (failed) {
    alert(failed.payload.error || "Erreur lors de l'enregistrement du statut.");
    flavaEls.uStatusSelect.value = oldStatus;
    return;
  }

  flavaUpdateLocalStatuses(codes, newStatus);

  if (kind === "UNIT" && oldStatus !== "Vendu" && newStatus === "Vendu") {
    window.location.href = `fiche_client.php?project=${FLAVA_PROJECT_CODE}&unit_code=${encodeURIComponent(code)}`;
    return;
  }

  await flavaSelectUnit(code);
}

async function flavaSelectUnit(code) {
  flavaState.selectedCode = code;
  flavaState.currentDetail = null;

  if (flavaState.viewMode === "plan") {
    flavaDrawUnits();
  } else {
    flavaRenderList();
  }

  flavaEls.unitEmpty.style.display = "none";
  flavaEls.sousSolPanel.classList.add("hidden");
  flavaEls.unitCard.classList.remove("hidden");

  const unit = flavaGetUnitByCode(code);
  if (!unit) return;

  let detail;
  try {
    detail = await flavaFetchDetails(code);
  } catch (error) {
    detail = {
      code: unit.code,
      kind: unit.kind || "UNIT",
      type: unit.type || "",
      designation: unit.type || "",
      block: unit.block || "",
      floor: flavaFormatFloorLabel(unit.floor),
      surface: Number(unit.surface || 0),
      price_ht: Number(unit.price_ht || 0),
      price_ttc: Number(unit.price_ttc || 0),
      price_m2_ht: Number(unit.price_m2_base || 0),
      price_m2_ttc: 0,
      status: normalizeStatusValue(unit.status || "Disponible"),
      plan_image: unit.plan_image || ""
    };
  }

  flavaState.currentDetail = detail;
  const kind = detail.kind || unit.kind || "UNIT";
  const displayBlock = flavaResolveBlockLabel(detail.block, unit.block);
  const displayFloor = flavaResolveFloorLabel(detail.floor, unit.floor);
  const surface = Number(detail.surface || unit.surface || 0);
  const status = normalizeStatusValue(detail.status || unit.status || "Disponible");
  const isParking = kind === "ACCESSORY" && (detail.type || unit.type || "").toUpperCase() === "PARKING";
  const priceHtM2 = priceForCurrentMonth(detail.price_m2_ht || unit.price_m2_base || 0);
  const priceTtcM2 = detail.price_m2_ttc
    ? Number(detail.price_m2_ttc)
    : Math.round(priceHtM2 * 1.19 * 100) / 100;
  const totalHt = isParking && status !== "Vendu" && typeof flavaParkingHT === "function"
    ? flavaParkingHT()
    : (detail.price_ht ? Number(detail.price_ht) : Math.round(priceHtM2 * surface * 100) / 100);
  const totalTtc = isParking && status !== "Vendu" && typeof flavaParkingTTC === "function"
    ? flavaParkingTTC()
    : (detail.price_ttc ? Number(detail.price_ttc) : Math.round(totalHt * 1.19 * 100) / 100);

  flavaEls.uCode.textContent = detail.code || unit.code;
  flavaEls.uMeta.textContent = `${displayBlock} · ${displayFloor}`;
  flavaEls.uBlock.textContent = displayBlock;
  flavaEls.uFloor.textContent = displayFloor;
  flavaEls.uType.textContent = detail.type || detail.designation || unit.type || "-";
  flavaEls.uSurface.textContent = flavaFmtSurface(surface);
  flavaEls.uPriceBase.textContent = `${flavaFmtMoney(priceHtM2)} TND/m² HT`;
  flavaEls.uPriceMonth.textContent = `${flavaFmtMoney(priceTtcM2)} TND/m² TTC`;
  flavaEls.uTotalMonth.textContent = `${flavaFmtMoney(totalHt)} TND HT · ${flavaFmtMoney(totalTtc)} TND TTC`;
  flavaEls.uStatusBadge.textContent = status;
  flavaEls.uStatusBadge.className = `status-badge ${flavaStatusToBadgeClass(status)}`;
  flavaEls.uStatusSelect.value = status;
  flavaEls.uSaveBtn.onclick = () => flavaSaveStatus(detail.code || unit.code, kind, status);

  flavaSetPreviewSource(detail.plan_image || unit.plan_image || "");

  const _unitRef = detail.code || unit.code;
  flavaEls.promiseSection.style.display = kind === "ACCESSORY" ? "none" : "";
  flavaEls.promiseBtn.textContent = status === "Vendu" ? "✏ Modifier la fiche client" : "Ouvrir la fiche client";
  flavaEls.promiseBtn.onclick = () => {
    window.location.href = `fiche_client.php?project=${FLAVA_PROJECT_CODE}&unit_code=${encodeURIComponent(_unitRef)}`;
  };
  // Bouton suivi de dossier (injecté dynamiquement)
  let _dossierBtn = document.getElementById("flava-dossier-btn");
  if (!_dossierBtn) {
    _dossierBtn = document.createElement("a");
    _dossierBtn.id = "flava-dossier-btn";
    _dossierBtn.target = "_blank";
    _dossierBtn.style.cssText = "display:block;margin-top:8px;text-decoration:none";
    _dossierBtn.innerHTML = `<button class="btn-secondary" style="width:100%">📊 Suivi de paiements</button>`;
    flavaEls.promiseSection.appendChild(_dossierBtn);
  }
  _dossierBtn.href = `paiements.php?unit_code=${encodeURIComponent(_unitRef)}`;
  _dossierBtn.style.display = status === "Vendu" ? "" : "none";

  // Résumé de promesse (injecté dynamiquement)
  let _promSummary = document.getElementById("flava-promise-summary");
  if (!_promSummary) {
    _promSummary = document.createElement("div");
    _promSummary.id = "flava-promise-summary";
    _promSummary.style.marginTop = "10px";
    flavaEls.promiseSection.appendChild(_promSummary);
  }
  _promSummary.innerHTML = "";
  if (kind !== "ACCESSORY") flavaFillPromise(_unitRef, _promSummary);

  await flavaFillClient(detail.code || unit.code, kind);
}

async function flavaFillPromise(unitCode, container) {
  container.innerHTML = `<span style="color:#9ca3af;font-size:11px">Chargement...</span>`;
  try {
    const res  = await fetch(`api/promise_get.php?unit_code=${encodeURIComponent(unitCode)}&_=${Date.now()}`);
    const data = await res.json();
    if (!data.promise || data.error || data.promise.error === "not_found") {
      container.innerHTML = `<span style="color:#9ca3af;font-size:11px">Aucune promesse enregistrée.</span>`;
      return;
    }
    const p     = data.promise;
    const sch   = data.schedule;
    const insts = data.installments || [];
    const SC = {
      draft:             { bg:"#e2eef5", color:"#145f7d", label:"Brouillon" },
      signed:            { bg:"#ecfdf5", color:"#166534", label:"Signée ✅" },
      cancelled:         { bg:"#fef2f2", color:"#991b1b", label:"Annulée"  },
      converted_to_sale: { bg:"#fef3c7", color:"#92400e", label:"Vendue"   },
    };
    const sc    = SC[p.status] || { bg:"#f4f6f8", color:"#555", label: p.status };
    const paid  = sch ? parseFloat(sch.paid_amount    || 0) : 0;
    const total = sch ? parseFloat(sch.total_amount   || 0) : parseFloat(p.sale_price || 0);
    const rem   = sch ? parseFloat(sch.remaining_amount || 0) : Math.max(0, total - paid);
    const prog  = total > 0 ? Math.round(paid / total * 1000) / 10 : 0;
    const fmtN  = v => Number(v||0).toLocaleString("fr-FR",{minimumFractionDigits:0,maximumFractionDigits:0});
    let html = `<div style="background:${sc.bg};border-radius:8px;padding:8px 10px;margin-bottom:6px">
      <div style="font-size:10px;font-weight:700;color:${sc.color};letter-spacing:.05em">${p.promise_number||"—"}</div>
      <div style="font-size:13px;font-weight:800;color:${sc.color};margin-top:2px">${sc.label}</div>
      ${p.sale_price?`<div style="font-size:11px;color:${sc.color};margin-top:3px;opacity:.85">Total : ${fmtN(p.sale_price)} TND TTC</div>`:""}
    </div>`;
    if (total > 0) {
      html += `<div style="margin-bottom:8px">
        <div style="font-size:11px;color:#6b7280;margin-bottom:3px">
          Payé : <strong style="color:#111">${fmtN(paid)} TND</strong> / ${fmtN(total)} TND
          <span style="float:right;color:${prog>=100?"#16a34a":"#374151"};font-weight:700">${prog}%</span>
        </div>
        <div style="height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden">
          <div style="width:${Math.min(100,prog)}%;height:100%;background:${prog>=100?"#16a34a":"#145f7d"};border-radius:3px;transition:width .3s"></div>
        </div>
        ${rem>0?`<div style="font-size:10px;color:#6b7280;margin-top:3px">Reste : <strong>${fmtN(rem)} TND</strong></div>`:""}
      </div>`;
    }
    if (insts.length > 0) {
      const IS = { pending:{label:"En attente",color:"#6b7280"}, partially_paid:{label:"Partiel",color:"#b45309"}, paid:{label:"✓ Payée",color:"#16a34a"}, overdue:{label:"⚠ Retard",color:"#dc2626"} };
      html += `<div style="border-top:1px solid #e5e7eb;padding-top:6px"><div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:5px">Échéancier (${insts.length} tranches)</div>`;
      insts.forEach(inst => {
        const is  = IS[inst.status] || {label:inst.status,color:"#6b7280"};
        const due = inst.due_date ? inst.due_date.split("-").reverse().join("/") : "—";
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid #f3f4f6">
          <span style="color:#374151;font-size:11px">${inst.label||"—"}</span>
          <span style="text-align:right"><span style="color:#111;font-weight:600;font-size:11px">${fmtN(inst.expected_amount)} TND</span>
          <span style="display:block;font-size:9px;color:${is.color}">${is.label} · ${due}</span></span></div>`;
      });
      html += `</div>`;
    }
    container.innerHTML = html;
  } catch(e) {
    container.innerHTML = `<span style="color:#9ca3af;font-size:11px">Erreur de chargement.</span>`;
  }
}

function flavaWireEvents() {
  flavaEls.selectBlock.addEventListener("change", () => {
    flavaState.block = flavaEls.selectBlock.value;
    flavaInitFloorSelect(flavaState.floor);
    flavaClearSelection();
    if (flavaState.viewMode === "plan") {
      flavaRenderPlan();
    } else {
      flavaRenderList();
    }
  });

  flavaEls.selectFloor.addEventListener("change", () => {
    flavaState.floor = flavaEls.selectFloor.value;
    flavaClearSelection();
    if (flavaState.viewMode === "plan") {
      flavaRenderPlan();
    } else {
      flavaRenderList();
    }
  });

  flavaEls.selectType.addEventListener("change", () => {
    flavaState.type = flavaEls.selectType.value;
    flavaClearSelection();
    if (flavaState.viewMode === "plan") {
      flavaRenderPlan();
    } else {
      flavaRenderList();
    }
  });

  flavaEls.onlyAvailable.addEventListener("change", () => {
    flavaState.onlyAvailable = !!flavaEls.onlyAvailable.checked;
    flavaRenderList();
  });

  flavaEls.viewPlanBtn.addEventListener("click", () => flavaSetViewMode("plan"));
  flavaEls.viewListBtn.addEventListener("click", () => flavaSetViewMode("list"));

  flavaEls.planImage.addEventListener("click", () => {
    flavaClearSelection();
  });

  flavaEls.planModalClose.addEventListener("click", flavaClosePlanModal);
  flavaEls.planModal.addEventListener("click", (event) => {
    if (event.target === flavaEls.planModal || event.target.classList.contains("plan-modal-backdrop")) {
      flavaClosePlanModal();
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") flavaClosePlanModal();
  });
}

async function flavaInit() {
  flavaCacheDom();
  await fetchDynamicUnits();
  await fetchDynamicAccessories();
  if (flavaEls.selectBlock.querySelector(`option[value="${FLAVA_DEFAULT_BLOCK}"]`)) {
    flavaEls.selectBlock.value = FLAVA_DEFAULT_BLOCK;
  }
  flavaState.block = flavaEls.selectBlock.value;
  flavaInitFloorSelect();
  flavaInitTypeSelect();

  flavaState.floor = flavaEls.selectFloor.value;
  flavaState.type = flavaEls.selectType.value;
  flavaState.onlyAvailable = !!flavaEls.onlyAvailable.checked;

  flavaWireEvents();
  flavaSetViewMode("plan");
  flavaRenderPlan();
  flavaRenderList();

  const _urlUnit = new URLSearchParams(window.location.search).get('unit_code');
  if (_urlUnit) {
    const _canonical = typeof getFlavaCanonicalUnitCode === 'function' ? getFlavaCanonicalUnitCode(_urlUnit) : _urlUnit.trim().toUpperCase();
    const _match = UNITS.find(u => {
      if (u.code === _urlUnit) return true;
      const uc = typeof getFlavaCanonicalUnitCode === 'function' ? getFlavaCanonicalUnitCode(u.code) : u.code;
      return uc === _canonical;
    });
    if (_match) {
      if (_match.block && flavaEls.selectBlock.querySelector(`option[value="${_match.block}"]`)) {
        flavaEls.selectBlock.value = _match.block;
        flavaState.block = _match.block;
        flavaInitFloorSelect(_match.floor);
      }
      if (_match.floor && flavaEls.selectFloor.querySelector(`option[value="${_match.floor}"]`)) {
        flavaEls.selectFloor.value = _match.floor;
        flavaState.floor = _match.floor;
      }
      flavaRenderPlan();
      await flavaSelectUnit(_match.code);
    }
  }
}

document.addEventListener("DOMContentLoaded", flavaInit);
