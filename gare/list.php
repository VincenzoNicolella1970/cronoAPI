<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

$data = getJsonInput();
$idUtente = isset($data['id']) ? (int) $data['id'] : 0;

if ($idUtente > 0) {
    $sql = "
        SELECT 
            g.*,
            1 AS assegnata_a_me
        FROM vw_gare_complete_utenti g
        WHERE g.id_utente = :id_utente
        ORDER BY g.data_inizio DESC, g.id_gara DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id_utente', $idUtente, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $sql = "
        SELECT 
            g.*,
            CASE 
                WHEN EXISTS (
                    SELECT 1
                    FROM tbl_gare_utenti gu
                    WHERE gu.rif_gara = g.id_gara
                      AND gu.rif_utente = :id_utente_check
                )
                THEN 1
                ELSE 0
            END AS assegnata_a_me
        FROM vw_gare_complete_ass_num_utenti g
        ORDER BY g.data_inizio DESC, g.id_gara DESC
    ";

    //$idUtenteSessione = isset($_SESSION['ID']) ? (int) $_SESSION['ID'] : 0;
    $idUtenteSessione = isset($_SESSION['user']) ? (int) $_SESSION['user']["wp_user_id"] : 0;

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id_utente_check', $idUtenteSessione, PDO::PARAM_INT);
    $stmt->execute();
}

$items = $stmt->fetchAll();

jsonResponse([
    'ok' => true,
    'items' => $items,
]);