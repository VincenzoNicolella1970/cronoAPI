<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

try {

    // //Administrator
    // //$mockUser = $config['dev_auth_admin'] ?? [];

    // //Socio
    // //$mockUser = $config['dev_auth_user'] ?? [];

    //Utente admin come in produzione
    $mockUser = $config['new_admin_vincenzo'] ?? [];
    //$mockUser = $config['new_admin_lorenzo'] ?? [];

    //Utente socio come in produzione
    //$mockUser = $config['new_socio_mariorossi'] ?? [];

    //Ruolo
    $mockUser["roles"] = $mockUser["roles"][0];

    //Registro/Gestisco l'utente sul DB
    $utente = syncUtente($pdo, $mockUser);


    $_SESSION['user'] = [
        'id_utente' => $utente['id_utente'],
        'wp_user_id' => (int) $mockUser['id'],
        'username' => (string) $mockUser['username'],
        'email' => (string) $mockUser['email'],
        'display_name' => (string) $mockUser['display_name'],
        'ruolo' => (string) $mockUser['roles'],
        'stato' => (string) $utente['stato'],
        "first_name" => (string) $mockUser['first_name'],
        "last_name" => (string) $mockUser['last_name'],
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
