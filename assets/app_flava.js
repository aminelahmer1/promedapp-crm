// ==============================
// CONFIG GLOBALE FLAVA
// ==============================

// Mois de base du prix (ton tableau Novembre)
const BASE_MONTH = "2026-05"; // AAAA-MM (DB mis à jour mai 2026)
const MONTHLY_INCREASE = 0.5; // +0,5 % / mois

// URLs pour les statuts partagés (à créer sur Hostinger)
const STATUS_GET_URL  = "api/status_get.php";
const STATUS_SAVE_URL = "api/status_save.php";
const CLIENT_GET_URL  = "api/client_get.php";
const PROJECT_UNITS_URL = "api/project_units.php?project=FLAVA";
const PROJECT_ACCESSORIES_URL = "api/project_accessories.php?project=FLAVA";

// Étages FLAVA
const FLOORS = [
  { id: "SOUS-SOL", label: "Sous-sol" },
  { id: "RDC",  label: "RDC" },
  { id: "1",    label: "1er étage" },
  { id: "2",    label: "2ème étage" },
  { id: "3",    label: "3ème étage" },
  { id: "MEZZ", label: "Mezzanine" }
];

// Plans par bloc + étage (adapter l’extension .png si besoin)
const FLOOR_IMAGES = {
  A: {
    "SOUS-SOL": "assets/plans/flava/SOUS-SOL.png",
    "RDC":  "assets/plans/flava/RDCA.png",
    "1":    "assets/plans/flava/1eretageA.jpg",
    "2":    "assets/plans/flava/2emeA.jpg",
    "3":    "assets/plans/flava/3emeA.jpg",
    "MEZZ": "assets/plans/flava/MEZZANINEA.jpg"
  },
  B: {
    "SOUS-SOL": "assets/plans/flava/SOUS-SOL.png",
    "RDC":  "assets/plans/flava/RDCB.png",
    "1":    "assets/plans/flava/1eretageB.jpg",
    "2":    "assets/plans/flava/2emeB.jpg",
    "3":    "assets/plans/flava/3emeB.jpg",
    "MEZZ": "assets/plans/flava/MEZZANINEB.jpg"
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


let UNITS = [
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
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 400, y: 1140, width: 600, height: 610 }
  },
  {
    code: "B-RDC-D02",
    block: "B",
    floor: "RDC",
    type: "S+4.5",
    surface: 335.47,
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 400, y: 500, width: 600, height: 620 }
  },
  {
    code: "B-RDC-D01",
    block: "B",
    floor: "RDC",
    type: "S+3",
    surface: 283.98,
    price_m2_base: 5568,
    status: "Disponible",
     rect: { x: 1000, y: 500, width: 670, height: 620 }
  },
  {
    code: "B-RDC-D04",
    block: "B",
    floor: "RDC",
    type: "S+3",
    surface: 260.17,
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 1000, y: 1230, width: 555, height: 520 }
  },
  {
    code: "B-RDC-D05",
    block: "B",
    floor: "RDC",
    type: "S+3",
    surface: 279.79,
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 1680, y: 1230, width: 670, height: 520 }
  },
  {
    code: "B-RDC-D06",
    block: "B",
    floor: "RDC",
    type: "S+3",
    surface: 311.09,
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 2360, y: 1230, width: 580, height: 520 }
  },
  {
    code: "B-RDC-D08",
    block: "B",
    floor: "RDC",
    type: "S+3",
    surface: 262.71,
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 1680, y: 500, width: 670, height: 620 }
  },
  {
    code: "B-RDC-D07",
    block: "B",
    floor: "RDC",
    type: "S+3",
    surface: 315.34,
    price_m2_base: 5568,
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
    price_m2_base: 5724,
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
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 2400, y: 350, width: 620, height: 710}
  },
  {
    code: "B-MEZZ-D02",
    block: "B",
    floor: "MEZZ",
    type: "S+4.5",
    surface: 335.47,
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 2400, y: 1050, width: 650, height: 710}
  },
  {
    code: "B-MEZZ-D01",
    block: "B",
    floor: "MEZZ",
    type: "S+3",
    surface: 283.98,
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 1630, y: 1050, width: 750, height: 710 }
  },
  {
    code: "B-MEZZ-D04",
    block: "B",
    floor: "MEZZ",
    type: "S+3",
    surface: 260.17,
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 1630, y: 350, width: 760, height: 720 }
  },
  {
    code: "B-MEZZ-D05",
    block: "B",
    floor: "MEZZ",
    type: "S+3",
    surface: 279.79,
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 920, y: 350, width: 700, height: 700 }
  },
  {
    code: "B-MEZZ-D06",
    block: "B",
    floor: "MEZZ",
    type: "S+3",
    surface: 311.09,
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 290, y:350, width: 610, height: 700 }
  },
  {
    code: "B-MEZZ-D08",
    block: "B",
    floor: "MEZZ",
    type: "S+3",
    surface: 262.71,
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 920, y: 1050, width: 700, height: 710 }
  },
  {
    code: "B-MEZZ-D07",
    block: "B",
    floor: "MEZZ",
    type: "S+3",
    surface: 315.34,
    price_m2_base: 5568,
    status: "Disponible",
    rect: { x: 290, y:1050, width: 610, height: 700 }
  },

  // -------- Bloc A / 1er étage --------
  {
    code: "A-1-7",
    block: "A",
    floor: "1",
    type: "S+3.5",
    surface: 179.79,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 380, y: 1170, width: 700, height: 880 }
  },
  {
    code: "A-1-6",
    block: "A",
    floor: "1",
    type: "S+3",
    surface: 165.98,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 380, y: 360, width: 700, height: 780 }
  },
  {
    code: "A-1-5",
    block: "A",
    floor: "1",
    type: "S+2",
    surface: 141.66,
    price_m2_base: 5724,
    status: "Disponible",
    rect: { x: 1060, y: 360, width: 750, height: 680 }
  },
  {
    code: "A-1-4",
    block: "A",
    floor: "1",
    type: "S+2",
    surface: 142.84,
    price_m2_base: 5724,
    status: "Disponible",
     rect: { x: 1800, y: 360, width: 750, height: 680 }
  },
  {
    code: "A-1-3",
    block: "A",
    floor: "1",
    type: "S+3",
    surface: 165.73,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 2530, y: 350, width: 660, height:800 }
  },
  {
    code: "A-1-2",
    block: "A",
    floor: "1",
    type: "S+3.5",
    surface: 175.96,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 2530, y: 1180, width: 660, height:880 }
  },
  {
    code: "A-1-1",
    block: "A",
    floor: "1",
    type: "S+1",
    surface: 93.02,
    price_m2_base: 5932,
    status: "Disponible",
    rect: { x: 2120, y: 1180, width: 400, height: 880 }
  },
  {
    code: "A-1-8",
    block: "A",
    floor: "1",
    type: "S+1",
    surface: 124.70,
    price_m2_base: 5724,
    status: "Disponible",
   rect: { x: 1080, y: 1170, width: 390, height: 880 }
  },
  {
    code: "A-1-9",
    block: "A",
    floor: "1",
    type: "S+2",
    surface: 124.70,
    price_m2_base: 5724,
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
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 1830, y: 350, width: 720, height: 700 }
  },
  {
    code: "D-1-3",
    block: "A",
    floor: "2",
    type: "S+4.5",
    surface: 317.59,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 2550, y: 350, width: 700, height: 800 }
  },
  {
    code: "D-1-2",
    block: "A",
    floor: "2",
    type: "S+4.5",
    surface: 321.95,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 2550, y: 1150, width: 700, height: 900}
  },
  {
    code: "D-1-1",
    block: "A",
    floor: "2",
    type: "S+3.5",
    surface: 312.16,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 1850, y: 1150, width: 700, height: 900}
  },
  {
    code: "D-1-8",
    block: "A",
    floor: "2",
    type: "S+3.5",
    surface: 313.23,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 1100, y: 1150, width: 750, height: 900 }
  },
   {
    code: "D-1-5",
    block: "A",
   floor: "2",
    type: "S+3.5",
    surface: 263.75,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 1100, y: 350, width: 720, height: 700 }
  },
   {
    code: "D-1-6",
    block: "A",
    floor: "2",
    type: "S+3.5",
    surface: 287.56,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 400, y: 350, width: 700, height: 800 }
  },
  {
    code: "D-1-7",
    block: "A",
    floor: "2",
    type: "S+3.5",
    surface: 309.71,
    price_m2_base: 5516,
    status: "Vendu",
    rect: { x: 400,y: 1150, width: 700, height: 900}
  }, // -------- Bloc A / 3eme   étage --------
  {
    code: "D-1-4",
    block: "A",
    floor: "3",
    type: "S+3.5",
    surface: 277.82,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 1850, y: 360, width: 750, height: 710 }
  },
  {
    code: "D-1-3",
    block: "A",
    floor: "3",
    type: "S+4.5",
    surface: 317.59,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 2610, y: 360, width: 750, height: 850 }
  },
  {
    code: "D-1-2",
    block: "A",
    floor: "3",
    type: "S+4.5",
    surface: 321.95,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 2610, y: 1220, width: 710, height: 900}
  },
  {
    code: "D-1-1",
    block: "A",
    floor: "3",
    type: "S+3.5",
    surface: 312.16,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 1850, y: 1080, width: 750, height: 1020}
  },
  {
    code: "D-1-8",
    block: "A",
    floor: "3",
    type: "S+3.5",
    surface: 313.23,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 1100, y: 1100, width: 750, height: 1000 }
  },
   {
    code: "D-1-5",
    block: "A",
   floor: "3",
    type: "S+3.5",
    surface: 263.75,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 1100, y: 350, width: 720, height: 700 }
  },
   {
    code: "D-1-6",
    block: "A",
    floor: "3",
    type: "S+3.5",
    surface: 287.56,
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 400, y: 350, width: 700, height: 800 }
  },
  {
    code: "D-1-7",
    block: "A",
    floor: "3",
    type: "S+3.5",
    surface: 309.71,
    price_m2_base: 5516,
    status: "Vendu",
    rect: { x: 400,y: 1150, width: 700, height: 900}
  },
   // -------- Bloc B / 2eme étage --------
  // -------- Bloc B / 1er etage --------
  {
    code: "B-1-4",
    block: "B",
    floor: "1",
    type: "S+2",
    surface: 121.34,
    price_m2_base: 5724,
    status: "Disponible",
    rect: { x: 1070, y: 1380, width: 730, height: 570 }
  },
  {
    code: "B-1-3",
    block: "B",
    floor: "1",
    type: "S+3",
    surface: 170.15,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 360, y: 1150, width: 730, height: 850 }
  },
  {
    code: "B-1-2",
    block: "B",
    floor: "1",
    type: "S+3",
    surface: 154.79,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 380, y: 530, width: 880, height: 600 }
  },
  {
    code: "B-1-1",
    block: "B",
    floor: "1",
    type: "S+2",
    surface: 105.16,
    price_m2_base: 5724,
    status: "Disponible",
    rect: { x: 1260, y: 530, width: 650, height: 700 }
  },
  {
    code: "B-1-8",
    block: "B",
    floor: "1",
    type: "S+1",
    surface: 75.60,
    price_m2_base: 5932,
    status: "Disponible",
    rect: { x: 1910, y: 530, width: 430, height: 710 }
  },
  {
    code: "B-1-5",
    block: "B",
    floor: "1",
    type: "S+2",
    surface: 120.54,
    price_m2_base: 5724,
    status: "Disponible",
    rect: { x: 1800, y: 1380, width: 730, height: 570 }
  },
  {
    code: "B-1-6",
    block: "B",
    floor: "1",
    type: "S+3",
    surface: 169.90,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 2520, y: 1150, width: 680, height: 820 }
  },
  {
    code: "B-1-7",
    block: "B",
    floor: "1",
    type: "S+3",
    surface: 154.53,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 2350, y: 530, width: 860, height: 600 }
  },
  // -------- Bloc B / 2eme etage --------
  {
    code: "B-2-4",
    block: "B",
    floor: "2",
    type: "S+2",
    surface: 120.11,
    price_m2_base: 5724,
    status: "Disponible",
    rect: { x: 1070, y: 1380, width: 730, height: 570 }
  },
  {
    code: "B-2-3",
    block: "B",
    floor: "2",
    type: "S+3",
    surface: 168.70,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 360, y: 1150, width: 730, height: 850 }
  },
  {
    code: "B-2-2",
    block: "B",
    floor: "2",
    type: "S+3",
    surface: 153.40,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 380, y: 530, width: 880, height: 600 }
  },
  {
    code: "B-2-1",
    block: "B",
    floor: "2",
    type: "S+2",
    surface: 104.20,
    price_m2_base: 5724,
    status: "Disponible",
    rect: { x: 1260, y: 530, width: 650, height: 700 }
  },
  {
    code: "B-2-8",
    block: "B",
    floor: "2",
    type: "S+1",
    surface: 74.85,
    price_m2_base: 5932,
    status: "Disponible",
    rect: { x: 1910, y: 530, width: 430, height: 710 }
  },
   {
    code: "B-2-5",
    block: "B",
    floor: "2",
    type: "S+2",
    surface: 119.27,
    price_m2_base: 5724,
    status: "Vendu",
    rect: { x: 1800, y: 1380, width: 730, height: 570 }
  },
   {
    code: "B-2-6",
    block: "B",
    floor: "2",
    type: "S+3",
    surface: 168.47,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 2520, y: 1150, width: 680, height: 820 }
  },
  {
    code: "B-2-7",
    block: "B",
    floor: "2",
    type: "S+3",
    surface: 153.17,
    price_m2_base: 5672,
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
    price_m2_base: 5516,
    status: "Disponible",
    rect: { x: 2420, y: 550, width: 900, height: 1470 }
  },
  {
    code: "B-3-3",
    block: "B",
    floor: "3",
    type: "S+2",
    surface: 142.60,
    price_m2_base: 5724,
    status: "Disponible",
    rect: { x: 1540, y: 1400, width: 850, height: 630 }
  },
  {
    code: "B-3-2",
    block: "B",
    floor: "3",
    type: "S+3",
    surface: 206.88,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 380, y: 1300, width: 1150, height: 730 }
  },
  {
    code: "B-3-1",
    block: "B",
    floor: "3",
    type: "S+3",
    surface: 190.42,
    price_m2_base: 5672,
    status: "Disponible",
    rect: { x: 380, y: 560, width: 1150, height: 730 }
  },
 
   {
    code: "B-3-5",
    block: "B",
    floor: "3",
    type: "S+2",
    surface: 116.02,
    price_m2_base: 5724,
    status: "Vendu",
    rect: { x: 1540, y: 560, width: 870, height: 730 },
    plan_image: "assets/unit_plans/B-3-1.jpg"
  }
];



