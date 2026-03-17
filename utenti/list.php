<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

//requireAdmin();

$sql = 'SELECT
            id_utente,
            wp_user_id,
            username,
            email,
            display_name,
            nome,
            cognome,
            ruolo_app,
            stato,
            ultimo_accesso,
            created_at,
            updated_at
        FROM tbl_utenti
        ORDER BY id_utente ASC';

$stmt = $pdo->query($sql);
$items = $stmt->fetchAll();

jsonResponse([
    'ok' => true,
    'items' => $items,
]);
