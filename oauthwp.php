<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . "/config.php";

require_once __DIR__ . "/utenti/functions.php";


$caFile = __DIR__ . '/certs/cacert.pem';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOrigins = [
  'http://localhost:3001',
  'https://www.cronofr.it',
  'https://cronofr.it'
];

if (in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin");
  header("Access-Control-Allow-Credentials: true");
  header("Access-Control-Allow-Headers: Content-Type, Authorization, Accept");
  header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

header('Content-Type: application/json; charset=utf-8');

$code = $_GET['code'] ?? '';
if ($code === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'missing_code']);
  exit;
}

// ===== CONFIG =====

$client_id = 'tISRcrsYMnxvZuPNdujBZdMFSGRIQpNr';
$client_secret = 'ZXlBnWLJaujbRFxPhBFIaKBiYJSnyUTY';

$redirect_uri = 'https://www.cronofr.it/cronoWAP/oauthwp';

$token_endpoint = 'https://www.cronofr.it/site/wp-json/moserver/token';
$resource_endpoint = 'https://www.cronofr.it/site/wp-json/moserver/resource';

// Endpoint ruoli (opzione B)
$wp_base = 'https://www.cronofr.it/site';
$roles_endpoint = $wp_base . '/wp-json/private/v1/user-roles';

// DEVE essere IDENTICO a PRIVATE_ROLES_SECRET nel wp-config.php
//$roles_secret = 'XP5(=(+2JuVP$C<t;jV2B>n>.SqDWSd=';//PRIVATE_ROLES_SECRET
$roles_secret = PRIVATE_ROLES_SECRET;

// ==================

function fetchMoUserInfo(string $resourceEndpoint, string $accessToken, string $caFile): array
{
  $ch = curl_init($resourceEndpoint);

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $accessToken,
      'Accept: application/json',
    ],
    // DEBUG ONLY
    CURLOPT_CAINFO => $caFile,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
  ]);

  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($resp === false) {
    return [
      'ok' => false,
      'error' => 'curl_error',
      'detail' => $err ?: 'unknown',
      'http' => $http,
    ];
  }

  $json = json_decode($resp, true);

  return [
    'ok' => ($http >= 200 && $http < 300),
    'http' => $http,
    'raw' => $resp,
    'json' => is_array($json) ? $json : null,
  ];
}

/**
 * Chiama l'endpoint WP "private/v1/user-roles" firmato (HMAC).
 * Ritorna ['ok'=>true,'roles'=>[...]] oppure errore con raw/http.
 */
function fetchWpRolesHmac(string $rolesEndpoint, int $userId, string $secret, string $caFile): array
{
  if ($userId <= 0 || $secret === '') {
    return ['ok' => false, 'error' => 'invalid_params'];
  }

  $ts = time();
  $payload = $userId . '|' . $ts;
  $sig = hash_hmac('sha256', $payload, $secret);

  $url = $rolesEndpoint . '?user_id=' . urlencode((string) $userId)
    . '&ts=' . urlencode((string) $ts)
    . '&sig=' . urlencode($sig);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    // DEBUG ONLY
    CURLOPT_CAINFO => $caFile,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
  ]);

  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($resp === false) {
    return ['ok' => false, 'error' => 'curl_error', 'detail' => $err ?: 'unknown'];
  }

  $data = json_decode($resp, true);
  if ($http !== 200 || !is_array($data) || (($data['ok'] ?? false) !== true)) {
    return ['ok' => false, 'error' => 'roles_failed', 'http' => $http, 'raw' => $resp];
  }

  return [
    'ok' => true,
    'roles' => is_array($data['roles'] ?? null) ? $data['roles'] : [],
  ];
}

// 1) code -> token
$postFields = http_build_query([
  'grant_type' => 'authorization_code',
  'code' => $code,
  'redirect_uri' => $redirect_uri,
  'client_id' => $client_id,
  'client_secret' => $client_secret,
]);

$ch = curl_init($token_endpoint);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $postFields,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
  // DEBUG ONLY
  CURLOPT_CAINFO => $caFile,
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($response === false) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'curl_error', 'detail' => $err]);
  exit;
}

$tokenData = json_decode($response, true);

if ($http !== 200 || !is_array($tokenData)) {
  http_response_code(401);
  echo json_encode([
    'ok' => false,
    'error' => 'token_exchange_failed',
    'http' => $http,
    'raw' => $response
  ]);
  exit;
}

$accessToken = $tokenData['access_token'] ?? '';
if ($accessToken === '') {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'missing_access_token', 'raw' => $tokenData]);
  exit;
}

// 2) token -> userinfo (resource)
$userInfoResp = fetchMoUserInfo($resource_endpoint, $accessToken, $caFile);

if (!$userInfoResp['ok']) {
  http_response_code(401);
  echo json_encode([
    'ok' => false,
    'error' => 'userinfo_failed',
    'userinfo' => $userInfoResp,
    'token_scope' => $tokenData['scope'] ?? null,
  ]);
  exit;
}

$user = $userInfoResp['json'] ?? [];
if (!is_array($user))
  $user = [];

// 2b) ruoli via endpoint privato WP (HMAC)
$userId = (int) ($user['id'] ?? 0);
$rolesResp = fetchWpRolesHmac($roles_endpoint, $userId, $roles_secret, $caFile);
if (($rolesResp['ok'] ?? false) === true) {
  $user['roles'] = $rolesResp['roles'][0];
} else {
  // Se vuoi debug temporaneo, scommenta:
  // $user['roles_error'] = $rolesResp;
  $user['roles'] = ""; // fallback
}
//$user['tkn'] = $tokenData['access_token'] ?? null;


//Registra sul DB
syncUtente($pdo, $user);



// 3) Salva in sessione
$_SESSION['access_token'] = $tokenData['access_token'] ?? null;
$_SESSION['id_token'] = $tokenData['id_token'] ?? null;
$_SESSION['token_type'] = $tokenData['token_type'] ?? null;
$_SESSION['expires_in'] = $tokenData['expires_in'] ?? null;
$_SESSION['wp_user'] = $user;

// 4) Risposta
echo json_encode([
  'ok' => true,
  'user' => $_SESSION['wp_user']
]);