function normalizeStatusValue(status) {
  const s = (status || '').toString().trim().toLowerCase();
  if (s === 'd' || s === 'disponible') return 'Disponible';
  if (s === 'r' || s === 'réservé' || s === 'reserve') return 'Réservé';
  if (s === 'v' || s === 'vendu') return 'Vendu';
  return status || 'Disponible';
}

function normalizeFloorValue(floor) {
  const raw = (floor || '').toString().trim();
  const f = raw
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toUpperCase();
  if (f === 'MEZZANINE' || f === 'MEZZ') return 'MEZZ';
  if (f === 'RDC') return 'RDC';
  if (f === 'SOUS-SOL' || f === 'SOUS SOL') return 'SOUS-SOL';
  if (f.startsWith('1')) return '1';
  if (f.startsWith('2')) return '2';
  if (f.startsWith('3')) return '3';
  return raw || '';
}

function normalizeBlockValue(block) {
  const b = (block || '').toString().trim().toUpperCase();
  if (b === 'BLOC A') return 'A';
  if (b === 'BLOC B') return 'B';
  return block || '';
}

function normalizeAccessoryType(t) {
  const u = (t || '').toString().trim().toUpperCase();
  if (u === 'PARKING') return 'Parking';
  if (u === 'CELLIER') return 'Cellier';
  return t || '';
}

