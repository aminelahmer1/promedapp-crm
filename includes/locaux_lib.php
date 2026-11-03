<?php
/* ══ Locaux en plusieurs parties ═══════════════════════════════
   Un local (showroom, magasin…) peut être enregistré en plusieurs lignes
   dans `units` (une par niveau ou par partie). Il se vend ou se loue en entier :
   un seul état et un seul dossier pour toutes ses parties.
   Les groupes sont définis ici (pas en base) : projet → [nom, références des parties].
   La 1re partie qui a une surface totale porte le prix du local.               */

const LOCAUX_GROUPES = [
    'VITAL' => [
        ['nom' => 'Show-Room 1-2-3', 'parts' => ['Show-Room 1', 'Show-Room 2', 'Show-Room 3']],
        ['nom' => 'Show-Room 2Bis-3Bis', 'parts' => ['Show-Room 2Bis', 'Show-Room 3Bis']],
        ['nom' => 'Show-Room 4-5-6', 'parts' => ['Show-Room 4', 'Show-Room 5', 'Ligne 1', 'Show-Room 6', 'Ligne 2']],
    ],
    'ZEN' => [
        ['nom' => 'Magasin', 'parts' => ['Magasin', 'Ligne 1']],
    ],
    'LAC DES FLAMANTS' => [
        ['nom' => 'M1 à M5', 'parts' => ['M1', 'M2', 'M3', 'M4', 'M5']],
    ],
    'EVOLO' => [
        ['nom' => 'MAG-06', 'parts' => ['MAG-06', 'Ligne 1']],
    ],
];

/* Groupe d'une référence dans un projet (null si le bien est seul) */
function local_group_of(string $projectCode, string $refCode): ?array {
    foreach (LOCAUX_GROUPES[strtoupper(trim($projectCode))] ?? [] as $i => $g) {
        if (in_array(trim($refCode), $g['parts'], true)) return $g + ['idx' => $i];
    }
    return null;
}

/* Toutes les lignes `units` du même local (même projet, même mode vente/location).
   Renvoie [id => ref_code] ; un bien seul renvoie juste lui-même. */
function local_part_rows(PDO $pdo, int $unitId): array {
    $st = $pdo->prepare("SELECT u.id, u.ref_code, u.operation_mode, u.project_id, p.code AS project_code
                         FROM units u JOIN projects p ON p.id = u.project_id WHERE u.id = ?");
    $st->execute([$unitId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return [];
    $g = local_group_of((string)$u['project_code'], (string)$u['ref_code']);
    if (!$g) return [(int)$u['id'] => $u['ref_code']];

    $ph = implode(',', array_fill(0, count($g['parts']), '?'));
    $q  = $pdo->prepare("SELECT id, ref_code FROM units
                         WHERE project_id = ? AND operation_mode = ? AND ref_code IN ($ph)");
    $q->execute(array_merge([$u['project_id'], $u['operation_mode']], $g['parts']));
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r['ref_code'];
    return $out;
}

/* Met le même statut sur toutes les parties du local de $unitId */
function local_set_status(PDO $pdo, int $unitId, string $status): array {
    $ids = array_keys(local_part_rows($pdo, $unitId));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE units SET status_code = ? WHERE id IN ($ph)")->execute(array_merge([$status], $ids));
    return $ids;
}

/* ── Parkings loués avec un local ─────────────────────────────────────────
   Chaque place est une ligne de unit_accessoires (code P23, P24…). Quand une place est
   réservée avec un local, elle a son propre dossier (même locataire) et son historique
   porte la note ci-dessous : c'est ce qui la rattache au dossier du local (aucune colonne ajoutée). */
function parking_link_note(int $localDossierId): string {
    return 'Parking rattaché au dossier n°' . $localDossierId;
}
/* ancien libellé (02/11/2026 matin), toujours reconnu */
function parking_link_note_old(int $localDossierId): string {
    return 'Parking réservé avec le local – dossier n°' . $localDossierId;
}

/** Dossiers parking rattachés au dossier d'un local : [ ['dossier_id'=>..,'unit_id'=>..,'statut'=>..,'code'=>..,'price_month_ht_rent'=>..], ... ] */
function local_parking_dossiers(PDO $pdo, int $localDossierId): array {
    $q = $pdo->prepare("SELECT d.id AS dossier_id, d.unit_id, d.statut, a.code, a.price_month_ht_rent
                        FROM location_status_history h
                        JOIN location_dossiers d ON d.id = h.dossier_id AND d.unit_type = 'accessoire'
                        JOIN unit_accessoires a ON a.id = d.unit_id
                        WHERE h.note IN (?, ?)
                        GROUP BY d.id, d.unit_id, d.statut, a.code, a.price_month_ht_rent
                        ORDER BY LENGTH(a.code), a.code");
    $q->execute([parking_link_note($localDossierId), parking_link_note_old($localDossierId)]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Places de parking (une ligne par place) proposées en location pour un projet */
function project_location_parkings(PDO $pdo, string $projectCode): array {
    $q = $pdo->prepare("SELECT a.id, a.code, a.status_code, a.price_month_ht_rent
                        FROM unit_accessoires a JOIN projects p ON p.id = a.project_id
                        WHERE p.code = ? AND a.operation_mode = 'location' AND a.accessory_type = 'PARKING'
                          AND a.code LIKE 'P%'
                        ORDER BY LENGTH(a.code), a.code");
    $q->execute([$projectCode]);
    return array_values(array_filter($q->fetchAll(PDO::FETCH_ASSOC), fn($r) => preg_match('/^P\d+$/', (string)$r['code'])));
}

/** Fait suivre aux parkings du local la confirmation (loue/L) ou l'annulation (annule/D) */
function local_parkings_follow(PDO $pdo, int $localDossierId, string $statut, int $userId): void {
    $map = ['loue' => ['L', 'date_confirmation', 'Location confirmée avec le dossier principal'],
            'annule' => ['D', 'date_annulation', 'Réservation annulée avec le dossier principal']];
    if (!isset($map[$statut])) return;
    [$st, $dateCol, $note] = $map[$statut];
    foreach (local_parking_dossiers($pdo, $localDossierId) as $pk) {
        if ($pk['statut'] !== 'reserve') continue;
        $pdo->prepare("UPDATE unit_accessoires SET status_code = ? WHERE id = ?")->execute([$st, $pk['unit_id']]);
        $pdo->prepare("UPDATE location_dossiers SET statut = ?, $dateCol = NOW() WHERE id = ?")->execute([$statut, $pk['dossier_id']]);
        $pdo->prepare("INSERT INTO location_status_history (dossier_id,old_status,new_status,changed_by,note) VALUES (?,?,?,?,?)")
            ->execute([$pk['dossier_id'], 'R', $st, $userId, $note]);
    }
}
