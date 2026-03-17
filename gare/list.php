<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

//$user = requireAuth();

// $sql = 'SELECT
//             g.id_gara,
//             g.nome_gara,
//             g.rif_disciplina,
//             d.nome AS disciplina,
//             g.rif_manifestazione,
//             m.nome AS manifestazione,
//             g.rif_regione,
//             g.rif_provincia,
//             g.rif_comune,
//             g.data_inizio,
//             g.data_fine,
//             g.stato,
//             g.note,
//             g.created_at
//         FROM tbl_gare g
//         LEFT JOIN tbl_disciplina d ON d.id = g.rif_disciplina
//         LEFT JOIN tbl_manifestazione m ON m.id = g.rif_manifestazione
//         ORDER BY g.data_inizio DESC, g.id_gara DESC';

$data = getJsonInput();

$sql = 'SELECT * FROM vw_gare_complete_ass_num_utenti ORDER BY data_inizio DESC, id_gara DESC';

if (!empty($data['id'])) {
    $sql = 'SELECT * FROM vw_gare_complete_utenti WHERE id_utente=' . $data['id'] . ' ORDER BY data_inizio DESC, id_gara DESC';
}

$stmt = $pdo->query($sql);
$items = $stmt->fetchAll();

jsonResponse([
    'ok' => true,
    'items' => $items,
]);