function normalizeCodeValue(code) {
  return (code || '').toString().trim().toUpperCase();
}

function getFlavaCanonicalUnitCode(code) {
  const value = normalizeCodeValue(code);
  let match = null;

  match = value.match(/^A-RDC-LC0?(\d{1,2})$/);
  if (match) return `LC ${Number(match[1])}`;

  if (value === "A-MEZZ-M01") return "M01";

  match = value.match(/^([AB])-([123])-(\d{1,2})$/);
  if (match) return `${match[1]}${match[2]}-${Number(match[3])}`;

  match = value.match(/^D-1-(\d{1,2})$/);
  if (match) return `D1-${Number(match[1])}`;

  match = value.match(/^B-(?:RDC|MEZZ)-D0?(\d{1,2})$/);
  if (match) return `D0-${Number(match[1])}`;

  return value;
}

function getFlavaUnitPlanFallback(code) {
  const canonical = getFlavaCanonicalUnitCode(code);
  let match;

  match = canonical.match(/^D0-(\d{1,2})$/);
  if (match) return `uploads/plans/units/flava/D0-${Number(match[1])}.jpg`;

  match = canonical.match(/^D1-(\d{1,2})$/);
  if (match) return `uploads/plans/units/flava/D1-${Number(match[1])}.jpg`;

  match = canonical.match(/^LC (\d{1,2})$/);
  if (match) return `uploads/plans/units/flava/Boutique ${String(Number(match[1])).padStart(2,'0')}.jpg`;

  if (canonical === "M01") return "uploads/plans/units/flava/M01.jpg";

  /* Appartements A1-x, B1-x, B2-x, B3-x */
  if (/^[AB][123]-\d{1,2}$/.test(canonical))
    return `uploads/plans/units/flava/${canonical}.jpg`;

  return "";
}

