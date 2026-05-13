<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/functions.php';

/*
 * report_note_spese_export_tcpdf.php
 *
 * Report Note Spese - PDF con TCPDF + CSV / Excel senza Composer.
 *
 * Libreria TCPDF attesa in una di queste posizioni:
 * - ../libs/tcpdf/tcpdf.php
 * - ./libs/tcpdf/tcpdf.php
 * - ../../libs/tcpdf/tcpdf.php
 *
 * Vista attesa:
 * - vw_report_note_spesa
 *
 * Parametri GET / JSON:
 * - formato=pdf|csv|excel|xls     default: pdf
 * - dal=YYYY-MM-DD
 * - al=YYYY-MM-DD
 * - stato=BOZZA|INVIATA|APPROVATA|RIFIUTATA|LIQUIDATA
 * - rif_gara=ID
 * - rif_utente=ID
 * - id=ID                         alias di rif_utente
 * - include_annullate=1           default: escluse gare annullate
 * - download=1                    PDF scaricato; default: PDF aperto inline
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

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function euro(float|int|null $value): string
{
    return '&euro; ' . number_format((float) ($value ?? 0), 2, ',', '.');
}

function euroText(float|int|null $value): string
{
    return '€ ' . number_format((float) ($value ?? 0), 2, ',', '.');
}

function dec(float|int|null $value, int $decimals = 2): string
{
    return number_format((float) ($value ?? 0), $decimals, ',', '.');
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

function cleanForExcel(mixed $value): string
{
    return str_replace(["\r", "\n", "\t"], ' ', (string) ($value ?? ''));
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
    $where[] = 'r.data_servizio >= :dal';
    $params[':dal'] = $dal;
}

if ($al !== null) {
    $where[] = 'r.data_servizio <= :al';
    $params[':al'] = $al;
}

if ($stato !== null) {
    $where[] = 'UPPER(r.stato) = :stato';
    $params[':stato'] = $stato;
}

if ($rifGara !== null) {
    $where[] = 'r.rif_gara = :rif_gara';
    $params[':rif_gara'] = (int) $rifGara;
}

if ($rifUtente !== null) {
    $where[] = 'r.rif_utente = :rif_utente';
    $params[':rif_utente'] = (int) $rifUtente;
}

if (!$includeAnnullate) {
    $where[] = "(r.stato_gara IS NULL OR UPPER(r.stato_gara) <> 'ANNULLATA')";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        r.id,
        r.rif_utente,
        COALESCE(r.utente, CONCAT('Utente ', r.rif_utente)) AS cronometrista,
        r.rif_gara,
        r.nome_gara,
        r.disciplina,
        r.manifestazione,
        r.data_servizio,
        r.importo_forfettario_eur,
        r.tot_importo_ore_extra_eur,
        r.km_percorsi,
        r.importo_km_percorsi_eur,
        r.spese_autostrada_eur,
        r.spesa1_descrizione,
        r.spesa1_eur,
        r.somme_ricevute_eur,
        r.totale_ricalcolato_admin_eur,
        r.stato,
        r.stato_gara,
        r.created_at,
        r.updated_at
    FROM vw_report_note_spesa r
    {$whereSql}
    ORDER BY r.data_servizio ASC, cronometrista ASC, r.id ASC
";

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Errore query report. Verifica che la vista vw_report_note_spesa esista e contenga i campi previsti. Dettaglio: ' . $e->getMessage());
}

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
    <tr><?php foreach ($headers as $header): ?><th><?= h($header) ?></th><?php endforeach; ?></tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= h(cleanForExcel($r['cronometrista'])) ?></td>
            <td><?= h(cleanForExcel($r['manifestazione'])) ?></td>
            <td><?= h(cleanForExcel($r['nome_gara'])) ?></td>
            <td class="date"><?= h(dateIt($r['data_servizio'] ?? null)) ?></td>
            <td class="num"><?= dec($r['_forfettario']) ?></td>
            <td class="num"><?= dec($r['_ore_extra']) ?></td>
            <td class="num"><?= dec(COEFF_ORA_EXTRA) ?></td>
            <td class="num"><?= dec($r['_diaria']) ?></td>
            <td class="num"><?= dec($r['_km']) ?></td>
            <td class="num"><?= dec(COEFF_KM) ?></td>
            <td class="num"><?= dec($r['_rimborso_km']) ?></td>
            <td class="num"><?= dec($r['_autostrada']) ?></td>
            <td><?= h(cleanForExcel($r['spesa1_descrizione'])) ?></td>
            <td class="num"><?= dec($r['_spesa1']) ?></td>
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

$tcpdfPaths = [
    __DIR__ . '/../libs/tcpdf/tcpdf.php',
    __DIR__ . '/libs/tcpdf/tcpdf.php',
    __DIR__ . '/../../libs/tcpdf/tcpdf.php',
];

$tcpdfLoaded = false;
foreach ($tcpdfPaths as $tcpdfPath) {
    if (file_exists($tcpdfPath)) {
        require_once $tcpdfPath;
        $tcpdfLoaded = true;
        break;
    }
}

if (!$tcpdfLoaded || !class_exists('TCPDF')) {
    http_response_code(500);
    exit('TCPDF non trovato. Scarica TCPDF e copia la cartella in cronoAPI/libs/tcpdf/ in modo che esista libs/tcpdf/tcpdf.php.');
}

$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('CronoFR');
$pdf->SetAuthor('CronoFR');
$pdf->SetTitle('Report Note Spese');
$pdf->SetSubject('Report amministrativo note spese');
$pdf->SetKeywords('report, note spese, cronometristi');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);
$pdf->SetMargins(5, 7, 5);
$pdf->SetAutoPageBreak(true, 9);
$pdf->setFooterMargin(4);
$pdf->SetFont('dejavusans', '', 6.2);
$pdf->AddPage();

function pdfText(mixed $value, int $max = 0): string
{
    $text = trim((string) ($value ?? ''));
    $text = preg_replace('/\s+/', ' ', $text);

    if ($max > 0 && strlen($text) > $max) {
        $text = substr($text, 0, $max - 1) . '…';
    }

    return h($text);
}

function pdfCell(string $content, string $width, string $class = '', string $align = 'left'): string
{
    $alignAttr = $align !== '' ? ' align="' . $align . '"' : '';
    $classAttr = $class !== '' ? ' class="' . $class . '"' : '';
    return '<td width="' . $width . '"' . $classAttr . $alignAttr . '>' . $content . '</td>';
}

function pdfHead(string $label, string $width, string $align = 'center'): string
{
    return '<th width="' . $width . '" align="' . $align . '">' . h($label) . '</th>';
}

$colWidths = [
    'cronometrista' => '8%',
    'manifestazione' => '17%',
    'data' => '5.5%',
    'forfettario' => '5.5%',
    'ore' => '4%',
    'euro_h' => '4.5%',
    'diaria' => '5.5%',
    'km' => '4.5%',
    'euro_km' => '4.5%',
    'rimborso_km' => '5.8%',
    'autostrada' => '5.8%',
    'spesa_descrizione' => '8.2%',
    'spesa_importo' => '5.5%',
    'ricevute' => '5.5%',
    'totale' => '5.8%',
    'stato' => '4.4%',
];

$html = '<style>
    body { color: #1b2733; }
    .header-table { width: 100%; border-bottom: 2px solid #173b5c; }
    .brand { font-size: 11px; font-weight: bold; color: #0d263d; }
    .title { font-size: 17px; font-weight: bold; text-align: center; color: #0d263d; }
    .subtitle { font-size: 7px; color: #5c6875; }
    .meta { font-size: 7px; }
    .main-table { width: 100%; border-collapse: collapse; }
    .main-table th { background-color: #123a5a; color: #ffffff; font-weight: bold; border: 1px solid #7e96aa; padding: 3px 1px; font-size: 5.8px; }
    .main-table td { border: 1px solid #d1d7dc; padding: 2px 1px; font-size: 5.7px; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .center { text-align: center; }
    .small { font-size: 5.1px; color: #5b6773; }
    .total-cell { font-weight: bold; }
    .summary { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .summary th { background-color: #123a5a; color: #ffffff; padding: 5px; font-size: 8px; text-align: left; }
    .summary td { border: 1px solid #d1d7dc; padding: 5px; font-size: 7.2px; }
    .summary-total td { background-color: #dbe8f5; font-size: 9px; font-weight: bold; }
</style>';

$html .= '<table class="header-table" cellpadding="2">
    <tr>
        <td width="30%">
            <div class="brand">CRONOMETRISTI</div>
            <div class="subtitle">Report amministrativo note spese</div>
        </td>
        <td width="40%" class="title">REPORT NOTE SPESE<br><span class="subtitle">Riepilogo economico cronometristi</span></td>
        <td width="30%" class="meta">
            <strong>Data generazione:</strong> ' . h(date('d/m/Y')) . '<br>
            <strong>Periodo:</strong> ' . h($periodoLabel) . '<br>
            <strong>Stato note:</strong> ' . h($statoLabel) . '<br>
            <strong>Numero note:</strong> ' . count($rows) . '
        </td>
    </tr>
</table><br>';

$html .= '<table class="main-table" cellpadding="2" cellspacing="0">
<thead>
<tr>'
    . pdfHead('Cronometrista', $colWidths['cronometrista'])
    . pdfHead('Manifestazione / Gara', $colWidths['manifestazione'])
    . pdfHead('Data', $colWidths['data'])
    . pdfHead('Forf.', $colWidths['forfettario'])
    . pdfHead('Ore', $colWidths['ore'])
    . pdfHead('€/H', $colWidths['euro_h'])
    . pdfHead('Diaria', $colWidths['diaria'])
    . pdfHead('Km', $colWidths['km'])
    . pdfHead('€/Km', $colWidths['euro_km'])
    . pdfHead('Rimb. Km', $colWidths['rimborso_km'])
    . pdfHead('Autostr.', $colWidths['autostrada'])
    . pdfHead('Spesa descr.', $colWidths['spesa_descrizione'])
    . pdfHead('Spesa €', $colWidths['spesa_importo'])
    . pdfHead('Ricev.', $colWidths['ricevute'])
    . pdfHead('Totale', $colWidths['totale'])
    . pdfHead('Stato', $colWidths['stato'])
. '</tr>
</thead>
<tbody>';

if (!$rows) {
    $html .= '<tr><td colspan="16" align="center">Nessuna nota spesa trovata per i filtri selezionati.</td></tr>';
} else {
    foreach ($rows as $r) {
        $manifestazione = trim((string) ($r['manifestazione'] ?? '')) !== '' ? $r['manifestazione'] : $r['nome_gara'];
        $gara = trim((string) ($r['nome_gara'] ?? ''));
        $manifestazioneCell = pdfText($manifestazione, 58);
        if ($gara !== '' && $gara !== (string) $manifestazione) {
            $manifestazioneCell .= '<br><span class="small">' . pdfText($gara, 48) . '</span>';
        }

        $html .= '<tr>'
            . pdfCell(pdfText($r['cronometrista'], 28), $colWidths['cronometrista'])
            . pdfCell($manifestazioneCell, $colWidths['manifestazione'])
            . pdfCell(h(dateIt($r['data_servizio'] ?? null)), $colWidths['data'], 'center', 'center')
            . pdfCell(euro($r['_forfettario']), $colWidths['forfettario'], 'num', 'right')
            . pdfCell(dec($r['_ore_extra']), $colWidths['ore'], 'num', 'right')
            . pdfCell(euro(COEFF_ORA_EXTRA), $colWidths['euro_h'], 'num', 'right')
            . pdfCell(euro($r['_diaria']), $colWidths['diaria'], 'num', 'right')
            . pdfCell(dec($r['_km']), $colWidths['km'], 'num', 'right')
            . pdfCell(euro(COEFF_KM), $colWidths['euro_km'], 'num', 'right')
            . pdfCell(euro($r['_rimborso_km']), $colWidths['rimborso_km'], 'num', 'right')
            . pdfCell(euro($r['_autostrada']), $colWidths['autostrada'], 'num', 'right')
            . pdfCell(pdfText($r['spesa1_descrizione'], 34), $colWidths['spesa_descrizione'])
            . pdfCell(euro($r['_spesa1']), $colWidths['spesa_importo'], 'num', 'right')
            . pdfCell(euro($r['_somme_ricevute']), $colWidths['ricevute'], 'num', 'right')
            . pdfCell('<strong>' . euro($r['_totale']) . '</strong>', $colWidths['totale'], 'num total-cell', 'right')
            . pdfCell(pdfText($r['stato'], 11), $colWidths['stato'], 'center', 'center')
            . '</tr>';
    }
}

$html .= '</tbody></table><br>';

$html .= '<table class="summary" cellpadding="4" cellspacing="0">
    <tr><th colspan="4">Riepilogo generale</th></tr>
    <tr>
        <td width="25%">Totale forfettario</td><td width="25%" class="num">' . euro($totals['forfettario']) . '</td>
        <td width="25%">Totale diaria</td><td width="25%" class="num">' . euro($totals['diaria']) . '</td>
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
    <tr class="summary-total"><td colspan="3">TOTALE COMPLESSIVO</td><td class="num">' . euro($totals['totale']) . '</td></tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output(reportFilename('pdf'), $download ? 'D' : 'I');
exit;
