<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $rifGara = $_GET['rif_gara'] ?? $_POST['rif_gara'] ?? null;

    if ($rifGara === null || trim((string) $rifGara) === '') {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'error' => 'missing_rif_gara',
            'message' => 'Parametro rif_gara mancante.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "
        SELECT COUNT(*) AS totale
        FROM vw_elenco_nota_spesa
        WHERE rif_gara = :rif_gara
    ";

    $stmt = $GLOBALS['pdo']->prepare($sql);
    $stmt->execute([
        ':rif_gara' => $rifGara,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $totale = (int) ($row['totale'] ?? 0);

    echo json_encode([
        'ok' => true,
        'rif_gara' => $rifGara,
        'hasNoteSpese' => $totale > 0,
        'totaleNoteSpese' => $totale,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
