<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

//$user = requireAuth();

$idGara = isset($_GET['id_gara']) ? (int)$_GET['id_gara'] : 0;

if ($idGara <= 0) {
    jsonResponse([
        'ok' => false,
        'error' => 'invalid_id_gara',
    ], 400);
}

$sql = 'SELECT * FROM tbl_gare WHERE id_gara = :id_gara LIMIT 1';
$stmt = $pdo->prepare($sql);
$stmt->execute([':id_gara' => $idGara]);
$item = $stmt->fetch();

if (!$item) {
    jsonResponse([
        'ok' => false,
        'error' => 'gara_not_found',
    ], 404);
}

jsonResponse([
    'ok' => true,
    'item' => $item,
]);
