-- ============================================================================
-- Parkings en location : une ligne par place (VITAL 19 places, LAC DES FLAMANTS 15)
-- Base : u860315086_promedapp      (pas de changement de structure : uniquement des lignes)
-- Exécuter les étapes une par une dans phpMyAdmin (onglet SQL).
-- ============================================================================

-- ÉTAPE 1 – Sauvegarde de la table (à faire une seule fois)
CREATE TABLE u860315086_promedapp.bk_unit_accessoires_20261102 AS SELECT * FROM u860315086_promedapp.unit_accessoires;

-- ÉTAPE 2 – Aperçu (lecture seule) : la ligne générique « Place de parking » de chaque projet
--           et les dossiers qui l'utilisent (doit être 0 dossier pour pouvoir la supprimer)
SELECT a.id, p.code AS projet, a.code, a.linked_unit_text, a.price_year_ht_rent, a.price_month_ht_rent, a.status_code,
       (SELECT COUNT(*) FROM u860315086_promedapp.location_dossiers d WHERE d.unit_type = 'accessoire' AND d.unit_id = a.id) AS nb_dossiers
FROM u860315086_promedapp.unit_accessoires a JOIN u860315086_promedapp.projects p ON p.id = a.project_id
WHERE a.operation_mode = 'location' AND a.accessory_type = 'PARKING'
ORDER BY p.code, a.code;

-- ÉTAPE 3 – Créer une ligne par place (copie bloc, niveau et loyers de la ligne générique).
--           VITAL : 27,28,29,37,38,39,41,42,43 = Loué (fichier « Etat du stock »), les autres Disponible.
--           Relancer ne crée pas de doublon.
INSERT INTO u860315086_promedapp.unit_accessoires
  (project_id, accessory_type, code, linked_unit_text, block_name, floor_name, surface,
   price_year_ht_rent, price_month_ht_rent, status_code, operation_mode)
SELECT g.project_id, 'PARKING', CONCAT('P', n.num), NULL, g.block_name, g.floor_name, g.surface,
       g.price_year_ht_rent, g.price_month_ht_rent,
       CASE WHEN p.code = 'VITAL' AND n.num IN (27,28,29,37,38,39,41,42,43) THEN 'L' ELSE 'D' END,
       'location'
FROM u860315086_promedapp.unit_accessoires g
JOIN u860315086_promedapp.projects p ON p.id = g.project_id
JOIN (
  SELECT 'VITAL' AS projet, 23 AS num UNION ALL SELECT 'VITAL',24 UNION ALL SELECT 'VITAL',27 UNION ALL SELECT 'VITAL',28
  UNION ALL SELECT 'VITAL',29 UNION ALL SELECT 'VITAL',30 UNION ALL SELECT 'VITAL',31 UNION ALL SELECT 'VITAL',32
  UNION ALL SELECT 'VITAL',34 UNION ALL SELECT 'VITAL',35 UNION ALL SELECT 'VITAL',36 UNION ALL SELECT 'VITAL',37
  UNION ALL SELECT 'VITAL',38 UNION ALL SELECT 'VITAL',39 UNION ALL SELECT 'VITAL',40 UNION ALL SELECT 'VITAL',41
  UNION ALL SELECT 'VITAL',42 UNION ALL SELECT 'VITAL',43 UNION ALL SELECT 'VITAL',55
  UNION ALL SELECT 'LAC DES FLAMANTS',33 UNION ALL SELECT 'LAC DES FLAMANTS',45 UNION ALL SELECT 'LAC DES FLAMANTS',46
  UNION ALL SELECT 'LAC DES FLAMANTS',47 UNION ALL SELECT 'LAC DES FLAMANTS',48 UNION ALL SELECT 'LAC DES FLAMANTS',49
  UNION ALL SELECT 'LAC DES FLAMANTS',51 UNION ALL SELECT 'LAC DES FLAMANTS',54 UNION ALL SELECT 'LAC DES FLAMANTS',55
  UNION ALL SELECT 'LAC DES FLAMANTS',57 UNION ALL SELECT 'LAC DES FLAMANTS',58 UNION ALL SELECT 'LAC DES FLAMANTS',59
  UNION ALL SELECT 'LAC DES FLAMANTS',60 UNION ALL SELECT 'LAC DES FLAMANTS',71 UNION ALL SELECT 'LAC DES FLAMANTS',72
) n ON n.projet = p.code
WHERE g.operation_mode = 'location' AND g.accessory_type = 'PARKING' AND g.code = 'Place de parking'
  AND NOT EXISTS (SELECT 1 FROM (SELECT project_id, code FROM u860315086_promedapp.unit_accessoires
                                 WHERE operation_mode = 'location' AND accessory_type = 'PARKING') x
                  WHERE x.project_id = g.project_id AND x.code = CONCAT('P', n.num));

-- ÉTAPE 4 – Retirer la ligne générique « Place de parking » (location) de VITAL et du Lac,
--           seulement si aucun dossier ne l'utilise. La vente n'est pas touchée.
DELETE FROM u860315086_promedapp.unit_accessoires
WHERE id IN (SELECT id FROM (
    SELECT a.id FROM u860315086_promedapp.unit_accessoires a
    JOIN u860315086_promedapp.projects p ON p.id = a.project_id
    WHERE a.operation_mode = 'location' AND a.accessory_type = 'PARKING' AND a.code = 'Place de parking'
      AND p.code IN ('VITAL', 'LAC DES FLAMANTS')
      AND NOT EXISTS (SELECT 1 FROM u860315086_promedapp.location_dossiers d WHERE d.unit_type = 'accessoire' AND d.unit_id = a.id)
) x);

-- ÉTAPE 5 – Vérification : VITAL 19 places (10 D + 9 L), LAC DES FLAMANTS 15 places (15 D)
SELECT p.code AS projet, a.status_code, COUNT(*) AS nb, GROUP_CONCAT(a.code ORDER BY LENGTH(a.code), a.code) AS places
FROM u860315086_promedapp.unit_accessoires a JOIN u860315086_promedapp.projects p ON p.id = a.project_id
WHERE a.operation_mode = 'location' AND a.accessory_type = 'PARKING' AND p.code IN ('VITAL', 'LAC DES FLAMANTS')
GROUP BY p.code, a.status_code ORDER BY p.code, a.status_code;

-- RETOUR ARRIÈRE (si besoin) : remettre la table comme avant
-- DELETE FROM u860315086_promedapp.unit_accessoires;
-- INSERT INTO u860315086_promedapp.unit_accessoires SELECT * FROM u860315086_promedapp.bk_unit_accessoires_20261102;
