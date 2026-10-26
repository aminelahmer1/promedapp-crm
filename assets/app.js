// ==============================
// CONFIG GLOBALE FLAVA
// ==============================

// Mois de base du prix (ton tableau Novembre)
const BASE_MONTH = "2025-11"; // AAAA-MM
const MONTHLY_INCREASE = 0.5; // +0,5 % / mois

// URLs pour les statuts partagés (à créer sur Hostinger)
const STATUS_GET_URL  = "api/status_get.php";
const STATUS_SAVE_URL = "api/status_save.php";
const CLIENT_GET_URL  = "api/client_get.php";

// Étages FLAVA
const FLOORS = [
  { id: "RDC",  label: "RDC" },
  { id: "1",    label: "1er étage" },
  { id: "2",    label: "2ème étage" },
  { id: "3",    label: "3ème étage" },
  { id: "MEZZ", label: "Mezzanine" }
];

// Plans par bloc + étage (adapter l’extension .png si besoin)
const FLOOR_IMAGES = {
  A: {
    "RDC":  "assets/plans/RDCA.png",
    "1":    "assets/plans/1eretageA.jpg",
    "2":    "assets/plans/2emeA.jpg",
    "3":    "assets/plans/3emeA.jpg",
    "MEZZ": "assets/plans/MEZZANINEA.jpg"
  },
  B: {
    "RDC":  "assets/plans/RDCB.png",
    "1":    "assets/plans/1eretageB.jpg",
    "2":    "assets/plans/2emeB.jpg",
    "3":    "assets/plans/3emeB.jpg",
    "MEZZ": "assets/plans/MEZZANINEB.jpg"
  }
};

// ==============================
// GROUPES DUPLEX (RDC + MEZZ)
// ==============================

const DUPLEX_GROUPS = [
  ["B-RDC-D01", "B-MEZZ-D01"],
  ["B-RDC-D02", "B-MEZZ-D02"],
  ["B-RDC-D03", "B-MEZZ-D03"],
  ["B-RDC-D04", "B-MEZZ-D04"],
  ["B-RDC-D05", "B-MEZZ-D05"],
  ["B-RDC-D06", "B-MEZZ-D06"],
  ["B-RDC-D07", "B-MEZZ-D07"],
  ["B-RDC-D08", "B-MEZZ-D08"]
];
const PENTHOUSE_GROUPS = [
  ["A-2-D-1-1", "A-3-D-1-1"],
  ["A-2-D-1-2", "A-3-D-1-2"],
  ["A-2-D-1-3", "A-3-D-1-3"],
  ["A-2-D-1-4", "A-3-D-1-4"],
  ["A-2-D-1-5", "A-3-D-1-5"],
  ["A-2-D-1-6", "A-3-D-1-6"],
  ["A-2-D-1-7", "A-3-D-1-7"],
  ["A-2-D-1-8", "A-3-D-1-8"],
];

// retourne tous les autres codes du même duplex
function getLinkedCodes(code) {
  const ALL_GROUPS = [...DUPLEX_GROUPS, ...PENTHOUSE_GROUPS];

  for (const group of ALL_GROUPS) {
    if (group.includes(code)) {
      return group.filter(c => c !== code);
    }
  }
  return [];
}
// ==============================
// DONNÉES UNITÉS (fixes)
// ==============================
//