function mergeDynamicUnits(dynamicUnits) {
  const layoutMap = new Map();
  UNITS.forEach((unit) => {
    const key = getFlavaCanonicalUnitCode(unit.code);
    if (!layoutMap.has(key)) layoutMap.set(key, []);
    layoutMap.get(key).push(unit);
  });
  const merged = [];
  const seen = new Set();

  (dynamicUnits || []).forEach((item) => {
    const codeKey = getFlavaCanonicalUnitCode(item.db_code || item.code);
    if (!codeKey) return;
    const layouts = layoutMap.get(codeKey) || [];
    const planImage = item.plan_image || getFlavaUnitPlanFallback(codeKey);

    if (layouts.length) {
      layouts.forEach((layout) => {
        merged.push({
          ...layout,
          ...item,
          code: layout.code || item.code,
          db_code: item.db_code || item.code || getFlavaCanonicalUnitCode(layout.code),
          block: normalizeBlockValue(item.block || layout.block || ''),
          floor: normalizeFloorValue(layout.floor || item.floor || ''),
          type: item.type || layout.type || '',
          surface: Number(item.surface ?? layout.surface ?? 0),
          price_m2_base: Number(item.price_m2_base ?? layout.price_m2_base ?? 0),
          status: normalizeStatusValue(item.status || layout.status || 'Disponible'),
          rect: layout.rect || item.rect || null,
          plan_image: planImage || layout.plan_image || ''
        });
        seen.add(layout);
      });
      return;
    }

    merged.push({
      ...item,
      db_code: item.db_code || item.code || codeKey,
      code: item.code || codeKey,
      block: normalizeBlockValue(item.block || ''),
      floor: normalizeFloorValue(item.floor || ''),
      type: item.type || '',
      surface: Number(item.surface ?? 0),
      price_m2_base: Number(item.price_m2_base ?? 0),
      status: normalizeStatusValue(item.status || 'Disponible'),
      rect: item.rect || null,
      plan_image: planImage || ''
    });
  });

  UNITS.forEach((item) => {
    if (seen.has(item)) return;
    merged.push({
      ...item,
      db_code: item.db_code || getFlavaCanonicalUnitCode(item.code),
      plan_image: item.plan_image || getFlavaUnitPlanFallback(item.code),
      status: normalizeStatusValue(item.status || 'Disponible')
    });
  });

  UNITS = merged;
}

