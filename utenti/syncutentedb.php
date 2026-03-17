<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

try {
    //Produzione
    $mockUser = getJsonInput();
    
    $utente = syncUtente($pdo, $mockUser);

    $_SESSION['user'] = [
        'id_utente' => $utente['id_utente'],
        'wp_user_id' => (int)$mockUser['id'],
        'username' => (string)$mockUser['username'],
        'email' => (string)$mockUser['email'],
        'display_name' => (string)$mockUser['display_name'],
        'ruolo_app' => (string)$utente['ruolo_app'],
        'stato' => (string)$utente['stato'],
    ];

    jsonResponse([
        'ok' => true,
        'message' => 'Login locale eseguito correttamente.',
        'user' => $_SESSION['user'],
        'is_new' => $utente['is_new'],
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'error' => 'auth_dev_failed',
        'detail' => $e->getMessage(),
    ], 500);
}
