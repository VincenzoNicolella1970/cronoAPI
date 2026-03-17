<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

//$user = requireAuth();

try {
    $data = nsGetPayload();
    $id = nsRequireInt($data, 'id');

    if (!nsNotaSpesaExists($pdo, $id)) {
        jsonResponse([
            'ok' => false,
            'error' => 'not_found',
        ], 404);
    }

    $stmt = $pdo->prepare('DELETE FROM tbl_nota_spesa WHERE id = :id');
    $stmt->execute([':id' => $id]);

    jsonResponse([
        'ok' => true,
        'message' => 'Nota spesa eliminata correttamente.',
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