const UNITS = [
  // -------- Bloc A / RDC --------
  {
    code: "A-RDC-LC10",
    block: "A",
    floor: "RDC",
    type: "Local commercial",
    surface: 151.48,
    price_m2_base: 12000,
    status: "Disponible",
    rect: { x: 420, y: 850, width: 360, height: 820 }
  },
  {
    code: "A-RDC-LC09",
    block: "A",
    floor: "RDC",
    type: "Local commercial",
    surface: 147.77,
    price_m2_base: 12000,
    status: "Disponible",
    rect: { x: 800, y: 850, width: 350, height: 820 }
  },
  {
    code: "A-RDC-LC08",
    block: "A",
    floor: "RDC",
    type: "Local commercial",
    surface: 71.58,
    price_m2_base: 12000,
    status: "Disponible",
    rect: { x: 1160, y: 1160, width: 250, height: 520 }
  },
  {
    code: "A-RDC-LC07",
    block: "A",
    floor: "RDC",
    type: "Local commercial",
    surface: 79.72,
    price_m2_base: 12000,
    status: "Disponible",
    rect: { x: 1420, y: 1160, width: 300, height: 520 }
  },
  {
    code: "A-RDC-LC06",
    block: "A",
    floor: "RDC",
    type: "Local commercial",
    surface: 79.92,
    price_m2_base: 12000,
    status: "Disponible",
    rect: { x: 1700, y: 1160, width: 300, height: 520 }
  },
  {
    code: "A-RDC-LC05",
    block: "A",
    floor: "RDC",
    type: "Local commercial",
    surface: 132.76,
    price_m2_base: 12000,
    status: "Disponible",
    rect: { x: 2010, y: 950, width: 360, height: 730 }
  },
  {
    code: "A-RDC-LC04",
    block: "A",
    floor: "RDC",
    type: "Local commercial",
    surface: 130.64,
    price_m2_base: 12000,
    status: "Disponible",
    rect: { x: 2370, y: 1270, width: 580, height: 400 }
  },
  {
    code: "A-RDC-LC03",
    block: "A",
    floor: "RDC",
    type: "Local commercial",
    surface: 89.16,
    price_m2_base: 12000,
    status: "Disponible",
    rect: { x: 2370, y: 950, width: 580, height: 300 }
  },
  {
    code: "A-RDC-LC02",
    block: "A",
    floor: "RDC",
    type: "Local commercial",
    surface: 125.59,
    price_m2_base: 12000,
    status: "Disponible",
   rect: { x: 2225, y: 600, width: 700, height: 360 }
  },
  {
    code: "A-RDC-LC01",
    block: "A",
    floor: "RDC",
    type: "Local commercial",
    surface: 134.48,
    price_m2_base: 12000,
    status: "Disponible",
    rect: { x: 2225, y: 220, width: 700, height: 380 }
  },

  // -------- Bloc B / RDC --------
  {
    code: "B-RDC-D03",
    block: "B",
    floor: "RDC",
    type: "S+4.5",
    surface: 327.69,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 400, y: 1140, width: 600, height: 610 }
  },
  {
    code: "B-RDC-D02",
    block: "B",
    floor: "RDC",
    type: "S+4.5",
    surface: 335.47,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 400, y: 500, width: 600, height: 620 }
  },
  {
    code: "B-RDC-D01",
    block: "B",
    floor: "RDC",
    type: "S+3",
    surface: 283.98,
    price_m2_base: 5404,
    status: "Disponible",
     rect: { x: 1000, y: 500, width: 670, height: 620 }
  },
  {
    code: "B-RDC-D04",
    block: "B",
    floor: "RDC",
    type: "S+3",
    surface: 260.17,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 1000, y: 1230, width: 555, height: 520 }
  },
  {
    code: "B-RDC-D05",
    block: "B",
    floor: "RDC",
    type: "S+3",
    surface: 279.79,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 1680, y: 1230, width: 670, height: 520 }
  },
  {
    code: "B-RDC-D06",
    block: "B",
    floor: "RDC",
    type: "S+3",
    surface: 311.09,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 2360, y: 1230, width: 580, height: 520 }
  },
  {
    code: "B-RDC-D08",
    block: "B",
    floor: "RDC",
    type: "S+3",
    surface: 262.71,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 1680, y: 500, width: 670, height: 620 }
  },
  {
    code: "B-RDC-D07",
    block: "B",
    floor: "RDC",
    type: "S+3",
    surface: 315.34,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 2350, y: 500, width: 600, height: 620 }
  },

  // -------- Bloc A / MEZZ --------
  {
    code: "A-MEZZ-M01",
    block: "A",
    floor: "MEZZ",
    type: "S+2",
    surface: 139.18,
    price_m2_base: 5555,
    status: "Disponible",
    rect: { x: 1670, y: 350, width: 700, height: 710 }
  },

  // -------- Bloc B / MEZZ --------
  {
    code: "B-MEZZ-D03",
    block: "B",
    floor: "MEZZ",
    type: "S+4.5",
    surface: 327.69,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 290, y:1050, width: 610, height: 700 }
  },
  {
    code: "B-MEZZ-D02",
    block: "B",
    floor: "MEZZ",
    type: "S+4.5",
    surface: 335.47,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 290, y:350, width: 610, height: 700 }
  },
  {
    code: "B-MEZZ-D01",
    block: "B",
    floor: "MEZZ",
    type: "S+3",
    surface: 283.98,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 920, y: 350, width: 700, height: 700 }
  },
  {
    code: "B-MEZZ-D04",
    block: "B",
    floor: "MEZZ",
    type: "S+3",
    surface: 260.17,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 920, y: 1050, width: 700, height: 710 }
  },
  {
    code: "B-MEZZ-D05",
    block: "B",
    floor: "MEZZ",
    type: "S+3",
    surface: 279.79,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 1630, y: 1050, width: 750, height: 710 }
  },
  {
    code: "B-MEZZ-D06",
    block: "B",
    floor: "MEZZ",
    type: "S+3",
    surface: 311.09,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 2400, y: 1050, width: 650, height: 710}
  },
  {
    code: "B-MEZZ-D08",
    block: "B",
    floor: "MEZZ",
    type: "S+3",
    surface: 262.71,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 1630, y: 350, width: 760, height: 720 }
  },
  {
    code: "B-MEZZ-D07",
    block: "B",
    floor: "MEZZ",
    type: "S+3",
    surface: 315.34,
    price_m2_base: 5404,
    status: "Disponible",
    rect: { x: 2400, y: 350, width: 620, height: 710}
  },

  // -------- Bloc A / 1er étage --------
  {
    code: "A-1-7",
    block: "A",
    floor: "1",
    type: "S+3.5",
    surface: 179.79,
    price_m2_base: 5505,
    status: "Disponible",
    rect: { x: 380, y: 1170, width: 700, height: 880 }
  },
  {
    code: "A-1-6",
    block: "A",
    floor: "1",
    type: "S+3",
    surface: 165.98,
    price_m2_base: 5505,
    status: "Disponible",
    rect: { x: 380, y: 360, width: 700, height: 780 }
  },
  {
    code: "A-1-5",
    block: "A",
    floor: "1",
    type: "S+2",
    surface: 141.66,
    price_m2_base: 5555,
    status: "Disponible",
    rect: { x: 1060, y: 360, width: 750, height: 680 }
  },
  {
    code: "A-1-4",
    block: "A",
    floor: "1",
    type: "S+2",
    surface: 142.84,
    price_m2_base: 5555,
    status: "Disponible",
     rect: { x: 1800, y: 360, width: 750, height: 680 }
  },
  {
    code: "A-1-3",
    block: "A",
    floor: "1",
    type: "S+3",
    surface: 165.73,
    price_m2_base: 5505,
    status: "Disponible",
    rect: { x: 2530, y: 350, width: 660, height:800 }
  },
  {
    code: "A-1-2",
    block: "A",
    floor: "1",
    type: "S+3.5",
    surface: 175.96,
    price_m2_base: 5505,
    status: "Disponible",
    rect: { x: 2530, y: 1180, width: 660, height:880 }
  },
  {
    code: "A-1-1",
    block: "A",
    floor: "1",
    type: "S+1",
    surface: 93.02,
    price_m2_base: 5729,
    status: "Disponible",
    rect: { x: 2120, y: 1180, width: 400, height: 880 }
  },
  {
    code: "A-1-8",
    block: "A",
    floor: "1",
    type: "S+1",
    surface: 124.70,
    price_m2_base: 5555,
    status: "Disponible",
   rect: { x: 1080, y: 1170, width: 390, height: 880 }
  },
  {
    code: "A-1-9",
    block: "A",
    floor: "1",
    type: "S+2",
    surface: 124.70,
    price_m2_base: 5555,
    status: "Disponible",
     rect: { x: 1500, y: 1180, width: 620, height: 880 }
  },

  // -------- Bloc A / 2eme   étage --------
  {
    code: "D-1-4",
    block: "A",
    floor: "2",
    type: "S+3.5",
    surface: 277.82,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 1100, y: 1150, width: 750, height: 900 }
  },
  {
    code: "D-1-3",
    block: "A",
    floor: "2",
    type: "S+4.5",
    surface: 317.59,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 400,y: 1150, width: 700, height: 900}
  },
  {
    code: "D-1-2",
    block: "A",
    floor: "2",
    type: "S+4.5",
    surface: 321.95,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 400, y: 350, width: 700, height: 800 }
  },
  {
    code: "D-1-1",
    block: "A",
    floor: "2",
    type: "S+3.5",
    surface: 312.16,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 1100, y: 350, width: 720, height: 700 }
  },
  {
    code: "D-1-8",
    block: "A",
    floor: "2",
    type: "S+3.5",
    surface: 313.23,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 1830, y: 350, width: 720, height: 700 }
  },
   {
    code: "D-1-5",
    block: "A",
   floor: "2",
    type: "S+3.5",
    surface: 263.75,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 1850, y: 1150, width: 700, height: 900}
  },
   {
    code: "D-1-6",
    block: "A",
    floor: "2",
    type: "S+3.5",
    surface: 287.56,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 2550, y: 1150, width: 700, height: 900}
  },
  {
    code: "D-1-7",
    block: "A",
    floor: "2",
    type: "S+3.5",
    surface: 309.71,
    price_m2_base: 5353,
    status: "Vendu",
    rect: { x: 2550, y: 350, width: 700, height: 800 }
  }, // -------- Bloc A / 3eme   étage --------
  {
    code: "D-1-4",
    block: "A",
    floor: "3",
    type: "S+3.5",
    surface: 277.82,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 1100, y: 1100, width: 750, height: 1000 }
  },
  {
    code: "D-1-3",
    block: "A",
    floor: "3",
    type: "S+4.5",
    surface: 317.59,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 400,y: 1150, width: 700, height: 900}
  },
  {
    code: "D-1-2",
    block: "A",
    floor: "3",
    type: "S+4.5",
    surface: 321.95,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 400, y: 350, width: 700, height: 800 }
  },
  {
    code: "D-1-1",
    block: "A",
    floor: "3",
    type: "S+3.5",
    surface: 312.16,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 1100, y: 350, width: 720, height: 700 }
  },
  {
    code: "D-1-8",
    block: "A",
    floor: "3",
    type: "S+3.5",
    surface: 313.23,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 1850, y: 360, width: 750, height: 710 }
  },
   {
    code: "D-1-5",
    block: "A",
   floor: "3",
    type: "S+3.5",
    surface: 263.75,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 1850, y: 1080, width: 750, height: 1020}
  },
   {
    code: "D-1-6",
    block: "A",
    floor: "3",
    type: "S+3.5",
    surface: 287.56,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 2610, y: 1220, width: 710, height: 900}
  },
  {
    code: "D-1-7",
    block: "A",
    floor: "3",
    type: "S+3.5",
    surface: 309.71,
    price_m2_base: 5353,
    status: "Vendu",
    rect: { x: 2610, y: 360, width: 750, height: 850 }
  },
   // -------- Bloc B / 2eme étage --------
  {
    code: "B-2-4",
    block: "B",
    floor: "2",
    type: "S+2",
    surface: 120.11,
    price_m2_base: 5555,
    status: "Disponible",
    rect: { x: 1070, y: 1380, width: 730, height: 570 }
  },
  {
    code: "B-2-3",
    block: "B",
    floor: "2",
    type: "S+3",
    surface: 168.70,
    price_m2_base: 5505,
    status: "Disponible",
    rect: { x: 360, y: 1150, width: 730, height: 850 }
  },
  {
    code: "B-2-2",
    block: "B",
    floor: "2",
    type: "S+3",
    surface: 153.40,
    price_m2_base: 5505,
    status: "Disponible",
    rect: { x: 380, y: 530, width: 880, height: 600 }
  },
  {
    code: "B-2-1",
    block: "B",
    floor: "2",
    type: "S+2",
    surface: 104.20,
    price_m2_base: 5555,
    status: "Disponible",
    rect: { x: 1260, y: 530, width: 650, height: 700 }
  },
  {
    code: "B-2-8",
    block: "B",
    floor: "2",
    type: "S+1",
    surface: 74.85,
    price_m2_base: 5729,
    status: "Disponible",
    rect: { x: 1910, y: 530, width: 430, height: 710 }
  },
   {
    code: "B-2-5",
    block: "B",
    floor: "2",
    type: "S+2",
    surface: 119.27,
    price_m2_base: 5555,
    status: "Vendu",
    rect: { x: 1800, y: 1380, width: 730, height: 570 }
  },
   {
    code: "B-2-6",
    block: "B",
    floor: "2",
    type: "S+3",
    surface: 168.47,
    price_m2_base: 5505,
    status: "Disponible",
    rect: { x: 2520, y: 1150, width: 680, height: 820 }
  },
  {
    code: "B-2-7",
    block: "B",
    floor: "2",
    type: "S+3",
    surface: 153.17,
    price_m2_base: 5505,
    status: "Vendu",
    rect: { x: 2350, y: 530, width: 860, height: 600 }
  },
  // -------- Bloc B / 2eme étage --------
  {
    code: "B-3-4",
    block: "B",
    floor: "3",
    type: "S+4.5",
    surface: 338.42,
    price_m2_base: 5353,
    status: "Disponible",
    rect: { x: 2420, y: 550, width: 900, height: 1470 }
  },
  {
    code: "B-3-3",
    block: "B",
    floor: "3",
    type: "S+2",
    surface: 142.60,
    price_m2_base: 5555,
    status: "Disponible",
    rect: { x: 1540, y: 1400, width: 850, height: 630 }
  },
  {
    code: "B-3-2",
    block: "B",
    floor: "3",
    type: "S+3",
    surface: 206.88,
    price_m2_base: 5505,
    status: "Disponible",
    rect: { x: 380, y: 1300, width: 1150, height: 730 }
  },
  {
    code: "B-3-1",
    block: "B",
    floor: "3",
    type: "S+3",
    surface: 190.42,
    price_m2_base: 5505,
    status: "Disponible",
    rect: { x: 380, y: 560, width: 1150, height: 730 }
  },
 
   {
    code: "B-3-5",
    block: "B",
    floor: "3",
    type: "S+2",
    surface: 116.02,
    price_m2_base: 5555,
    status: "Vendu",
    rect: { x: 1540, y: 560, width: 870, height: 730 },
    plan_image: "assets/unit_plans/B-3-1.jpg"
  }
];


