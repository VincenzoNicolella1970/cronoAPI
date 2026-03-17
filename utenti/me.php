<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

$user = requireAuth();

jsonResponse([
    'ok' => true,
    'user' => $user,
]);
