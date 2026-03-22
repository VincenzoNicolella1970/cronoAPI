<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $id = isset($input['id']) ? (int) $input['id'] : 0;
    $nome = trim((string) ($input['nome'] ?? ''));
    $attivo = isset($input['attivo']) ? (int) $input['attivo'] : 1;

    $errori = [];

    if ($id <= 0) {
        $errori['id'] = 'ID non valido';
    }

    if ($nome === '') {
        $errori['nome'] = 'Il nome è obbligatorio';
    } elseif (mb_strlen($nome) > 60) {
        $errori['nome'] = 'Il nome non può superare 60 caratteri';
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
        FROM tbl_disciplina
        WHERE id = :id
    ";
    $stmtExists = $pdo->prepare($sqlExists);
    $stmtExists->execute([':id' => $id]);

    if ((int) $stmtExists->fetchColumn() === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Disciplina non trovata'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sqlCheck = "
        SELECT COUNT(*)
        FROM tbl_disciplina
        WHERE UPPER(nome) = UPPER(:nome)
          AND id <> :id
    ";
    $stmtCheck = $pdo->prepare($sqlCheck);
    $stmtCheck->execute([
        ':nome' => $nome,
        ':id' => $id
    ]);

    if ((int) $stmtCheck->fetchColumn() > 0) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Esiste già una disciplina con questo nome',
            'fields' => [
                'nome' => 'Disciplina già presente'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "
        UPDATE tbl_disciplina
        SET
            nome = :nome,
            attivo = :attivo
        WHERE id = :id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':nome' => $nome,
        ':attivo' => $attivo
    ]);

    $sqlItem = "
        SELECT
            id,
            nome,
            attivo
        FROM tbl_disciplina
        WHERE id = :id
    ";
    $stmtItem = $pdo->prepare($sqlItem);
    $stmtItem->execute([':id' => $id]);
    $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Disciplina aggiornata correttamente',
        'item' => $item
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Errore aggiornamento disciplina',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}