<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

//$user = requireAuth();

$idGara = isset($_GET['id_gara']) ? (int) $_GET['id_gara'] : 0;

if ($idGara <= 0) {
    jsonResponse([
        'ok' => false,
        'error' => 'invalid_id_gara'
    ], 400);
}

try {
    $sql = "
        SELECT
            gu.id_gara_utente,
            gu.rif_gara,
            gu.rif_utente,
            gu.ruolo,
            gu.note,
            gu.created_at,
            u.username,
            u.email,
            u.display_name,
            u.nome,
            u.cognome
        FROM tbl_gare_utenti gu
        INNER JOIN tbl_utenti u
            ON u.wp_user_id = gu.rif_utente
        WHERE gu.rif_gara = :id_gara
        ORDER BY u.display_name ASC, u.username ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_gara' => $idGara
    ]);

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse([
        'ok' => true,
        'items' => $items
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'error' => 'server_error',
        'detail' => $e->getMessage()
    ], 500);
}