// ==============================
// UTILS PRIX
// ==============================

function monthsSinceBase(baseMonthStr) {
  const [y, m] = baseMonthStr.split("-").map(Number);
  const base = new Date(y, m - 1, 1);
  const now = new Date();
  const months =
    (now.getFullYear() - base.getFullYear()) * 12 +
    (now.getMonth() - base.getMonth());
  return Math.max(0, months);
}

const MONTHS_DELTA = monthsSinceBase(BASE_MONTH);

// prix HT/m² indexé (base + 0.5%/mois)
function priceForCurrentMonth(priceBase) {
  const factor = Math.pow(1 + MONTHLY_INCREASE / 100, MONTHS_DELTA);
  return Math.round(priceBase * factor * 100) / 100;
}

function fmtMoney(n) {
  return (Math.round(n * 100) / 100).toLocaleString("fr-FR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

// ==============================
// ÉTAT + DOM
// ==============================

const state = {
  block: "B",          // Bloc B par défaut
  floor: FLOORS[0].id, // RDC
  selectedCode: null,
  statusOverrides: {}  // sera chargé depuis le serveur
};

const els = {};

function cacheDom() {
  els.block = document.getElementById("selectBlock");
  els.floor = document.getElementById("selectFloor");
  els.planImage = document.getElementById("planImage");
  els.overlay = document.getElementById("unitOverlay");

  els.unitEmpty = document.getElementById("unitEmpty");
  els.unitCard = document.getElementById("unitCard");

  els.uCode = document.getElementById("uCode");
  els.uMeta = document.getElementById("uMeta");
  els.uBlock = document.getElementById("uBlock");
  els.uFloor = document.getElementById("uFloor");
  els.uType = document.getElementById("uType");
  els.uSurface = document.getElementById("uSurface");

  // section client (colonne de droite)
  els.clientSection  = document.getElementById("clientSection");
  els.cName          = document.getElementById("cName");
  els.cPhone         = document.getElementById("cPhone");
  els.cEmail         = document.getElementById("cEmail");
  els.promiseSection = document.getElementById("promiseSection");
  els.promiseBtn     = document.getElementById("promiseBtn");

  els.uPriceBase  = document.getElementById("uPriceBase");
  els.uPriceMonth = document.getElementById("uPriceMonth");
  els.uTotalMonth = document.getElementById("uTotalMonth");

  els.uStatusBadge  = document.getElementById("uStatusBadge");
  els.uStatusSelect = document.getElementById("uStatusSelect");
  els.uSaveBtn      = document.getElementById("uSaveBtn");

  // aperçu zoomé du plan
  els.uPreviewImg = document.getElementById("unitPreviewImg");
  els.uPreviewBox = document.querySelector(".unit-preview");
}

// ==============================
// API STATUTS
// ==============================

async function fetchStatusOverrides() {
  try {
    const res = await fetch(STATUS_GET_URL + "?_=" + Date.now());
    if (!res.ok) return {};
    return await res.json();
  } catch (e) {
    console.warn("Impossible de charger les statuts distants", e);
    return {};
  }
}

async function pushStatusOverrides(map) {
  try {
    await fetch(STATUS_SAVE_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(map)
    });
  } catch (e) {
    console.warn("Impossible d'enregistrer les statuts distants", e);
  }
}

// ==============================
// INIT
// ==============================

function initFloorSelect() {
  els.floor.innerHTML = "";
  FLOORS.forEach((f) => {
    const opt = document.createElement("option");
    opt.value = f.id;
    opt.textContent = f.label;
    els.floor.appendChild(opt);
  });
}

// retourne le plan correspondant bloc + étage
function getFloorImage(block, floor) {
  if (FLOOR_IMAGES[block] && FLOOR_IMAGES[block][floor]) {
    return FLOOR_IMAGES[block][floor];
  }
  return "assets/plans/RDC.png"; // fallback
}

function getUnitsWithStatusOverrides() {
  const map = state.statusOverrides;
  return UNITS.map((u) => ({
    ...u,
    status: map[u.code] || u.status
  }));
}

function getUnitsForCurrentPlan() {
  return getUnitsWithStatusOverrides().filter(
    (u) => u.block === state.block && u.floor === state.floor && u.rect
  );
}

function loadPlanImage() {
  const imgSrc = getFloorImage(state.block, state.floor);
  els.planImage.src = imgSrc;

  els.planImage.onload = () => {
    drawUnits();
  };
}

// ==============================
// DESSIN DES RECTANGLES
// ==============================

function drawUnits() {
  const img = els.planImage;
  const overlay = els.overlay;
  overlay.innerHTML = "";

  const units = getUnitsForCurrentPlan();
  if (!units.length || !img.naturalWidth || !img.naturalHeight) return;

  const scaleX = img.clientWidth / img.naturalWidth;
  const scaleY = img.clientHeight / img.naturalHeight;

  overlay.style.width = img.clientWidth + "px";
  overlay.style.height = img.clientHeight + "px";

  units.forEach((u) => {
    const div = document.createElement("div");
    div.classList.add("unit-rect");

    const statusClass = statusToRectClass(u.status);
    if (statusClass) div.classList.add(statusClass);

    div.style.left   = u.rect.x * scaleX + "px";
    div.style.top    = u.rect.y * scaleY + "px";
    div.style.width  = u.rect.width  * scaleX + "px";
    div.style.height = u.rect.height * scaleY + "px";

    const label = document.createElement("span");
    label.textContent = u.code;
    div.appendChild(label);

    div.addEventListener("click", (e) => {
      e.stopPropagation();
      selectUnit(u.code);
    });

    overlay.appendChild(div);
  });
}

function statusToRectClass(status) {
  switch (status) {
    case "Disponible": return "unit-dispo";
    case "Réservé":    return "unit-reserve";
    case "Vendu":      return "unit-vendu";
    default:           return null;
  }
}

function statusToBadgeClass(status) {
  switch (status) {
    case "Disponible": return "badge-dispo";
    case "Réservé":    return "badge-reserve";
    case "Vendu":      return "badge-vendu";
    default:           return "";
  }
}

// ==============================
// APERÇU ZOOMÉ À DROITE
// ==============================

function updateUnitPreview(unit) {
  if (!els.uPreviewImg || !els.uPreviewBox) return;

  const imgSrc = getFloorImage(unit.block, unit.floor);
  if (!imgSrc) return;

  const img = els.uPreviewImg;
  img.onload = () => {
    const rect = unit.rect;
    const natW = img.naturalWidth;
    const natH = img.naturalHeight;
    if (!natW || !natH) return;

    const boxW = els.uPreviewBox.clientWidth;
    const boxH = els.uPreviewBox.clientHeight;

    const zoom = Math.min(boxW / rect.width, boxH / rect.height) * 1.1;

    const scaledW = natW * zoom;
    const scaledH = natH * zoom;

    img.style.width  = scaledW + "px";
    img.style.height = scaledH + "px";

    const offsetX = rect.x * zoom;
    const offsetY = rect.y * zoom;

    const extraX = (boxW - rect.width * zoom) / 2;
    const extraY = (boxH - rect.height * zoom) / 2;

    img.style.transform =
      `translate(${-offsetX + extraX}px, ${-offsetY + extraY}px)`;
  };

  img.src = imgSrc;
}

// ==============================
// SÉLECTION D’UNE UNITÉ
// ==============================

function selectUnit(code) {
  state.selectedCode = code;
  const unitBase = UNITS.find((u) => u.code === code);
  if (!unitBase) return;

  const effectiveStatus =
    state.statusOverrides[code] || unitBase.status || "Disponible";

  const unit = { ...unitBase, status: effectiveStatus };

  els.unitEmpty.style.display = "none";
  els.unitCard.classList.remove("hidden");

  els.uCode.textContent = unit.code;
  els.uMeta.textContent = `Bloc ${unit.block} · Étage ${formatFloorLabel(
    unit.floor
  )}`;

  els.uBlock.textContent = unit.block;
  els.uFloor.textContent = formatFloorLabel(unit.floor);
  els.uType.textContent = unit.type;
  els.uSurface.textContent = `${unit.surface.toString().replace(".", ",")} m²`;

  // --- CALCULS PRIX ---

  const price_ht_m2  = priceForCurrentMonth(unit.price_m2_base);
  const price_ttc_m2 = price_ht_m2 * 1.19;

  const total_ht  = price_ht_m2  * unit.surface;
  const total_ttc = price_ttc_m2 * unit.surface;

  els.uPriceBase.textContent =
    fmtMoney(price_ht_m2) + " TND/m² HT (indexé depuis " + BASE_MONTH + ")";

  els.uPriceMonth.textContent =
    fmtMoney(price_ttc_m2) + " TND/m² TTC";

  els.uTotalMonth.textContent =
    fmtMoney(total_ht) + " TND HT • " +
    fmtMoney(total_ttc) + " TND TTC";

  // --- STATUT ---

  els.uStatusBadge.textContent =
    unit.status;
  els.uStatusBadge.className =
    "status-badge " + statusToBadgeClass(unit.status);

  els.uStatusSelect.value = unit.status;

  els.uSaveBtn.onclick = async () => {
    const newStatus = els.uStatusSelect.value;
    const overrides = { ...state.statusOverrides };

    overrides[unit.code] = newStatus;

    const linked = getLinkedCodes(unit.code);
    linked.forEach((c) => {
      overrides[c] = newStatus;
    });

    state.statusOverrides = overrides;

    await pushStatusOverrides(overrides);

    selectUnit(unit.code); // refresh
    drawUnits();
  };

  updateUnitPreview(unit);
  loadClientForUnit(unit.code);
}

function formatFloorLabel(id) {
  const f = FLOORS.find((x) => x.id === id);
  return f ? f.label : id;
}

// ==============================
// WIRING
// ==============================

function wireEvents() {
  els.block.addEventListener("change", () => {
    state.block = els.block.value;
    state.selectedCode = null;
    els.unitCard.classList.add("hidden");
    els.unitEmpty.style.display = "grid";
    loadPlanImage();
  });

  els.floor.addEventListener("change", () => {
    state.floor = els.floor.value;
    state.selectedCode = null;
    els.unitCard.classList.add("hidden");
    els.unitEmpty.style.display = "grid";
    loadPlanImage();
  });

  els.planImage.addEventListener("click", () => {
    state.selectedCode = null;
    els.unitCard.classList.add("hidden");
    els.unitEmpty.style.display = "grid";
  });

  if (els.promiseBtn) {
    els.promiseBtn.addEventListener("click", () => {
      if (!state.selectedCode) {
        alert("Veuillez sélectionner d'abord une unité.");
        return;
      }
      const url = "promesse.php?unit_code=" + encodeURIComponent(state.selectedCode);
      window.open(url, "_blank");
    });
  }
}

// ==============================
// MAIN
// ==============================

async function init() {
  cacheDom();
  initFloorSelect();

  state.block = els.block.value;
  state.floor = els.floor.value;

  wireEvents();
  loadPlanImage();

  const remote = await fetchStatusOverrides();
  state.statusOverrides = remote || {};
  drawUnits();

  
  if (DEBUG_RECT_PICKER) {
    setupRectPicker();
  }
}

// =====================================================
// FICHE CLIENT – Modal
// =====================================================

let pendingClientUnit = null;

function openClientModal(unit) {
  const modal     = document.getElementById("clientModal");
  const spanUnit  = document.getElementById("fcUnit");
  const inputCode = document.getElementById("fcUnitCode");
  const textarea  = document.getElementById("fcObjet");

  if (!modal || !spanUnit || !inputCode || !textarea) return;

  pendingClientUnit = unit;

  spanUnit.textContent = unit.code;
  inputCode.value      = unit.code;
  textarea.value       =
    `Vente unité ${unit.code} – Surface ${unit.surface} m² – Type ${unit.type}`;

  modal.classList.remove("hidden");
}

function closeClientModal() {
  const modal = document.getElementById("clientModal");
  if (modal) modal.classList.add("hidden");
  pendingClientUnit = null;
}

// Ecouteurs pour la fiche client
document.addEventListener("DOMContentLoaded", () => {
  const statusSelect = document.getElementById("uStatusSelect");
  const clientForm   = document.getElementById("clientForm");
  const cancelBtn    = document.getElementById("clientCancelBtn");

  if (statusSelect) {
    statusSelect.addEventListener("change", () => {
      if (statusSelect.value === "Vendu" && state && state.selectedCode) {
        const unit = UNITS.find(u => u.code === state.selectedCode);
        if (unit) {
          openClientModal(unit);
        }
      }
    });
  }

  if (cancelBtn) {
    cancelBtn.addEventListener("click", () => {
      closeClientModal();
    });
  }

  if (clientForm) {
    clientForm.addEventListener("submit", async (e) => {
      e.preventDefault();

      if (!pendingClientUnit) {
        closeClientModal();
        return;
      }

      const formData = new FormData(clientForm);
      const data = Object.fromEntries(formData.entries());

      try {
        const res = await fetch("api/client_save.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(data)
        });

        const json = await res.json();
        if (!res.ok || !json || json.error) {
          alert("Erreur lors de l'enregistrement de la fiche client.");
          return;
        }

        if (els.uStatusSelect) {
          els.uStatusSelect.value = "Vendu";
        }
        if (els.uSaveBtn) {
          els.uSaveBtn.click();
        }

        if (els.clientSection) {
          els.clientSection.style.display = "block";
        }
        if (els.cName && data.nom) {
          els.cName.textContent = data.nom;
        }
        if (els.cPhone && data.tel) {
          els.cPhone.textContent = data.tel;
        }
        if (els.cEmail && data.email) {
          els.cEmail.textContent = data.email;
        }

        alert("Fiche client enregistrée et appartement marqué comme vendu.");
        closeClientModal();

        if (pendingClientUnit && pendingClientUnit.code) {
          loadClientForUnit(pendingClientUnit.code);
        }

      } catch (err) {
        console.error(err);
        alert("Erreur réseau lors de l'enregistrement de la fiche client.");
      }
    });
  }
});

