<?php
declare(strict_types=1);

function requireAuth(): array
{
    if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
        jsonResponse([
            'ok' => false,
            'error' => 'not_authenticated',
        ], 401);
    }

    return $_SESSION['user'];
}

function requireAdmin(): array
{
    $user = requireAuth();

    if (($user['ruolo_app'] ?? '') !== 'administrator') {
        jsonResponse([
            'ok' => false,
            'error' => 'forbidden',
        ], 403);
    }

    return $user;
}
