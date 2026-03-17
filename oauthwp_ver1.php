<?php
declare(strict_types=1);
session_start();

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOrigins = [
  'http://localhost:3001',
];

if (in_array($origin, $allowedOrigins, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header("Vary: Origin"); // importante per cache/proxy
  header("Access-Control-Allow-Credentials: true");
  header("Access-Control-Allow-Headers: Content-Type, Authorization, Accept");
  header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
}

// Rispondi al preflight
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

// Deve essere IDENTICA a quella usata nell'authorize URL
$redirect_uri = 'http://localhost:3001/oauthwp';

$token_endpoint = 'https://www.parrocchiasgibattista.it/site/wp-json/moserver/token';
$resource_endpoint = 'https://www.parrocchiasgibattista.it/site/wp-json/moserver/resource';
// ==================

/**
 * Chiama l'endpoint "resource" di miniOrange per ottenere i dati utente.
 * Ritorna array con info utente.
 * Lancia Exception se fallisce.
 */
function fetchMoUserInfo(string $resourceEndpoint, string $accessToken): array
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
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
  ]);

  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
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
  // DEBUG ONLY: in produzione metti true/2
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_SSL_VERIFYHOST => false,
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
$userInfoResp = fetchMoUserInfo($resource_endpoint, $accessToken);

// Se fallisce, NON buttare giù tutto: ritorna debug
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

// 3) Salva in sessione (semplice per iniziare)
$_SESSION['access_token'] = $tokenData['access_token'] ?? null;
$_SESSION['id_token'] = $tokenData['id_token'] ?? null;
$_SESSION['token_type'] = $tokenData['token_type'] ?? null;
$_SESSION['expires_in'] = $tokenData['expires_in'] ?? null;

$_SESSION['wp_user'] = $userInfoResp['json'];

// 4) Risposta al frontend (consiglio: NON rimandare indietro i token)
echo json_encode([
  'ok' => true,
  'user' =>  $userInfoResp['json'],
]);