async function fetchDynamicUnits() {
  try {
    const res = await fetch(PROJECT_UNITS_URL + '&_=' + Date.now());
    if (!res.ok) return;
    const payload = await res.json();
    if (!payload || !Array.isArray(payload.units)) return;
    mergeDynamicUnits(payload.units);
  } catch (e) {
    console.warn('Impossible de charger les unités dynamiques', e);
  }
}

function mergeDynamicAccessories(accessories) {
  const layoutMap = new Map(UNITS.map(u => [normalizeCodeValue(u.code), u]));
  const merged = [];
  const seen = new Set();

  (accessories || []).forEach((item) => {
    const codeKey = normalizeCodeValue(item.code);
    if (!codeKey) return;
    const layout = layoutMap.get(codeKey) || {};
    merged.push({
      ...layout,
      ...item,
      kind: 'ACCESSORY',
      code: item.code || layout.code,
      block: normalizeBlockValue(item.block || layout.block || ''),
      floor: 'SOUS-SOL',
      type: normalizeAccessoryType(item.type || item.accessory_type || layout.type || ''),
      surface: Number(item.surface ?? layout.surface ?? 0),
      price_m2_base: Number(item.price_m2_base ?? layout.price_m2_base ?? 0),
      price_ht: Number(item.price_ht ?? layout.price_ht ?? 0),
      price_ttc: Number(item.price_ttc ?? layout.price_ttc ?? 0),
      status: normalizeStatusValue(item.status || layout.status || 'Disponible'),
      rect: layout.rect || null,
      plan_image: item.plan_image || layout.plan_image || ''
    });
    seen.add(codeKey);
  });

  UNITS.forEach((item) => {
    const codeKey = normalizeCodeValue(item.code);
    if (seen.has(codeKey)) return;
    merged.push({
      ...item,
      status: normalizeStatusValue(item.status || 'Disponible')
    });
  });

  UNITS = merged;

  // Filtre agence : ne garder que les unités autorisées
  if (window.__AGENCE_FLAVA_UNITS__ && window.__AGENCE_FLAVA_UNITS__.length > 0) {
    const allowed = new Set(window.__AGENCE_FLAVA_UNITS__.map(function(c){ return c.toUpperCase().trim(); }));
    UNITS = UNITS.filter(function(u) {
      const code = getFlavaCanonicalUnitCode(u.db_code || u.code || '').toUpperCase().trim();
      return allowed.has(code);
    });
  }
}

