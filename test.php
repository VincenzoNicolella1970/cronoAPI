<?php
$secret = 'XP5(=(+2JuVP$C<t;jV2B>n>.SqDWSd='; // stesso identico di wp-config.php
$userId = 1;

$ts = time();
$payload = $userId . '|' . $ts;
$sig = hash_hmac('sha256', $payload, $secret);

$url = "https://www.parrocchiasgibattista.it/site/wp-json/private/v1/user-roles"
     . "?user_id={$userId}&ts={$ts}&sig={$sig}";

echo $url . PHP_EOL;