// ==============================
// CHARGER LES COORDONNÉES CLIENT POUR UNE UNITÉ
// ==============================

async function loadClientForUnit(code) {
  if (!els.clientSection) return;

  els.clientSection.style.display = "none";
  if (els.cName)  els.cName.textContent  = "";
  if (els.cPhone) els.cPhone.textContent = "";
  if (els.cEmail) els.cEmail.textContent = "";

  try {
    const res = await fetch(
      CLIENT_GET_URL + "?unit_code=" + encodeURIComponent(code) + "&_=" + Date.now()
    );
    if (!res.ok) return;

    const data = await res.json();
    if (!data || data.error || !data.id) {
      return;
    }

    const nom  = data.nom_prenom || data.nom || "";
    const tel  = data.telephone  || data.tel || "";
    const mail = data.email      || "";

    if (els.cName)  els.cName.textContent  = nom;
    if (els.cPhone) els.cPhone.textContent = tel;
    if (els.cEmail) els.cEmail.textContent = mail;

    els.clientSection.style.display = "block";

    if (els.promiseSection) {
      els.promiseSection.style.display = "block";
    }

  } catch (e) {
    console.warn("Erreur chargement client", e);
  }
}
// =====================================================
// MODE DEBUG : PICKER DE RECTANGLES SUR LE PLAN
// =====================================================