async function fetchDynamicAccessories() {
  try {
    const res = await fetch(PROJECT_ACCESSORIES_URL + '&_=' + Date.now());
    if (!res.ok) return;
    const payload = await res.json();
    if (!payload || !Array.isArray(payload.accessories)) return;
    mergeDynamicAccessories(payload.accessories);
  } catch (e) {
    console.warn('Impossible de charger les accessoires dynamiques', e);
  }
}

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

// Les prix arrivent déjà calculés pour le mois par le serveur (même calcul que la Liste des prix, blocage compris)
function priceForCurrentMonth(price) {
  return Number(price) || 0;
}

// Prix parking du mois : celui envoyé par le serveur
function flavaParkingHT() {
  const p = UNITS.find(u => u.kind === 'ACCESSORY' && String(u.type || '').toUpperCase() === 'PARKING' && Number(u.price_ht) > 0);
  return p ? Number(p.price_ht) : 0;
}
function flavaParkingTTC() {
  return Math.round(flavaParkingHT() * 1.19);
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
  type: "ALL",
  viewMode: "plan",   // plan | list
  onlyAvailable: true,
  selectedCode: null,
  statusOverrides: {}  // sera chargé depuis le serveur
};

const els = {};

function cacheDom() {
  els.block = document.getElementById("selectBlock");
  els.floor = document.getElementById("selectFloor");
  els.type  = document.getElementById("selectType");

  els.viewPlanBtn = document.getElementById("viewPlanBtn");
  els.viewListBtn = document.getElementById("viewListBtn");

  els.listWrapper = document.getElementById("listWrapper");
  els.onlyAvailable = document.getElementById("onlyAvailable");
  els.unitsTbody = document.getElementById("unitsTbody");

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

  const optAll = document.createElement("option");
  optAll.value = "ALL";
  optAll.textContent = "Tous les étages";
  els.floor.appendChild(optAll);

  FLOORS.forEach((f) => {
    const opt = document.createElement("option");
    opt.value = f.id;
    opt.textContent = f.label;
    els.floor.appendChild(opt);
  });
}

function initTypeSelect() {
  if (!els.type) return;
  const types = Array.from(new Set(UNITS.map(u => u.type).filter(Boolean))).sort((a,b) => a.localeCompare(b, 'fr'));
  els.type.innerHTML = "";

  const optAll = document.createElement("option");
  optAll.value = "ALL";
  optAll.textContent = "Toutes";
  els.type.appendChild(optAll);

  types.forEach((t) => {
    const opt = document.createElement("option");
    opt.value = t;
    opt.textContent = t;
    els.type.appendChild(opt);
  });
}

// retourne le plan correspondant bloc + étage
function getFloorImage(block, floor) {
  if (FLOOR_IMAGES[block] && FLOOR_IMAGES[block][floor]) {
    return FLOOR_IMAGES[block][floor];
  }
  return "assets/plans/flava/RDCB.png"; // fallback
}

function getUnitsWithStatusOverrides() {
  const map = state.statusOverrides;
  return UNITS.map((u) => ({
    ...u,
    status: map[u.code] || u.status
  }));
}

