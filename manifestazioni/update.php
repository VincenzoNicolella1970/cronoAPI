<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $id = isset($input['id']) ? (int) $input['id'] : 0;
    $nome = trim((string) ($input['nome'] ?? ''));
    $rifDisciplina = isset($input['rif_disciplina']) ? (int) $input['rif_disciplina'] : 0;
    $attivo = isset($input['attivo']) ? (int) $input['attivo'] : 1;

    $errori = [];

    if ($id <= 0) {
        $errori['id'] = 'ID non valido';
    }

    if ($nome === '') {
        $errori['nome'] = 'Il nome è obbligatorio';
    } elseif (mb_strlen($nome) > 160) {
        $errori['nome'] = 'Il nome non può superare 160 caratteri';
    }

    if ($rifDisciplina <= 0) {
        $errori['rif_disciplina'] = 'La disciplina è obbligatoria';
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

    $sqlDisciplina = "
        SELECT COUNT(*)
        FROM tbl_disciplina
        WHERE id = :id
    ";
    $stmtDisciplina = $pdo->prepare($sqlDisciplina);
    $stmtDisciplina->execute([':id' => $rifDisciplina]);

    if ((int) $stmtDisciplina->fetchColumn() === 0) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Disciplina non valida',
            'fields' => [
                'rif_disciplina' => 'La disciplina selezionata non esiste'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sqlCheck = "
        SELECT COUNT(*)
        FROM tbl_manifestazione
        WHERE UPPER(nome) = UPPER(:nome)
          AND rif_disciplina = :rif_disciplina
          AND id <> :id
    ";
    $stmtCheck = $pdo->prepare($sqlCheck);
    $stmtCheck->execute([
        ':nome' => $nome,
        ':rif_disciplina' => $rifDisciplina,
        ':id' => $id
    ]);

    if ((int) $stmtCheck->fetchColumn() > 0) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Esiste già una manifestazione con questo nome per la disciplina selezionata',
            'fields' => [
                'nome' => 'Manifestazione già presente per questa disciplina'
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sql = "
        UPDATE tbl_manifestazione
        SET
            nome = :nome,
            rif_disciplina = :rif_disciplina,
            attivo = :attivo
        WHERE id = :id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':nome' => $nome,
        ':rif_disciplina' => $rifDisciplina,
        ':attivo' => $attivo
    ]);

    $sqlItem = "
        SELECT
            m.id,
            m.nome,
            m.rif_disciplina,
            m.attivo,
            d.nome AS desc_disciplina
        FROM tbl_manifestazione m
        LEFT JOIN tbl_disciplina d
            ON d.id = m.rif_disciplina
        WHERE m.id = :id
    ";
    $stmtItem = $pdo->prepare($sqlItem);
    $stmtItem->execute([':id' => $id]);
    $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Manifestazione aggiornata correttamente',
        'item' => $item
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Errore aggiornamento manifestazione',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}