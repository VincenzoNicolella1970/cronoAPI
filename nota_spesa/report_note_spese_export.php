<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

/*
 * Report Note Spese - PDF / CSV / Excel
 *
 * Parametri GET:
 * - formato=pdf|csv|excel   default: pdf
 * - dal=YYYY-MM-DD
 * - al=YYYY-MM-DD
 * - stato=BOZZA|INVIATA|APPROVATA|RESPINTA|LIQUIDATA
 * - rif_gara=ID
 * - rif_utente=ID
 * - include_annullate=1     default: escluse gare annullate
 * - download=1              per scaricare il PDF invece di aprirlo inline
 *
 * Nota:
 * - per il PDF serve Dompdf: composer require dompdf/dompdf
 * - non vengono usate spesa2, vitto, alloggio, varie.
 * - spesa1_descrizione e spesa1_eur sono colonne separate.
 */

const COEFF_ORA_EXTRA = 6.00;
const COEFF_KM = 0.35;

function getPdoConnection(): PDO
{
    foreach (['pdo', 'conn', 'db'] as $name) {
        if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
            return $GLOBALS[$name];
        }
    }

    http_response_code(500);
    exit('Connessione PDO non trovata. Verifica il file core/bootstrap.php e rendi disponibile $pdo oppure $conn.');
}

function getParam(string $name, ?string $default = null): ?string
{
    $value = $_GET[$name] ?? $default;
    if ($value === null) {
        return null;
    }
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function isValidDate(?string $value): bool
{
    if ($value === null) {
        return true;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value;
}

function money(float|int|null $value): string
{
    return '€ ' . number_format((float) ($value ?? 0), 2, ',', '.');
}

function num(float|int|null $value, int $decimals = 2): string
{
    return number_format((float) ($value ?? 0), $decimals, ',', '.');
}

function e(?string $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parseMoney(mixed $value): float
{
    return round((float) ($value ?? 0), 2);
}

function reportFileName(string $ext): string
{
    $suffix = date('Ymd_His');
    return "report_note_spese_{$suffix}.{$ext}";
}

$pdo = getPdoConnection();

$formato = strtolower(getParam('formato', 'pdf') ?? 'pdf');
if (!in_array($formato, ['pdf', 'csv', 'excel', 'xls'], true)) {
    $formato = 'pdf';
}
if ($formato === 'xls') {
    $formato = 'excel';
}

$dal = getParam('dal');
$al = getParam('al');
$stato = getParam('stato');
$rifGara = getParam('rif_gara');
$rifUtente = getParam('rif_utente');
$includeAnnullate = getParam('include_annullate', '0') === '1';
$download = getParam('download', '0') === '1';

if (!isValidDate($dal) || !isValidDate($al)) {
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
    $params[':stato'] = strtoupper($stato);
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

$whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

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
        ns.km_percorsi,
        ns.spese_autostrada_eur,
        ns.spesa1_descrizione,
        ns.spesa1_eur,
        ns.somme_ricevute_eur,
        ns.stato,
        ns.tot_importo_ore_extra_eur,
        ns.importo_km_percorsi_eur,
        ns.importo_forfettario_eur,
        ns.totale_ricalcolato_admin_eur
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
    'ore_extra_importo' => 0.0,
    'km' => 0.0,
    'rimborso_km' => 0.0,
    'autostrada' => 0.0,
    'spesa1' => 0.0,
    'somme_ricevute' => 0.0,
    'totale' => 0.0,
];

foreach ($rows as &$row) {
    $forfettario = parseMoney($row['importo_forfettario_eur'] ?? 0);
    $oreExtraImporto = parseMoney($row['tot_importo_ore_extra_eur'] ?? 0);
    $km = parseMoney($row['km_percorsi'] ?? 0);
    $rimborsoKm = parseMoney($row['importo_km_percorsi_eur'] ?? 0);
    $autostrada = parseMoney($row['spese_autostrada_eur'] ?? 0);
    $spesa1 = parseMoney($row['spesa1_eur'] ?? 0);
    $sommeRicevute = parseMoney($row['somme_ricevute_eur'] ?? 0);

    $totaleAdmin = parseMoney($row['totale_ricalcolato_admin_eur'] ?? 0);
    $totaleFallback = $forfettario + $oreExtraImporto + $rimborsoKm + $autostrada + $spesa1 - $sommeRicevute;
    $totale = $totaleAdmin > 0 ? $totaleAdmin : $totaleFallback;

    $row['_ore_extra'] = COEFF_ORA_EXTRA > 0 ? round($oreExtraImporto / COEFF_ORA_EXTRA, 2) : 0;
    $row['_forfettario'] = $forfettario;
    $row['_ore_extra_importo'] = $oreExtraImporto;
    $row['_km'] = $km;
    $row['_rimborso_km'] = $rimborsoKm;
    $row['_autostrada'] = $autostrada;
    $row['_spesa1'] = $spesa1;
    $row['_somme_ricevute'] = $sommeRicevute;
    $row['_totale'] = $totale;

    $totals['forfettario'] += $forfettario;
    $totals['ore_extra_importo'] += $oreExtraImporto;
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
    'Somme ricevute',
    'Totale',
    'Stato',
];

if ($formato === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . reportFileName('csv') . '"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['cronometrista'],
            $r['manifestazione'],
            $r['nome_gara'],
            $r['data_servizio'],
            num($r['_forfettario']),
            num($r['_ore_extra']),
            num(COEFF_ORA_EXTRA),
            num($r['_ore_extra_importo']),
            num($r['_km']),
            num(COEFF_KM),
            num($r['_rimborso_km']),
            num($r['_autostrada']),
            $r['spesa1_descrizione'],
            num($r['_spesa1']),
            num($r['_somme_ricevute']),
            num($r['_totale']),
            $r['stato'],
        ], ';');
    }
    fputcsv($out, [
        'TOTALI', '', '', '',
        num($totals['forfettario']),
        '', '',
        num($totals['ore_extra_importo']),
        num($totals['km']),
        '',
        num($totals['rimborso_km']),
        num($totals['autostrada']),
        '',
        num($totals['spesa1']),
        num($totals['somme_ricevute']),
        num($totals['totale']),
        '',
    ], ';');
    fclose($out);
    exit;
}

