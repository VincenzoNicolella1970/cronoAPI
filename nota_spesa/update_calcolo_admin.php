<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

//$user = requireAuth();

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metodo non consentito.'
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$id = isset($input['id']) ? (int) $input['id'] : 0;

$totImportoOreExtra = isset($input['tot_importo_ore_extra_eur'])
    ? (float) $input['tot_importo_ore_extra_eur']
    : 0.0;

$importoKmPercorsi = isset($input['importo_km_percorsi_eur'])
    ? (float) $input['importo_km_percorsi_eur']
    : 0.0;

$importoForfettario = isset($input['importo_forfettario_eur'])
    ? (float) $input['importo_forfettario_eur']
    : 0.0;

$totaleRicalcolatoAdmin = isset($input['totale_ricalcolato_admin_eur'])
    ? (float) $input['totale_ricalcolato_admin_eur']
    : 0.0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'ID nota spesa non valido.'
    ]);
    exit;
}

foreach (
    [
        'tot_importo_ore_extra_eur' => $totImportoOreExtra,
        'importo_km_percorsi_eur' => $importoKmPercorsi,
        'importo_forfettario_eur' => $importoForfettario,
        'totale_ricalcolato_admin_eur' => $totaleRicalcolatoAdmin,
    ] as $field => $value
) {
    if ($value < 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Il valore {$field} non può essere negativo."
        ]);
        exit;
    }
}

try {

    $sql = "UPDATE tbl_nota_spesa
            SET
                tot_importo_ore_extra_eur = :tot_importo_ore_extra_eur,
                importo_km_percorsi_eur = :importo_km_percorsi_eur,
                importo_forfettario_eur = :importo_forfettario_eur,
                totale_ricalcolato_admin_eur = :totale_ricalcolato_admin_eur
            WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tot_importo_ore_extra_eur' => number_format($totImportoOreExtra, 2, '.', ''),
        ':importo_km_percorsi_eur' => number_format($importoKmPercorsi, 2, '.', ''),
        ':importo_forfettario_eur' => number_format($importoForfettario, 2, '.', ''),
        ':totale_ricalcolato_admin_eur' => number_format($totaleRicalcolatoAdmin, 2, '.', ''),
        ':id' => $id,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Ricalcolo amministrativo salvato correttamente.'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Errore salvataggio ricalcolo amministrativo.',
        'error' => $e->getMessage()
    ]);
}