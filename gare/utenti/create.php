<?php
declare(strict_types=1);

require_once __DIR__ . '/../../core/bootstrap.php';

//requireAdmin();

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    jsonResponse([
        'ok' => false,
        'error' => 'invalid_json'
    ], 400);
}

$rifGara = (int) ($input['rif_gara'] ?? 0);
$rifUtente = (int) ($input['rif_utente'] ?? 0);
$ruolo = trim((string) ($input['ruolo'] ?? ''));
$note = trim((string) ($input['note'] ?? ''));

$errors = [];

if ($rifGara <= 0) {
    $errors[] = 'rif_gara non valido';
}

if ($rifUtente <= 0) {
    $errors[] = 'rif_utente non valido';
}

if (!empty($errors)) {
    jsonResponse([
        'ok' => false,
        'error' => 'validation_error',
        'messages' => $errors
    ], 422);
}

try {
    $stmtGara = $pdo->prepare("
        SELECT id_gara
        FROM tbl_gare
        WHERE id_gara = :id_gara
        LIMIT 1
    ");
    $stmtGara->execute([
        ':id_gara' => $rifGara
    ]);
    $gara = $stmtGara->fetch(PDO::FETCH_ASSOC);

    if (!$gara) {
        jsonResponse([
            'ok' => false,
            'error' => 'gara_not_found'
        ], 404);
    }

    $stmtUtente = $pdo->prepare("
        SELECT id_utente
        FROM tbl_utenti
        WHERE wp_user_id = :id_utente
        LIMIT 1
    ");
    $stmtUtente->execute([
        ':id_utente' => $rifUtente
    ]);
    $utente = $stmtUtente->fetch(PDO::FETCH_ASSOC);

    if (!$utente) {
        jsonResponse([
            'ok' => false,
            'error' => 'utente_not_found'
        ], 404);
    }

    $stmtCheck = $pdo->prepare("
        SELECT id_gara_utente
        FROM tbl_gare_utenti
        WHERE rif_gara = :rif_gara
          AND rif_utente = :rif_utente
        LIMIT 1
    ");
    $stmtCheck->execute([
        ':rif_gara' => $rifGara,
        ':rif_utente' => $rifUtente
    ]);
    $exists = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        jsonResponse([
            'ok' => false,
            'error' => 'assignment_already_exists'
        ], 409);
    }

    $stmtInsert = $pdo->prepare("
        INSERT INTO tbl_gare_utenti
        (
            rif_gara,
            rif_utente,
            ruolo,
            note,
            created_at
        )
        VALUES
        (
            :rif_gara,
            :rif_utente,
            :ruolo,
            :note,
            NOW()
        )
    ");

    $stmtInsert->execute([
        ':rif_gara' => $rifGara,
        ':rif_utente' => $rifUtente,
        ':ruolo' => $ruolo !== '' ? $ruolo : null,
        ':note' => $note !== '' ? $note : null
    ]);

    $idGaraUtente = (int) $pdo->lastInsertId();

    $stmtDetail = $pdo->prepare("
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
        WHERE gu.id_gara_utente = :id_gara_utente
        LIMIT 1
    ");
    $stmtDetail->execute([
        ':id_gara_utente' => $idGaraUtente
    ]);

    $item = $stmtDetail->fetch(PDO::FETCH_ASSOC);

    jsonResponse([
        'ok' => true,
        'message' => 'Utente assegnato correttamente alla gara',
        'data' => $item
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'error' => 'server_error',
        'detail' => $e->getMessage()
    ], 500);
}