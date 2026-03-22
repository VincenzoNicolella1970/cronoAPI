<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

try {
    $sql = "
        SELECT
            m.id,
            m.nome,
            m.rif_disciplina,
            m.attivo,
            d.nome AS desc_disciplina
        FROM tbl_manifestazione m
        LEFT JOIN tbl_disciplina d
            ON d.id = m.rif_disciplina
        ORDER BY d.nome ASC, m.nome ASC
    ";

    $stmt = $pdo->query($sql);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'items' => $items
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Errore caricamento manifestazioni',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}