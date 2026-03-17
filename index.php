<?php
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . "/vendor/autoload.php";

/* helper base */

function json($data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function body_json(): array {
    $raw = file_get_contents("php://input") ?: "";
    if (trim($raw) === "") return [];
    $d = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE && is_array($d)) ? $d : [];
}

function require_method(string $m) {
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== strtoupper($m)) {
        json(["error" => "Method not allowed"], 405);
    }
}

/* dispatcher semplicissimo */

$path = $_GET["path"] ?? parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$path = trim($path, "/");

$parts = $path !== "" ? explode("/", $path) : [];

if (isset($parts[0]) && strtolower($parts[0]) === "api") {
    array_shift($parts);
}

$endpoint = $parts[0] ?? "";

if ($endpoint) {
    $file = __DIR__ . "/" . $endpoint . ".php";
    if (file_exists($file)) {
        require $file;
    }
}

json([
    "ok" => true,
    "message" => "WebAPI pronta"
]);