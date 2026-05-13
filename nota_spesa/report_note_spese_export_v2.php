<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

/*
 * report_note_spese_export_v2.php
 *
 * Report Note Spese - PDF / CSV / Excel
 * Connessione DB coerente con gli endpoint esistenti del progetto:
 *   require_once __DIR__ . '/../core/bootstrap.php';
 *   require_once __DIR__ . '/functions.php';
 *
 * Parametri supportati, via GET oppure JSON body:
 * - formato=pdf|csv|excel|xls     default: pdf
 * - dal=YYYY-MM-DD
 * - al=YYYY-MM-DD
 * - stato=BOZZA|INVIATA|APPROVATA|RESPINTA|LIQUIDATA
 * - rif_gara=ID
 * - rif_utente=ID
 * - id=ID                         alias di rif_utente, come nel list.php
 * - include_annullate=1           default: escluse gare annullate
 * - download=1                    PDF scaricato; default: PDF aperto inline
 *
 * Per PDF serve Dompdf:
 *   composer require dompdf/dompdf
 */

const COEFF_ORA_EXTRA = 6.00;
const COEFF_KM = 0.35;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Connessione PDO non trovata. Verifica core/bootstrap.php: deve rendere disponibile la variabile $pdo.');
}

function requestPayload(): array
{
    $data = $_GET;

    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = array_merge($data, $json);
        }
    }

    return $data;
}

function req(array $data, string $key, mixed $default = null): mixed
{
    if (!array_key_exists($key, $data)) {
        return $default;
    }

    if (is_string($data[$key])) {
        $value = trim($data[$key]);
        return $value === '' ? $default : $value;
    }

    return $data[$key] ?? $default;
}