if ($formato === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . reportFileName('xls') . '"');
    echo "\xEF\xBB\xBF";
    ?>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 11px; }
            th { background: #123a5a; color: #fff; font-weight: bold; }
            th, td { border: 1px solid #999; padding: 6px; }
            .num { text-align: right; }
            .total { font-weight: bold; background: #dbe8f5; }
        </style>
    </head>
    <body>
    <h2>Report Note Spese</h2>
    <p>Periodo: <?= e($periodoLabel) ?> - Stato: <?= e($statoLabel) ?></p>
    <table>
        <thead>
            <tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['cronometrista']) ?></td>
                    <td><?= e($r['manifestazione']) ?></td>
                    <td><?= e($r['nome_gara']) ?></td>
                    <td><?= e($r['data_servizio']) ?></td>
                    <td class="num"><?= num($r['_forfettario']) ?></td>
                    <td class="num"><?= num($r['_ore_extra']) ?></td>
                    <td class="num"><?= num(COEFF_ORA_EXTRA) ?></td>
                    <td class="num"><?= num($r['_ore_extra_importo']) ?></td>
                    <td class="num"><?= num($r['_km']) ?></td>
                    <td class="num"><?= num(COEFF_KM) ?></td>
                    <td class="num"><?= num($r['_rimborso_km']) ?></td>
                    <td class="num"><?= num($r['_autostrada']) ?></td>
                    <td><?= e($r['spesa1_descrizione']) ?></td>
                    <td class="num"><?= num($r['_spesa1']) ?></td>
                    <td class="num"><?= num($r['_somme_ricevute']) ?></td>
                    <td class="num"><strong><?= num($r['_totale']) ?></strong></td>
                    <td><?= e($r['stato']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total">
                <td>TOTALI</td><td></td><td></td><td></td>
                <td class="num"><?= num($totals['forfettario']) ?></td>
                <td></td><td></td>
                <td class="num"><?= num($totals['ore_extra_importo']) ?></td>
                <td class="num"><?= num($totals['km']) ?></td>
                <td></td>
                <td class="num"><?= num($totals['rimborso_km']) ?></td>
                <td class="num"><?= num($totals['autostrada']) ?></td>
                <td></td>
                <td class="num"><?= num($totals['spesa1']) ?></td>
                <td class="num"><?= num($totals['somme_ricevute']) ?></td>
                <td class="num"><?= num($totals['totale']) ?></td>
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
    @page { margin: 24px 22px; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1b2733; }
    .top { border-bottom: 2px solid #173b5c; padding-bottom: 12px; margin-bottom: 18px; }
    .brand { width: 34%; float: left; }
    .title { width: 32%; float: left; text-align: center; }
    .meta { width: 30%; float: right; font-size: 9px; }
    .clear { clear: both; }
    h1 { font-size: 23px; margin: 8px 0 4px; color: #0d263d; letter-spacing: .5px; }
    h2 { font-size: 15px; margin: 0 0 4px; color: #0d263d; }
    .subtitle { font-size: 11px; color: #5c6875; }
    .meta table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 3px 0; border: 0; }
    .main-table { width: 100%; border-collapse: collapse; }
    .main-table th { background: #123a5a; color: white; padding: 6px 4px; border: 1px solid #7e96aa; font-size: 8px; }
    .main-table td { padding: 5px 4px; border: 1px solid #d1d7dc; vertical-align: top; }
    .main-table tr:nth-child(even) td { background: #f7f9fb; }
    .num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .small { font-size: 8px; color: #5b6773; }
    .badge { padding: 2px 5px; border-radius: 3px; background: #eef3f8; font-size: 8px; }
    .summary { margin-top: 16px; width: 100%; border-collapse: collapse; }
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
            <tr><td>Data generazione:</td><td><strong>' . e(date('d/m/Y')) . '</strong></td></tr>
            <tr><td>Periodo:</td><td><strong>' . e($periodoLabel) . '</strong></td></tr>
            <tr><td>Stato note:</td><td><strong>' . e($statoLabel) . '</strong></td></tr>
            <tr><td>Numero note:</td><td><strong>' . count($rows) . '</strong></td></tr>
        </table>
    </div>
    <div class="clear"></div>
</div>';

$html .= '<table class="main-table"><thead><tr>
    <th>Cronometrista</th>
    <th>Manifestazione</th>
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

if (count($rows) === 0) {
    $html .= '<tr><td colspan="16" class="center">Nessuna nota spesa trovata per i filtri selezionati.</td></tr>';
} else {
    foreach ($rows as $r) {
        $html .= '<tr>'
            . '<td>' . e($r['cronometrista']) . '</td>'
            . '<td>' . e($r['manifestazione'] ?: $r['nome_gara']) . '<br><span class="small">' . e($r['nome_gara']) . '</span></td>'
            . '<td class="center">' . e(date('d/m/Y', strtotime((string) $r['data_servizio']))) . '</td>'
            . '<td class="num">' . money($r['_forfettario']) . '</td>'
            . '<td class="num">' . num($r['_ore_extra']) . '</td>'
            . '<td class="num">' . money(COEFF_ORA_EXTRA) . '</td>'
            . '<td class="num">' . money($r['_ore_extra_importo']) . '</td>'
            . '<td class="num">' . num($r['_km']) . '</td>'
            . '<td class="num">' . money(COEFF_KM) . '</td>'
            . '<td class="num">' . money($r['_rimborso_km']) . '</td>'
            . '<td class="num">' . money($r['_autostrada']) . '</td>'
            . '<td>' . e($r['spesa1_descrizione']) . '</td>'
            . '<td class="num">' . money($r['_spesa1']) . '</td>'
            . '<td class="num">' . money($r['_somme_ricevute']) . '</td>'
            . '<td class="num"><strong>' . money($r['_totale']) . '</strong></td>'
            . '<td class="center"><span class="badge">' . e($r['stato']) . '</span></td>'
            . '</tr>';
    }
}
$html .= '</tbody></table>';

$html .= '<table class="summary">
    <tr><th colspan="4">Riepilogo generale</th></tr>
    <tr>
        <td>Totale forfettario</td><td class="num">' . money($totals['forfettario']) . '</td>
        <td>Totale diaria</td><td class="num">' . money($totals['ore_extra_importo']) . '</td>
    </tr>
    <tr>
        <td>Totale km</td><td class="num">' . num($totals['km']) . '</td>
        <td>Totale rimborso km</td><td class="num">' . money($totals['rimborso_km']) . '</td>
    </tr>
    <tr>
        <td>Totale autostrada</td><td class="num">' . money($totals['autostrada']) . '</td>
        <td>Totale spese</td><td class="num">' . money($totals['spesa1']) . '</td>
    </tr>
    <tr>
        <td>Somme ricevute</td><td class="num">' . money($totals['somme_ricevute']) . '</td>
        <td>Media per nota</td><td class="num">' . money(count($rows) > 0 ? $totals['totale'] / count($rows) : 0) . '</td>
    </tr>
    <tr class="total"><td colspan="3">TOTALE COMPLESSIVO</td><td class="num">' . money($totals['totale']) . '</td></tr>
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
$dompdf->stream(reportFileName('pdf'), ['Attachment' => $download]);
exit;