function getUnitsForCurrentPlan() {
  const list = getUnitsWithStatusOverrides().filter((u) => u.rect);
  return list.filter((u) => {
    if (state.floor === 'SOUS-SOL') return false; // pas de coordonnées rectangles pour FLAVA sous-sol
    const blockOk = state.block === "ALL" ? true : u.block === state.block;
    const floorOk = state.floor === "ALL" ? true : u.floor === state.floor;
    const typeOk  = state.type  === "ALL" ? true : u.type === state.type;
    return blockOk && floorOk && typeOk;
  });
}

function loadPlanImage() {
  // si on est en mode liste, on ne charge pas de plan
  if (state.viewMode !== "plan") return;

  // en mode plan, il faut un bloc + étage précis
  if (state.block === "ALL") state.block = "B";
  if (state.floor === "ALL") state.floor = FLOORS[0].id;

  const imgSrc = getFloorImage(state.block, state.floor);
  els.planImage.src = imgSrc;

  els.planImage.onload = () => {
    drawUnits();
  };
}

// ==============================
// LISTE DE VENTE
// ==============================

function rowBadge(status){
  const s = (status || "").toLowerCase();
  if (s === "disponible") return '<span class="row-badge dispo">Disponible</span>';
  if (s === "réservé" || s === "reserve") return '<span class="row-badge reserve">Réservé</span>';
  if (s === "vendu") return '<span class="row-badge vendu">Vendu</span>';
  return `<span class="row-badge">${status || ""}</span>`;
}

function getUnitsForList(){
  let list = getUnitsWithStatusOverrides();

  // filtres bloc / étage / typologie
  list = list.filter((u) => {
    const blockOk = state.block === "ALL" ? true : u.block === state.block;
    const floorOk = state.floor === "ALL" ? true : u.floor === state.floor;
    const typeOk  = state.type  === "ALL" ? true : u.type === state.type;
    return blockOk && floorOk && typeOk;
  });

  // Disponible uniquement
  if (state.onlyAvailable) {
    list = list.filter(u => (u.status || "") === "Disponible");
  }

  // tri : bloc, étage, code
  const floorRank = (f) => {
    if (f === "RDC") return -1;
    if (f === "MEZZ") return 99;
    const n = parseInt(f, 10);
    return Number.isFinite(n) ? n : 50;
  };

  list.sort((a,b) => {
    if (a.block !== b.block) return a.block.localeCompare(b.block);
    const ra = floorRank(a.floor), rb = floorRank(b.floor);
    if (ra !== rb) return ra - rb;
    return a.code.localeCompare(b.code);
  });

  return list;
}

