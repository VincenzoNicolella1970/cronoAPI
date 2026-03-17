<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

//$user = requireAuth();

$data = getJsonInput();

$sql = "SELECT * from vw_elenco_nota_spesa ORDER BY id DESC";

if (!empty($data['id'])) {
    $sql = 'SELECT * FROM vw_elenco_nota_spesa WHERE rif_utente=' . $data['id'] . ' ORDER BY id DESC';
}


$stmt = $pdo->query($sql);
$items = $stmt->fetchAll();

jsonResponse([
    'ok' => true,
    'items' => $items,
]);