function validDate(?string $value): bool
{
    if ($value === null || $value === '') {
        return true;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $value;
}

function euro(float|int|null $value): string
{
    return '€ ' . number_format((float) ($value ?? 0), 2, ',', '.');
}

function dec(float|int|null $value, int $decimals = 2): string
{
    return number_format((float) ($value ?? 0), $decimals, ',', '.');
}

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function moneyValue(mixed $value): float
{
    return round((float) ($value ?? 0), 2);
}

function reportFilename(string $extension): string
{
    return 'report_note_spese_' . date('Ymd_His') . '.' . $extension;
}

function dateIt(?string $value): string
{
    if (!$value) {
        return '';
    }

    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
}

$data = requestPayload();

$formato = strtolower((string) req($data, 'formato', 'pdf'));
if ($formato === 'xls') {
    $formato = 'excel';
}
if (!in_array($formato, ['pdf', 'csv', 'excel'], true)) {
    $formato = 'pdf';
}

$dal = req($data, 'dal');
$al = req($data, 'al');
$stato = req($data, 'stato');
$rifGara = req($data, 'rif_gara');
$rifUtente = req($data, 'rif_utente', req($data, 'id'));
$includeAnnullate = (string) req($data, 'include_annullate', '0') === '1';
$download = (string) req($data, 'download', '0') === '1';

$dal = $dal !== null ? (string) $dal : null;
$al = $al !== null ? (string) $al : null;
$stato = $stato !== null ? strtoupper((string) $stato) : null;

if (!validDate($dal) || !validDate($al)) {
    http_response_code(400);
    exit('Formato data non valido. Usa YYYY-MM-DD.');
}

$where = [];
$params = [];

if ($dal !== null) {
    $where[] = 'ns.data_servizio >= :dal';
    $params[':dal'] = $dal;
}

if ($al !== null) {
    $where[] = 'ns.data_servizio <= :al';
    $params[':al'] = $al;
}

if ($stato !== null) {
    $where[] = 'ns.stato = :stato';
    $params[':stato'] = $stato;
}

if ($rifGara !== null) {
    $where[] = 'ns.rif_gara = :rif_gara';
    $params[':rif_gara'] = (int) $rifGara;
}

if ($rifUtente !== null) {
    $where[] = 'ns.rif_utente = :rif_utente';
    $params[':rif_utente'] = (int) $rifUtente;
}

if (!$includeAnnullate) {
    $where[] = "(g.stato IS NULL OR UPPER(g.stato) <> 'ANNULLATA')";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        ns.id,
        ns.rif_utente,
        COALESCE(u.nome, CONCAT('Utente ', ns.rif_utente)) AS cronometrista,
        ns.rif_gara,
        g.nome_gara,
        d.nome AS disciplina,
        m.nome AS manifestazione,
        ns.data_servizio,
        ns.ora_inizio_servizio,
        ns.ora_fine_servizio,
        ns.ora_inizio_gara,
        ns.ora_fine_gara,
        ns.km_percorsi,
        ns.spese_autostrada_eur,
        ns.spesa1_descrizione,
        ns.spesa1_eur,
        ns.somme_ricevute_da,
        ns.somme_ricevute_eur,
        ns.stato,
        ns.tot_importo_ore_extra_eur,
        ns.importo_km_percorsi_eur,
        ns.importo_forfettario_eur,
        ns.totale_ricalcolato_admin_eur,
        ns.created_at,
        ns.updated_at
    FROM tbl_nota_spesa ns
    LEFT JOIN tbl_utenti u ON u.wp_user_id = ns.rif_utente
    LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
    LEFT JOIN tbl_disciplina d ON d.id = g.rif_disciplina
    LEFT JOIN tbl_manifestazione m ON m.id = g.rif_manifestazione
    {$whereSql}
    ORDER BY ns.data_servizio ASC, cronometrista ASC, ns.id ASC
";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totals = [
    'forfettario' => 0.0,
    'ore_extra' => 0.0,
    'diaria' => 0.0,
    'km' => 0.0,
    'rimborso_km' => 0.0,
    'autostrada' => 0.0,
    'spesa1' => 0.0,
    'somme_ricevute' => 0.0,
    'totale' => 0.0,
];

foreach ($rows as &$row) {
    $forfettario = moneyValue($row['importo_forfettario_eur'] ?? 0);
    $diaria = moneyValue($row['tot_importo_ore_extra_eur'] ?? 0);
    $km = moneyValue($row['km_percorsi'] ?? 0);
    $rimborsoKm = moneyValue($row['importo_km_percorsi_eur'] ?? 0);
    $autostrada = moneyValue($row['spese_autostrada_eur'] ?? 0);
    $spesa1 = moneyValue($row['spesa1_eur'] ?? 0);
    $sommeRicevute = moneyValue($row['somme_ricevute_eur'] ?? 0);
    $totaleAdmin = moneyValue($row['totale_ricalcolato_admin_eur'] ?? 0);

    $totaleCalcolato = $forfettario + $diaria + $rimborsoKm + $autostrada + $spesa1 - $sommeRicevute;
    $totale = $totaleAdmin > 0 ? $totaleAdmin : $totaleCalcolato;
    $oreExtra = COEFF_ORA_EXTRA > 0 ? round($diaria / COEFF_ORA_EXTRA, 2) : 0;

    $row['_forfettario'] = $forfettario;
    $row['_ore_extra'] = $oreExtra;
    $row['_diaria'] = $diaria;
    $row['_km'] = $km;
    $row['_rimborso_km'] = $rimborsoKm;
    $row['_autostrada'] = $autostrada;
    $row['_spesa1'] = $spesa1;
    $row['_somme_ricevute'] = $sommeRicevute;
    $row['_totale'] = $totale;

    $totals['forfettario'] += $forfettario;
    $totals['ore_extra'] += $oreExtra;
    $totals['diaria'] += $diaria;
    $totals['km'] += $km;
    $totals['rimborso_km'] += $rimborsoKm;
    $totals['autostrada'] += $autostrada;
    $totals['spesa1'] += $spesa1;
    $totals['somme_ricevute'] += $sommeRicevute;
    $totals['totale'] += $totale;
}
unset($row);

$periodoLabel = ($dal ?: 'inizio') . ' / ' . ($al ?: 'fine');
$statoLabel = $stato ?: 'Tutte';

$headers = [
    'Cronometrista',
    'Manifestazione',
    'Gara',
    'Data',
    'Forfettario',
    'Ore extra',
    '€/H',
    'Diaria',
    'Km',
    '€/Km',
    'Rimb. Km',
    'Autostrada',
    'Spesa descrizione',
    'Spesa importo',
    'Somme ricevute da',
    'Somme ricevute',
    'Totale',
    'Stato',
];

if ($formato === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . reportFilename('csv') . '"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ';');

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['cronometrista'],
            $r['manifestazione'],
            $r['nome_gara'],
            dateIt($r['data_servizio'] ?? null),
            dec($r['_forfettario']),
            dec($r['_ore_extra']),
            dec(COEFF_ORA_EXTRA),
            dec($r['_diaria']),
            dec($r['_km']),
            dec(COEFF_KM),
            dec($r['_rimborso_km']),
            dec($r['_autostrada']),
            $r['spesa1_descrizione'],
            dec($r['_spesa1']),
            $r['somme_ricevute_da'],
            dec($r['_somme_ricevute']),
            dec($r['_totale']),
            $r['stato'],
        ], ';');
    }

    fputcsv($out, [
        'TOTALI', '', '', '',
        dec($totals['forfettario']),
        dec($totals['ore_extra']),
        '',
        dec($totals['diaria']),
        dec($totals['km']),
        '',
        dec($totals['rimborso_km']),
        dec($totals['autostrada']),
        '',
        dec($totals['spesa1']),
        '',
        dec($totals['somme_ricevute']),
        dec($totals['totale']),
        '',
    ], ';');

    fclose($out);
    exit;
}