function renderList(){
  if (!els.unitsTbody) return;
  const list = getUnitsForList();
  els.unitsTbody.innerHTML = "";

  list.forEach((u) => {
    const priceM2 = priceForCurrentMonth(u.price_m2_base || 0);
    const totalHT = (u.kind === 'ACCESSORY' && u.price_ht) ? u.price_ht : (u.surface || 0) * priceM2;

    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${u.code}</td>
      <td>${u.block}</td>
      <td>${u.floor}</td>
      <td>${u.type || ""}</td>
      <td>${(u.surface ?? "").toString()} m²</td>
      <td>${fmtMoney(priceM2)} TND</td>
      <td>${fmtMoney(totalHT)} TND</td>
      <td>${rowBadge(u.status)}</td>
    `;
    tr.addEventListener("click", () => {
      selectUnit(u.code);
    });
    els.unitsTbody.appendChild(tr);
  });
}

function setViewMode(mode){
  state.viewMode = mode;
  if (els.viewPlanBtn && els.viewListBtn) {
    els.viewPlanBtn.classList.toggle("is-active", mode === "plan");
    els.viewListBtn.classList.toggle("is-active", mode === "list");
  }

  const planWrap = document.querySelector('.plan-wrapper');
  if (planWrap) planWrap.classList.toggle('hidden', mode !== 'plan');
  if (els.listWrapper) els.listWrapper.classList.toggle('hidden', mode !== 'list');

  if (mode === "plan") {
    loadPlanImage();
  } else {
    renderList();
  }
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

  const img = els.uPreviewImg;

  if (unit.plan_image) {
    img.onload = () => {
      img.style.width = '100%';
      img.style.height = '100%';
      img.style.objectFit = 'contain';
      img.style.transform = 'none';
    };
    img.src = unit.plan_image;
    return;
  }

  const imgSrc = getFloorImage(unit.block, unit.floor);
  if (!imgSrc || !unit.rect) return;

  img.onload = () => {
    img.style.objectFit = 'initial';
    const rect = unit.rect;
    const natW = img.naturalWidth;
    const natH = img.naturalHeight;
    if (!natW || !natH) return;

    const boxW = els.uPreviewBox.clientWidth;
    const boxH = els.uPreviewBox.clientHeight;

    const zoom = Math.min(boxW / rect.width, boxH / rect.height) * 1.1;

    const scaledW = natW * zoom;
    const scaledH = natH * zoom;

    img.style.width  = scaledW + 'px';
    img.style.height = scaledH + 'px';

    const offsetX = rect.x * zoom;
    const offsetY = rect.y * zoom;

    const extraX = (boxW - rect.width * zoom) / 2;
    const extraY = (boxH - rect.height * zoom) / 2;

    img.style.transform = `translate(${-offsetX + extraX}px, ${-offsetY + extraY}px)`;
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

  const price_ht_m2  = priceForCurrentMonth(unit.price_m2_base || 0);
  const price_ttc_m2 = price_ht_m2 * 1.19;

  const total_ht  = (unit.kind === 'ACCESSORY' && unit.price_ht) ? unit.price_ht : price_ht_m2  * (unit.surface || 0);
  const total_ttc = (unit.kind === 'ACCESSORY' && unit.price_ttc) ? unit.price_ttc : price_ttc_m2 * (unit.surface || 0);

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
    if (state.viewMode === "plan") drawUnits();
    if (state.viewMode === "list") renderList();
  };

  updateUnitPreview(unit);
  if (unit.kind !== 'ACCESSORY') {
    loadClientForUnit(unit.code);
  }
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
    if (state.viewMode === "plan") {
      loadPlanImage();
    } else {
      renderList();
    }
  });

  els.floor.addEventListener("change", () => {
    state.floor = els.floor.value;
    state.selectedCode = null;
    els.unitCard.classList.add("hidden");
    els.unitEmpty.style.display = "grid";
    if (state.viewMode === "plan") {
      loadPlanImage();
    } else {
      renderList();
    }
  });

  if (els.type) {
    els.type.addEventListener("change", () => {
      state.type = els.type.value;
      state.selectedCode = null;
      els.unitCard.classList.add("hidden");
      els.unitEmpty.style.display = "grid";
      if (state.viewMode === "plan") {
        loadPlanImage();
      } else {
        renderList();
      }
    });
  }

  if (els.onlyAvailable) {
    els.onlyAvailable.addEventListener("change", () => {
      state.onlyAvailable = !!els.onlyAvailable.checked;
      if (state.viewMode === "list") renderList();
    });
  }

  if (els.viewPlanBtn) {
    els.viewPlanBtn.addEventListener("click", () => setViewMode("plan"));
  }
  if (els.viewListBtn) {
    els.viewListBtn.addEventListener("click", () => setViewMode("list"));
  }

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
      const url = "fiche_client.php?unit_code=" + encodeURIComponent(state.selectedCode);
      window.open(url, "_blank");
    });
  }
}

// ==============================
// MAIN
// ==============================

async function init() {
  cacheDom();
  await fetchDynamicUnits();
  await fetchDynamicAccessories();
  initFloorSelect();
  initTypeSelect();

  state.block = els.block.value;
  state.floor = els.floor.value;
  state.type = els.type ? els.type.value : "ALL";
  state.onlyAvailable = els.onlyAvailable ? !!els.onlyAvailable.checked : true;

  wireEvents();
  setViewMode("plan");

  const remote = await fetchStatusOverrides();
  state.statusOverrides = remote || {};
  if (state.viewMode === "plan") drawUnits();
  if (state.viewMode === "list") renderList();

  
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
if (!window.__PROMEDAPP_SKIP_FLAVA_LEGACY__) {
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
}

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
      pickStart = { x: xNat, y: yNat };
    } else {
      pickStart = null;
    }
  });
}


// lancer l’init principal
if (!window.__PROMEDAPP_SKIP_FLAVA_LEGACY__) {
  document.addEventListener("DOMContentLoaded", init);
}
