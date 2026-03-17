<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name($config['session_name'] ?? 'app_session');
    session_start();
}

require_once __DIR__ . '/../core/db.php';

try {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $n = isset($_GET['n']) ? (int) $_GET['n'] : 0;

    if ($id <= 0 || !in_array($n, [1, 2], true)) {
        http_response_code(400);
        exit('Parametri non validi.');
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            rif_utente,
            allegato1_path,
            allegato1_nome_file,
            allegato2_path,
            allegato2_nome_file
        FROM tbl_nota_spesa
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    $nota = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$nota) {
        http_response_code(404);
        exit('Nota spesa non trovata.');
    }

    if ($n === 1) {
        $filePath = trim((string) ($nota['allegato1_path'] ?? ''));
        $fileName = trim((string) ($nota['allegato1_nome_file'] ?? 'allegato1'));
    } else {
        $filePath = trim((string) ($nota['allegato2_path'] ?? ''));
        $fileName = trim((string) ($nota['allegato2_nome_file'] ?? 'allegato2'));
    }

    // Path reale del file sul filesystem
    $resolvedPath = '../' . $filePath;

    if ($filePath === '' || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
        http_response_code(404);
        exit('File non trovato.');
    }

    $mimeType = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $resolvedPath);
            if ($detected) {
                $mimeType = $detected;
            }
            finfo_close($finfo);
        }
    }

    // Pulisce eventuali buffer/output già aperti
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string) filesize($resolvedPath));
    header('Content-Disposition: inline; filename="' . basename($fileName) . '"');
    header('Content-Transfer-Encoding: binary');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-transform, no-store, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    $fp = fopen($resolvedPath, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit('Errore apertura file.');
    }

    fpassthru($fp);
    fclose($fp);
    exit;

} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    exit('Errore interno.');
}