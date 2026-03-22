<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

//$user = requireAuth();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    jsonResponse([
        'ok' => false,
        'error' => 'invalid_id',
    ], 400);
}

$sql = "
    SELECT
        ns.*,
        CONCAT(u.nome , ' ' , u.cognome) AS utente,
        g.nome_gara,
        d.nome AS disciplina,
        m.nome AS manifestazione,

        c.nome AS comune,
        p.nome AS provincia,
        r.nome AS regione        

    FROM tbl_nota_spesa ns
    LEFT JOIN tbl_utenti u ON u.wp_user_id = ns.rif_utente
    LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
    LEFT JOIN tbl_disciplina d ON d.id = g.rif_disciplina
    LEFT JOIN tbl_manifestazione m ON m.id = g.rif_manifestazione

    LEFT JOIN comuni c ON c.codice_comune = g.rif_comune
    LEFT JOIN province p ON p.codice_provincia = g.rif_provincia
    LEFT JOIN regioni r ON r.codice_regione = g.rif_regione

    WHERE ns.id = :id
    LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id]);
$item = $stmt->fetch();

if (!$item) {
    jsonResponse([
        'ok' => false,
        'error' => 'not_found',
    ], 404);
}

jsonResponse([
    'ok' => true,
    'item' => $item,
]);