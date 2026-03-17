<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';
// jsonResponse([
//     'ok' => true,
//     'test' => 'update raggiunto'
// ]);
// exit;

//requireAdmin();
//$user = requireAdmin();
$input = getJsonInput();
$idGara = isset($input['id_gara']) ? (int)$input['id_gara'] : 0;

if ($idGara <= 0) {
    jsonResponse([
        'ok' => false,
        'error' => 'invalid_id_gara',
    ], 400);
}

$payload = normalizzaGaraPayload($input);
$errori = validaGaraPayload($payload);

if ($errori !== []) {
    jsonResponse([
        'ok' => false,
        'error' => 'validation_error',
        'fields' => $errori,
    ], 422);
}

$sql = 'UPDATE tbl_gare
        SET nome_gara = :nome_gara,
            rif_disciplina = :rif_disciplina,
            rif_manifestazione = :rif_manifestazione,
            rif_regione = :rif_regione,
            rif_provincia = :rif_provincia,
            rif_comune = :rif_comune,
            data_inizio = :data_inizio,
            data_fine = :data_fine,
            stato = :stato,
            note = :note
        WHERE id_gara = :id_gara';

// printf($sql);
// jsonResponse([
//     'ok' => true,
//     'sql Execute' => $sql
// ]);
// exit;

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':nome_gara' => $payload['nome_gara'],
    ':rif_disciplina' => $payload['rif_disciplina'],
    ':rif_manifestazione' => $payload['rif_manifestazione'],
    ':rif_regione' => $payload['rif_regione'] !== '' ? $payload['rif_regione'] : null,
    ':rif_provincia' => $payload['rif_provincia'] !== '' ? $payload['rif_provincia'] : null,
    ':rif_comune' => $payload['rif_comune'] !== '' ? $payload['rif_comune'] : null,
    ':data_inizio' => $payload['data_inizio'],
    ':data_fine' => $payload['data_fine'],
    ':stato' => $payload['stato'] !== '' ? $payload['stato'] : 'ATTIVA',
    ':note' => $payload['note'] !== '' ? $payload['note'] : null,
    ':id_gara' => $idGara,
]);

jsonResponse([
    'ok' => true,
    'message' => 'Gara aggiornata correttamente.',
]);
