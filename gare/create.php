<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

//$user = requireAdmin();
$input = getJsonInput();
$payload = normalizzaGaraPayload($input);
$errori = validaGaraPayload($payload);

if ($errori !== []) {
    jsonResponse([
        'ok' => false,
        'error' => 'validation_error',
        'fields' => $errori,
    ], 422);
}

$sql = 'INSERT INTO tbl_gare (
            nome_gara,
            rif_disciplina,
            rif_manifestazione,
            rif_regione,
            rif_provincia,
            rif_comune,
            data_inizio,
            data_fine,
            stato,
            note,
            created_at
        ) VALUES (
            :nome_gara,
            :rif_disciplina,
            :rif_manifestazione,
            :rif_regione,
            :rif_provincia,
            :rif_comune,
            :data_inizio,
            :data_fine,
            :stato,
            :note,
            NOW()
        )';

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
]);

jsonResponse([
    'ok' => true,
    'message' => 'Gara creata correttamente.',
    'id_gara' => (int)$pdo->lastInsertId(),
    //'created_by' => (int)$user['id_utente'],
], 201);
