<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

//requireAdmin();
$input = getJsonInput();
$idGara = isset($input['id_gara']) ? (int)$input['id_gara'] : 0;

if ($idGara <= 0) {
    jsonResponse([
        'ok' => false,
        'error' => 'invalid_id_gara',
    ], 400);
}

$stmt = $pdo->prepare('DELETE FROM tbl_gare WHERE id_gara = :id_gara');
$stmt->execute([':id_gara' => $idGara]);

jsonResponse([
    'ok' => true,
    'message' => 'Gara eliminata correttamente.',
    'affected_rows' => $stmt->rowCount(),
]);
