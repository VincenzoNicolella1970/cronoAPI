<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $id = isset($input['id']) ? (int) $input['id'] : 0;
    $attivo = isset($input['attivo']) ? (int) $input['attivo'] : -1;

    $errori = [];

    if ($id <= 0) {
        $errori['id'] = 'ID non valido';
    }

    if (!in_array($attivo, [0, 1], true)) {
        $errori['attivo'] = 'Valore attivo non valido';
    }

    if (!empty($errori)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Dati non validi',
            'fields' => $errori
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sqlExists = "
        SELECT COUNT(*)
        FROM tbl_manifestazione
        WHERE id = :id
    ";
    $stmtExists = $pdo->prepare($sqlExists);
    $stmtExists->execute([':id' => $id]);

    if ((int) $stmtExists->fetchColumn() === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Manifestazione non trovata'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "
        UPDATE tbl_manifestazione
        SET attivo = :attivo
        WHERE id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':attivo' => $attivo
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Stato manifestazione aggiornato correttamente'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Errore aggiornamento stato manifestazione',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}