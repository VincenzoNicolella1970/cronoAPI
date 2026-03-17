<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

//requireAdmin();

$idGaraUtente = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($idGaraUtente <= 0) {
    jsonResponse([
        'ok' => false,
        'error' => 'invalid_id_gara_utente'
    ], 400);
}

try {
    $stmtCheck = $pdo->prepare("
        SELECT id_gara_utente
        FROM tbl_gare_utenti
        WHERE id_gara_utente = :id_gara_utente
        LIMIT 1
    ");
    $stmtCheck->execute([
        ':id_gara_utente' => $idGaraUtente
    ]);

    $exists = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$exists) {
        jsonResponse([
            'ok' => false,
            'error' => 'assignment_not_found'
        ], 404);
    }

    $stmtDelete = $pdo->prepare("
        DELETE FROM tbl_gare_utenti
        WHERE id_gara_utente = :id_gara_utente
    ");
    $stmtDelete->execute([
        ':id_gara_utente' => $idGaraUtente
    ]);

    jsonResponse([
        'ok' => true,
        'message' => 'Assegnazione rimossa correttamente'
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'error' => 'server_error',
        'detail' => $e->getMessage()
    ], 500);
}