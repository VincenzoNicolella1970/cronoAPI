<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

//$user = requireAuth();

try {
    $data = nsGetPayload();

    $id        = nsRequireInt($data, 'id');
    $rifUtente = nsRequireInt($data, 'rif_utente');
    $rifGara   = nsRequireInt($data, 'rif_gara');

    if (!nsNotaSpesaExists($pdo, $id)) {
        jsonResponse([
            'ok' => false,
            'error' => 'not_found',
        ], 404);
    }

    if (!nsUtenteExists($pdo, $rifUtente)) {
        jsonResponse([
            'ok' => false,
            'error' => 'utente_not_found',
        ], 400);
    }

    if (!nsGaraExists($pdo, $rifGara)) {
        jsonResponse([
            'ok' => false,
            'error' => 'gara_not_found',
        ], 400);
    }

    $sql = "
        UPDATE tbl_nota_spesa
        SET
            rif_utente = :rif_utente,
            rif_gara = :rif_gara,
            data_servizio = :data_servizio,
            ora_inizio_servizio = :ora_inizio_servizio,
            ora_fine_servizio = :ora_fine_servizio,
            ora_inizio_gara = :ora_inizio_gara,
            ora_fine_gara = :ora_fine_gara,
            km_percorsi = :km_percorsi,
            spese_autostrada_eur = :spese_autostrada_eur,
            targa_auto = :targa_auto,
            persone_trasportate = :persone_trasportate,
            trasportato_da = :trasportato_da,
            spesa1_descrizione = :spesa1_descrizione,
            spesa1_eur = :spesa1_eur,
            spesa2_descrizione = :spesa2_descrizione,
            spesa2_eur = :spesa2_eur,
            somme_ricevute_da = :somme_ricevute_da,
            somme_ricevute_eur = :somme_ricevute_eur,
            note_servizio = :note_servizio,
            allegato1_path = :allegato1_path,
            allegato1_nome_file = :allegato1_nome_file,
            allegato2_path = :allegato2_path,
            allegato2_nome_file = :allegato2_nome_file,
            stato = :stato,
            updated_at = NOW()
        WHERE id = :id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id'                    => $id,
        ':rif_utente'            => $rifUtente,
        ':rif_gara'              => $rifGara,
        ':data_servizio'         => nsNullIfEmpty($data['data_servizio'] ?? null),
        ':ora_inizio_servizio'   => nsNullIfEmpty($data['ora_inizio_servizio'] ?? null),
        ':ora_fine_servizio'     => nsNullIfEmpty($data['ora_fine_servizio'] ?? null),
        ':ora_inizio_gara'       => nsNullIfEmpty($data['ora_inizio_gara'] ?? null),
        ':ora_fine_gara'         => nsNullIfEmpty($data['ora_fine_gara'] ?? null),
        ':km_percorsi'           => nsNullIfEmpty($data['km_percorsi'] ?? null),
        ':spese_autostrada_eur'  => nsNullIfEmpty($data['spese_autostrada_eur'] ?? null),
        ':targa_auto'            => nsNullIfEmpty($data['targa_auto'] ?? null),
        ':persone_trasportate'   => nsNullIfEmpty($data['persone_trasportate'] ?? null),
        ':trasportato_da'        => nsNullIfEmpty($data['trasportato_da'] ?? null),
        ':spesa1_descrizione'    => nsNullIfEmpty($data['spesa1_descrizione'] ?? null),
        ':spesa1_eur'            => nsNullIfEmpty($data['spesa1_eur'] ?? null),
        ':spesa2_descrizione'    => nsNullIfEmpty($data['spesa2_descrizione'] ?? null),
        ':spesa2_eur'            => nsNullIfEmpty($data['spesa2_eur'] ?? null),
        ':somme_ricevute_da'     => nsNullIfEmpty($data['somme_ricevute_da'] ?? null),
        ':somme_ricevute_eur'    => nsNullIfEmpty($data['somme_ricevute_eur'] ?? null),
        ':note_servizio'         => nsNullIfEmpty($data['note_servizio'] ?? null),
        ':allegato1_path'        => nsNullIfEmpty($data['allegato1_path'] ?? null),
        ':allegato1_nome_file'   => nsNullIfEmpty($data['allegato1_nome_file'] ?? null),
        ':allegato2_path'        => nsNullIfEmpty($data['allegato2_path'] ?? null),
        ':allegato2_nome_file'   => nsNullIfEmpty($data['allegato2_nome_file'] ?? null),
        ':stato'                 => nsValidateStato($data['stato'] ?? 'BOZZA'),
    ]);

    jsonResponse([
        'ok' => true,
        'message' => 'Nota spesa aggiornata correttamente.',
    ]);

} catch (InvalidArgumentException $e) {
    jsonResponse([
        'ok' => false,
        'error' => 'validation_error',
        'message' => $e->getMessage(),
    ], 400);

} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage(),
    ], 500);
}