if ($formato === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . reportFilename('xls') . '"');
    echo "\xEF\xBB\xBF";
    ?>
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; }
        table { border-collapse: collapse; font-size: 11px; }
        th { background: #123a5a; color: #fff; font-weight: bold; }
        th, td { border: 1px solid #999; padding: 6px; }
        .num { text-align: right; mso-number-format:"\#\,\#\#0\.00"; }
        .date { text-align: center; }
        .total { font-weight: bold; background: #dbe8f5; }
    </style>
</head>
<body>
<h2>Report Note Spese</h2>
<p>Periodo: <?= h($periodoLabel) ?> - Stato: <?= h($statoLabel) ?> - Numero note: <?= count($rows) ?></p>
<table>
    <thead>
    <tr><?php foreach ($headers as $h): ?><th><?= h($h) ?></th><?php endforeach; ?></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= h($r['cronometrista']) ?></td>
            <td><?= h($r['manifestazione']) ?></td>
            <td><?= h($r['nome_gara']) ?></td>
            <td class="date"><?= h(dateIt($r['data_servizio'] ?? null)) ?></td>
            <td class="num"><?= dec($r['_forfettario']) ?></td>
            <td class="num"><?= dec($r['_ore_extra']) ?></td>
            <td class="num"><?= dec(COEFF_ORA_EXTRA) ?></td>
            <td class="num"><?= dec($r['_diaria']) ?></td>
            <td class="num"><?= dec($r['_km']) ?></td>
            <td class="num"><?= dec(COEFF_KM) ?></td>
            <td class="num"><?= dec($r['_rimborso_km']) ?></td>
            <td class="num"><?= dec($r['_autostrada']) ?></td>
            <td><?= h($r['spesa1_descrizione']) ?></td>
            <td class="num"><?= dec($r['_spesa1']) ?></td>
            <td><?= h($r['somme_ricevute_da']) ?></td>
            <td class="num"><?= dec($r['_somme_ricevute']) ?></td>
            <td class="num"><strong><?= dec($r['_totale']) ?></strong></td>
            <td><?= h($r['stato']) ?></td>
        </tr>
    <?php endforeach; ?>
    <tr class="total">
        <td>TOTALI</td><td></td><td></td><td></td>
        <td class="num"><?= dec($totals['forfettario']) ?></td>
        <td class="num"><?= dec($totals['ore_extra']) ?></td>
        <td></td>
        <td class="num"><?= dec($totals['diaria']) ?></td>
        <td class="num"><?= dec($totals['km']) ?></td>
        <td></td>
        <td class="num"><?= dec($totals['rimborso_km']) ?></td>
        <td class="num"><?= dec($totals['autostrada']) ?></td>
        <td></td>
        <td class="num"><?= dec($totals['spesa1']) ?></td>
        <td></td>
        <td class="num"><?= dec($totals['somme_ricevute']) ?></td>
        <td class="num"><?= dec($totals['totale']) ?></td>
        <td></td>
    </tr>
    </tbody>
</table>
</body>
</html>
    <?php
    exit;
}

$html = '<!doctype html><html><head><meta charset="UTF-8"><style>
    @page { margin: 24px 20px; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8.5px; color: #1b2733; }
    .top { border-bottom: 2px solid #173b5c; padding-bottom: 12px; margin-bottom: 16px; }
    .brand { width: 33%; float: left; }
    .title { width: 34%; float: left; text-align: center; }
    .meta { width: 30%; float: right; font-size: 8.5px; }
    .clear { clear: both; }
    h1 { font-size: 22px; margin: 6px 0 4px; color: #0d263d; letter-spacing: .4px; }
    h2 { font-size: 14px; margin: 0 0 4px; color: #0d263d; }
    .subtitle { font-size: 10px; color: #5c6875; }
    .meta table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 3px 0; border: 0; }
    .main-table { width: 100%; border-collapse: collapse; }
    .main-table th { background: #123a5a; color: white; padding: 5px 3px; border: 1px solid #7e96aa; font-size: 7.6px; }
    .main-table td { padding: 4px 3px; border: 1px solid #d1d7dc; vertical-align: top; }
    .main-table tr:nth-child(even) td { background: #f7f9fb; }
    .num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .small { font-size: 7.4px; color: #5b6773; }
    .badge { padding: 2px 4px; border-radius: 3px; background: #eef3f8; font-size: 7.5px; }
    .summary { margin-top: 15px; width: 100%; border-collapse: collapse; }
    .summary th { background: #123a5a; color: white; padding: 6px; text-align: left; }
    .summary td { border: 1px solid #d1d7dc; padding: 6px; }
    .summary .total td { background: #dbe8f5; font-size: 11px; font-weight: bold; }
    .footer { position: fixed; bottom: -8px; left: 0; right: 0; border-top: 1px solid #173b5c; padding-top: 6px; text-align: center; font-size: 8px; color: #5b6773; }
</style></head><body>';

$html .= '<div class="top">
    <div class="brand">
        <h2>CRONOMETRISTI</h2>
        <div class="subtitle">Report amministrativo note spese</div>
    </div>
    <div class="title">
        <h1>REPORT NOTE SPESE</h1>
        <div class="subtitle">Riepilogo economico cronometristi</div>
    </div>
    <div class="meta">
        <table>
            <tr><td>Data generazione:</td><td><strong>' . h(date('d/m/Y')) . '</strong></td></tr>
            <tr><td>Periodo:</td><td><strong>' . h($periodoLabel) . '</strong></td></tr>
            <tr><td>Stato note:</td><td><strong>' . h($statoLabel) . '</strong></td></tr>
            <tr><td>Numero note:</td><td><strong>' . count($rows) . '</strong></td></tr>
        </table>
    </div>
    <div class="clear"></div>
</div>';

$html .= '<table class="main-table"><thead><tr>
    <th>Cronometrista</th>
    <th>Manifestazione / Gara</th>
    <th>Data</th>
    <th>Forf.</th>
    <th>Ore extra</th>
    <th>€/H</th>
    <th>Diaria</th>
    <th>Km</th>
    <th>€/Km</th>
    <th>Rimb. Km</th>
    <th>Autostrada</th>
    <th>Spesa descr.</th>
    <th>Spesa €</th>
    <th>Ricevute</th>
    <th>Totale</th>
    <th>Stato</th>
</tr></thead><tbody>';

if (!$rows) {
    $html .= '<tr><td colspan="16" class="center">Nessuna nota spesa trovata per i filtri selezionati.</td></tr>';
} else {
    foreach ($rows as $r) {
        $manifestazione = trim((string) ($r['manifestazione'] ?? '')) !== '' ? $r['manifestazione'] : $r['nome_gara'];

        $html .= '<tr>'
            . '<td>' . h($r['cronometrista']) . '</td>'
            . '<td>' . h($manifestazione) . '<br><span class="small">' . h($r['nome_gara']) . '</span></td>'
            . '<td class="center">' . h(dateIt($r['data_servizio'] ?? null)) . '</td>'
            . '<td class="num">' . euro($r['_forfettario']) . '</td>'
            . '<td class="num">' . dec($r['_ore_extra']) . '</td>'
            . '<td class="num">' . euro(COEFF_ORA_EXTRA) . '</td>'
            . '<td class="num">' . euro($r['_diaria']) . '</td>'
            . '<td class="num">' . dec($r['_km']) . '</td>'
            . '<td class="num">' . euro(COEFF_KM) . '</td>'
            . '<td class="num">' . euro($r['_rimborso_km']) . '</td>'
            . '<td class="num">' . euro($r['_autostrada']) . '</td>'
            . '<td>' . h($r['spesa1_descrizione']) . '</td>'
            . '<td class="num">' . euro($r['_spesa1']) . '</td>'
            . '<td class="num">' . euro($r['_somme_ricevute']) . '</td>'
            . '<td class="num"><strong>' . euro($r['_totale']) . '</strong></td>'
            . '<td class="center"><span class="badge">' . h($r['stato']) . '</span></td>'
            . '</tr>';
    }
}

$html .= '</tbody></table>';

$html .= '<table class="summary">
    <tr><th colspan="4">Riepilogo generale</th></tr>
    <tr>
        <td>Totale forfettario</td><td class="num">' . euro($totals['forfettario']) . '</td>
        <td>Totale diaria</td><td class="num">' . euro($totals['diaria']) . '</td>
    </tr>
    <tr>
        <td>Totale ore extra</td><td class="num">' . dec($totals['ore_extra']) . '</td>
        <td>Totale km</td><td class="num">' . dec($totals['km']) . '</td>
    </tr>
    <tr>
        <td>Totale rimborso km</td><td class="num">' . euro($totals['rimborso_km']) . '</td>
        <td>Totale autostrada</td><td class="num">' . euro($totals['autostrada']) . '</td>
    </tr>
    <tr>
        <td>Totale spese</td><td class="num">' . euro($totals['spesa1']) . '</td>
        <td>Somme ricevute</td><td class="num">' . euro($totals['somme_ricevute']) . '</td>
    </tr>
    <tr>
        <td>Numero note</td><td class="num">' . count($rows) . '</td>
        <td>Media per nota</td><td class="num">' . euro(count($rows) > 0 ? $totals['totale'] / count($rows) : 0) . '</td>
    </tr>
    <tr class="total"><td colspan="3">TOTALE COMPLESSIVO</td><td class="num">' . euro($totals['totale']) . '</td></tr>
</table>';

$html .= '<div class="footer">Report generato automaticamente dal sistema</div>';
$html .= '</body></html>';

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];

$autoloadLoaded = false;
foreach ($autoloadPaths as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded || !class_exists('Dompdf\\Dompdf')) {
    http_response_code(500);
    exit('Dompdf non trovato. Installa con: composer require dompdf/dompdf');
}

$options = new Dompdf\Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream(reportFilename('pdf'), ['Attachment' => $download]);
exit;
