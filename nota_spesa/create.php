<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

//$user = requireAuth();

function nsRequestData(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function nsUploadBaseDir(): string
{
    return dirname(__DIR__) . '/uploads/nota_spesa';
}

function nsUploadPublicPath(string $relativePath): string
{
    return '/uploads/nota_spesa/' . str_replace('\\', '/', ltrim($relativePath, '/'));
}

function nsDeleteStoredFile(?string $dbPath): void
{
    if (!$dbPath) {
        return;
    }

    $relative = preg_replace('#^/uploads/nota_spesa/#', '', $dbPath);
    $absolute = nsUploadBaseDir() . '/' . ltrim((string) $relative, '/');

    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

function nsStoreUploadedFile(string $fieldName): ?array
{
    if (
        !isset($_FILES[$fieldName]) ||
        !is_array($_FILES[$fieldName]) ||
        (int) ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
    ) {
        return null;
    }

    $file = $_FILES[$fieldName];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Errore upload file per ' . $fieldName . '.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new InvalidArgumentException('File non valido per ' . $fieldName . '.');
    }

    $originalName = (string) ($file['name'] ?? 'file');
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

    $allowedMimeTypes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpName);

    if (!isset($allowedMimeTypes[$mimeType])) {
        throw new InvalidArgumentException('Sono ammessi solo file PDF o immagini.');
    }

    if ($extension === '') {
        $extension = $allowedMimeTypes[$mimeType];
    }

    $safeBaseName = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) pathinfo($originalName, PATHINFO_FILENAME));
    $safeBaseName = trim((string) $safeBaseName, '_');
    if ($safeBaseName === '') {
        $safeBaseName = 'allegato';
    }

    $relativeDir = date('Y') . '/' . date('m');
    $targetDir = nsUploadBaseDir() . '/' . $relativeDir;

    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Impossibile creare la cartella degli allegati.');
    }

    $finalName = $safeBaseName . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $absolutePath = $targetDir . '/' . $finalName;

    if (!move_uploaded_file($tmpName, $absolutePath)) {
        throw new RuntimeException('Impossibile salvare il file caricato.');
    }

    return [
        'nome_file' => $originalName,
        'path' => nsUploadPublicPath($relativeDir . '/' . $finalName),
    ];
}

try {
    $data = nsRequestData();

    $rifUtente = nsRequireInt($data, 'rif_utente');
    $rifGara = nsRequireInt($data, 'rif_gara');

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

    $upload1 = nsStoreUploadedFile('allegato1_file');
    $upload2 = nsStoreUploadedFile('allegato2_file');

    $allegato1Path = $upload1['path'] ?? nsNullIfEmpty($data['allegato1_path'] ?? null);
    $allegato1Nome = $upload1['nome_file'] ?? nsNullIfEmpty($data['allegato1_nome_file'] ?? null);
    $allegato2Path = $upload2['path'] ?? nsNullIfEmpty($data['allegato2_path'] ?? null);
    $allegato2Nome = $upload2['nome_file'] ?? nsNullIfEmpty($data['allegato2_nome_file'] ?? null);

    if ((int) ($data['remove_allegato1'] ?? 0) === 1) {
        $allegato1Path = null;
        $allegato1Nome = null;
    }

    if ((int) ($data['remove_allegato2'] ?? 0) === 1) {
        $allegato2Path = null;
        $allegato2Nome = null;
    }

    $sql = "
        INSERT INTO tbl_nota_spesa (
            rif_utente,
            rif_gara,
            data_servizio,
            ora_inizio_servizio,
            ora_fine_servizio,
            ora_inizio_gara,
            ora_fine_gara,
            km_percorsi,
            spese_autostrada_eur,
            targa_auto,
            persone_trasportate,
            trasportato_da,
            spesa1_descrizione,
            spesa1_eur,
            spesa2_descrizione,
            spesa2_eur,
            somme_ricevute_da,
            somme_ricevute_eur,
            note_servizio,
            allegato1_path,
            allegato1_nome_file,
            allegato2_path,
            allegato2_nome_file,
            stato,
            created_at,
            updated_at
        ) VALUES (
            :rif_utente,
            :rif_gara,
            :data_servizio,
            :ora_inizio_servizio,
            :ora_fine_servizio,
            :ora_inizio_gara,
            :ora_fine_gara,
            :km_percorsi,
            :spese_autostrada_eur,
            :targa_auto,
            :persone_trasportate,
            :trasportato_da,
            :spesa1_descrizione,
            :spesa1_eur,
            :spesa2_descrizione,
            :spesa2_eur,
            :somme_ricevute_da,
            :somme_ricevute_eur,
            :note_servizio,
            :allegato1_path,
            :allegato1_nome_file,
            :allegato2_path,
            :allegato2_nome_file,
            :stato,
            NOW(),
            NOW()
        )
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':rif_utente' => $rifUtente,
        ':rif_gara' => $rifGara,
        ':data_servizio' => nsNullIfEmpty($data['data_servizio'] ?? null),
        ':ora_inizio_servizio' => nsNullIfEmpty($data['ora_inizio_servizio'] ?? null),
        ':ora_fine_servizio' => nsNullIfEmpty($data['ora_fine_servizio'] ?? null),
        ':ora_inizio_gara' => nsNullIfEmpty($data['ora_inizio_gara'] ?? null),
        ':ora_fine_gara' => nsNullIfEmpty($data['ora_fine_gara'] ?? null),
        ':km_percorsi' => nsNullIfEmpty($data['km_percorsi'] ?? null),
        ':spese_autostrada_eur' => nsNullIfEmpty($data['spese_autostrada_eur'] ?? null),
        ':targa_auto' => nsNullIfEmpty($data['targa_auto'] ?? null),
        ':persone_trasportate' => nsNullIfEmpty($data['persone_trasportate'] ?? null),
        ':trasportato_da' => nsNullIfEmpty($data['trasportato_da'] ?? null),
        ':spesa1_descrizione' => nsNullIfEmpty($data['spesa1_descrizione'] ?? null),
        ':spesa1_eur' => nsNullIfEmpty($data['spesa1_eur'] ?? null),
        ':spesa2_descrizione' => nsNullIfEmpty($data['spesa2_descrizione'] ?? null),
        ':spesa2_eur' => nsNullIfEmpty($data['spesa2_eur'] ?? null),
        ':somme_ricevute_da' => nsNullIfEmpty($data['somme_ricevute_da'] ?? null),
        ':somme_ricevute_eur' => nsNullIfEmpty($data['somme_ricevute_eur'] ?? null),
        ':note_servizio' => nsNullIfEmpty($data['note_servizio'] ?? null),
        ':allegato1_path' => $allegato1Path,
        ':allegato1_nome_file' => $allegato1Nome,
        ':allegato2_path' => $allegato2Path,
        ':allegato2_nome_file' => $allegato2Nome,
        ':stato' => nsValidateStato($data['stato'] ?? 'BOZZA'),
    ]);

    jsonResponse([
        'ok' => true,
        'id' => (int) $pdo->lastInsertId(),
    ], 201);

} catch (InvalidArgumentException $e) {
    jsonResponse([
        'ok' => false,
        'error' => 'validation_error',
        'message' => $e->getMessage(),
    ], 400);

} catch (Throwable $e) {
    if (isset($upload1['path'])) {
        nsDeleteStoredFile($upload1['path']);
    }
    if (isset($upload2['path'])) {
        nsDeleteStoredFile($upload2['path']);
    }

    jsonResponse([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage(),
    ], 500);
}