const DEBUG_RECT_PICKER = true; // passe à false quand tu as fini
let pickStart = null;

function setupRectPicker() {
  // on écoute les clics sur l’OVERLAY (au-dessus de l’image)
  const target = els.overlay || els.planImage;
  if (!target || !els.planImage) return;

  target.addEventListener("click", function (e) {
    // on ne déclenche que si on maintient ALT pour ne pas gêner l'usage normal
    if (!e.altKey) return;

    const img = els.planImage;
    const bounds = img.getBoundingClientRect();

    // coordonnées cliquées dans l'image visible (en px)
    const clickX = e.clientX - bounds.left;
    const clickY = e.clientY - bounds.top;

    // rapport vers la taille ORIGINALE du plan
    const natW = img.naturalWidth;
    const natH = img.naturalHeight;
    if (!natW || !natH) return;

    const scaleX = natW / img.clientWidth;
    const scaleY = natH / img.clientHeight;

    const xNat = Math.round(clickX * scaleX);
    const yNat = Math.round(clickY * scaleY);

    if (!pickStart) {
      // 1er clic = coin haut gauche
      pickStart = { x: xNat, y: yNat };
    } else {
      pickStart = null;
    }
  });
}


// lancer l’init principal
document.addEventListener("DOMContentLoaded", init);
