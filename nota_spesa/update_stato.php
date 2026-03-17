<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

/*
 // Se hai già l'autenticazione attiva, puoi usare qualcosa del genere:
 $user = requireAuth();

 // E qui un eventuale controllo ruolo admin:
 if (($user['role'] ?? '') !== 'administrator') {
     jsonResponse([
         'ok' => false,
         'error' => 'forbidden',
         'message' => 'Operazione non autorizzata.'
     ], 403);
 }
*/

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new InvalidArgumentException('Payload JSON non valido.');
    }

    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $nuovoStato = strtoupper(trim((string) ($data['stato'] ?? '')));

    if ($id <= 0) {
        throw new InvalidArgumentException('ID nota spesa non valido.');
    }

    $statiConsentiti = ['APPROVATA', 'RESPINTA', 'LIQUIDATA'];

    if (!in_array($nuovoStato, $statiConsentiti, true)) {
        throw new InvalidArgumentException('Stato richiesto non consentito.');
    }

    $stmt = $pdo->prepare("
        SELECT id, stato
        FROM tbl_nota_spesa
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    $nota = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$nota) {
        jsonResponse([
            'ok' => false,
            'error' => 'not_found',
            'message' => 'Nota spesa non trovata.'
        ], 404);
    }

    $statoAttuale = strtoupper(trim((string) ($nota['stato'] ?? '')));

    $transizioniConsentite = [
        'BOZZA' => ['APPROVATA', 'RESPINTA'],
        'INVIATA' => ['APPROVATA', 'RESPINTA'],
        'APPROVATA' => ['LIQUIDATA'],
        'RESPINTA' => [],
        'LIQUIDATA' => [],
    ];

    if (!array_key_exists($statoAttuale, $transizioniConsentite)) {
        throw new RuntimeException('Stato attuale della nota spesa non gestito.');
    }

    if ($statoAttuale === $nuovoStato) {
        jsonResponse([
            'ok' => true,
            'message' => 'Nessuna modifica necessaria.',
            'item' => [
                'id' => $id,
                'stato_precedente' => $statoAttuale,
                'stato_nuovo' => $nuovoStato
            ]
        ]);
    }

    if (!in_array($nuovoStato, $transizioniConsentite[$statoAttuale], true)) {
        jsonResponse([
            'ok' => false,
            'error' => 'invalid_transition',
            'message' => "Transizione non consentita da {$statoAttuale} a {$nuovoStato}."
        ], 400);
    }

    $stmtUpdate = $pdo->prepare("
        UPDATE tbl_nota_spesa
        SET
            stato = :stato,
            updated_at = NOW()
        WHERE id = :id
    ");

    $stmtUpdate->execute([
        ':id' => $id,
        ':stato' => $nuovoStato,
    ]);

    jsonResponse([
        'ok' => true,
        'message' => 'Stato aggiornato correttamente.',
        'item' => [
            'id' => $id,
            'stato_precedente' => $statoAttuale,
            'stato_nuovo' => $nuovoStato
        ]
    ]);

} catch (InvalidArgumentException $e) {
    jsonResponse([
        'ok' => false,
        'error' => 'validation_error',
        'message' => $e->getMessage()
    ], 400);

} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage()
    ], 500);
}