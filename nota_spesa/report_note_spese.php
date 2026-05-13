<?php
declare(strict_types=1);

/**
 * report_note_spese.php
 * Report PDF professionale Note Spese - prima versione
 *
 * Dipendenze:
 *   composer require dompdf/dompdf
 *
 * Parametri GET supportati:
 *   dal=YYYY-MM-DD
 *   al=YYYY-MM-DD
 *   stato=INVIATA|APPROVATA|RESPINTA|LIQUIDATA|BOZZA|TUTTE
 *   rif_gara=ID
 *   rif_utente=ID
 *   download=1|0
 */

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

header_remove('X-Powered-By');

// -----------------------------------------------------------------------------
// Connessione DB
// -----------------------------------------------------------------------------
$cn = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $cn = $pdo;
} elseif (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $cn = $GLOBALS['pdo'];
} elseif (isset($db) && $db instanceof PDO) {
    $cn = $db;
} elseif (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
    $cn = $GLOBALS['db'];
}

if (!$cn instanceof PDO) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'db_connection_not_found',
        'detail' => 'Il file core/bootstrap.php deve esporre una istanza PDO in $pdo oppure $GLOBALS["pdo"].'
    ]);
    exit;
}

$cn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// -----------------------------------------------------------------------------
// Utility
// -----------------------------------------------------------------------------
function get_param_string(string $key, ?string $default = null): ?string
{
    $value = $_GET[$key] ?? $default;
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    return $value === '' ? $default : $value;
}

function get_param_int(string $key): ?int
{
    if (!isset($_GET[$key]) || trim((string)$_GET[$key]) === '') {
        return null;
    }
    return (int)$_GET[$key];
}

function is_valid_date(?string $date): bool
{
    if (!$date) {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function euro($value): string
{
    return '&euro; ' . number_format((float)$value, 2, ',', '.');
}

function num($value, int $decimals = 2): string
{
    return number_format((float)$value, $decimals, ',', '.');
}

function fmt_date(?string $date): string
{
    if (!$date) {
        return '';
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d ? $d->format('d/m/Y') : $date;
}

// -----------------------------------------------------------------------------
// Parametri
// -----------------------------------------------------------------------------
$dal = get_param_string('dal');
$al = get_param_string('al');
$stato = strtoupper((string)get_param_string('stato', 'TUTTE'));
$rifGara = get_param_int('rif_gara');
$rifUtente = get_param_int('rif_utente');
$download = (int)($_GET['download'] ?? 1) === 1;

if ($dal !== null && !is_valid_date($dal)) {
    http_response_code(400);
    echo 'Parametro dal non valido. Formato atteso: YYYY-MM-DD';
    exit;
}
if ($al !== null && !is_valid_date($al)) {
    http_response_code(400);
    echo 'Parametro al non valido. Formato atteso: YYYY-MM-DD';
    exit;
}

// -----------------------------------------------------------------------------
// Query dati
// Nota: viene usata solo spesa1_eur/spesa1_descrizione. spesa2, vitto, alloggio e varie NON sono considerati.
// -----------------------------------------------------------------------------
$where = [];
$params = [];

if ($dal) {
    $where[] = 'ns.data_servizio >= :dal';
    $params[':dal'] = $dal;
}
if ($al) {
    $where[] = 'ns.data_servizio <= :al';
    $params[':al'] = $al;
}
if ($stato !== 'TUTTE' && $stato !== 'TUTTI') {
    $where[] = 'ns.stato = :stato';
    $params[':stato'] = $stato;
}
if ($rifGara !== null) {
    $where[] = 'ns.rif_gara = :rif_gara';
    $params[':rif_gara'] = $rifGara;
}
if ($rifUtente !== null) {
    $where[] = 'ns.rif_utente = :rif_utente';
    $params[':rif_utente'] = $rifUtente;
}

$whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT
        ns.id,
        ns.rif_utente,
        COALESCE(u.nome, CONCAT('Utente #', ns.rif_utente)) AS cronometrista,
        ns.rif_gara,
        g.nome_gara,
        d.nome AS disciplina,
        m.nome AS manifestazione,
        ns.data_servizio,
        COALESCE(ns.km_percorsi, 0) AS km_percorsi,
        COALESCE(ns.spese_autostrada_eur, 0) AS spese_autostrada_eur,
        COALESCE(ns.spesa1_eur, 0) AS spesa1_eur,
        ns.spesa1_descrizione,
        COALESCE(ns.somme_ricevute_eur, 0) AS somme_ricevute_eur,
        COALESCE(ns.tot_importo_ore_extra_eur, 0) AS tot_importo_ore_extra_eur,
        COALESCE(ns.importo_km_percorsi_eur, 0) AS importo_km_percorsi_eur,
        COALESCE(ns.importo_forfettario_eur, 0) AS importo_forfettario_eur,
        COALESCE(ns.totale_ricalcolato_admin_eur, 0) AS totale_ricalcolato_admin_eur,
        ns.stato
    FROM tbl_nota_spesa ns
    LEFT JOIN tbl_utenti u ON u.wp_user_id = ns.rif_utente
    LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
    LEFT JOIN tbl_disciplina d ON d.id = g.rif_disciplina
    LEFT JOIN tbl_manifestazione m ON m.id = g.rif_manifestazione
    $whereSql
    ORDER BY ns.data_servizio ASC, cronometrista ASC, ns.id ASC
";

$stmt = $cn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// -----------------------------------------------------------------------------
// Calcoli riepilogo
// -----------------------------------------------------------------------------
$totForfettario = 0.0;
$totOreExtraImporto = 0.0;
$totKm = 0.0;
$totRimborsoKm = 0.0;
$totAutostrada = 0.0;
$totSpesa1 = 0.0;
$totSommeRicevute = 0.0;
$totComplessivo = 0.0;
$totOreExtra = 0.0;
$coeffOrarioDefault = 6.00;

foreach ($rows as $r) {
    $totForfettario += (float)$r['importo_forfettario_eur'];
    $totOreExtraImporto += (float)$r['tot_importo_ore_extra_eur'];
    $totKm += (float)$r['km_percorsi'];
    $totRimborsoKm += (float)$r['importo_km_percorsi_eur'];
    $totAutostrada += (float)$r['spese_autostrada_eur'];
    $totSpesa1 += (float)$r['spesa1_eur'];
    $totSommeRicevute += (float)$r['somme_ricevute_eur'];
    $totComplessivo += (float)$r['totale_ricalcolato_admin_eur'];
    $totOreExtra += $coeffOrarioDefault > 0 ? ((float)$r['tot_importo_ore_extra_eur'] / $coeffOrarioDefault) : 0;
}

$periodoLabel = ($dal || $al)
    ? 'Dal ' . ($dal ? fmt_date($dal) : 'inizio') . ' al ' . ($al ? fmt_date($al) : 'oggi')
    : 'Tutto il periodo';

$statoLabel = ($stato === 'TUTTE' || $stato === 'TUTTI') ? 'Tutte' : $stato;
$dataGenerazione = (new DateTime())->format('d/m/Y H:i');

// -----------------------------------------------------------------------------
// HTML PDF
// -----------------------------------------------------------------------------
$tableRows = '';

if (count($rows) === 0) {
    $tableRows = '<tr><td colspan="13" class="empty">Nessuna nota spesa trovata per i filtri selezionati.</td></tr>';
} else {
    foreach ($rows as $r) {
        $oreExtra = $coeffOrarioDefault > 0 ? ((float)$r['tot_importo_ore_extra_eur'] / $coeffOrarioDefault) : 0;
        $eurKm = ((float)$r['km_percorsi'] > 0)
            ? ((float)$r['importo_km_percorsi_eur'] / (float)$r['km_percorsi'])
            : 0.35;

        $manifestazione = trim((string)($r['manifestazione'] ?: $r['nome_gara'] ?: ''));
        $spesaDescr = trim((string)($r['spesa1_descrizione'] ?? ''));
        $spesaLabel = euro($r['spesa1_eur']);
        if ($spesaDescr !== '') {
            $spesaLabel .= '<br><span class="muted small">' . h($spesaDescr) . '</span>';
        }

        $tableRows .= '<tr>';
        $tableRows .= '<td class="text-left">' . h($r['cronometrista']) . '</td>';
        $tableRows .= '<td class="text-left">' . h($manifestazione) . '</td>';
        $tableRows .= '<td class="text-center">' . h(fmt_date($r['data_servizio'])) . '</td>';
        $tableRows .= '<td class="num">' . euro($r['importo_forfettario_eur']) . '</td>';
        $tableRows .= '<td class="num">' . num($oreExtra, 2) . '</td>';
        $tableRows .= '<td class="num">' . euro($coeffOrarioDefault) . '</td>';
        $tableRows .= '<td class="num">' . euro($r['tot_importo_ore_extra_eur']) . '</td>';
        $tableRows .= '<td class="num">' . num($r['km_percorsi'], 2) . '</td>';
        $tableRows .= '<td class="num">' . euro($eurKm) . '</td>';
        $tableRows .= '<td class="num">' . euro($r['importo_km_percorsi_eur']) . '</td>';
        $tableRows .= '<td class="num">' . euro($r['spese_autostrada_eur']) . '</td>';
        $tableRows .= '<td class="num">' . $spesaLabel . '</td>';
        $tableRows .= '<td class="num total-cell">' . euro($r['totale_ricalcolato_admin_eur']) . '</td>';
        $tableRows .= '</tr>';
    }
}

$html = <<<HTML
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 18px 18px 28px 18px; }
    body {
        font-family: DejaVu Sans, Arial, sans-serif;
        font-size: 9px;
        color: #101828;
        margin: 0;
    }
    .header {
        width: 100%;
        border-bottom: 1.5px solid #123a5a;
        padding-bottom: 12px;
        margin-bottom: 16px;
    }
    .header td { vertical-align: top; }
    .brand-title {
        font-size: 22px;
        font-weight: 800;
        color: #123a5a;
        letter-spacing: .2px;
        margin: 0 0 4px 0;
    }
    .brand-subtitle {
        font-size: 10px;
        line-height: 1.55;
        color: #344054;
    }
    .report-title {
        text-align: center;
        font-size: 25px;
        font-weight: 800;
        color: #101828;
        margin-top: 6px;
    }
    .report-subtitle {
        text-align: center;
        font-size: 12px;
        color: #344054;
        margin-top: 6px;
    }
    .meta {
        width: 100%;
        font-size: 9.5px;
        border-collapse: collapse;
    }
    .meta td {
        padding: 4px 0 4px 8px;
        border-bottom: 1px solid #eef2f6;
    }
    .meta .label { color: #475467; width: 42%; }
    .meta .value { font-weight: 700; }
    .main-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    .main-table th {
        background: #123a5a;
        color: #fff;
        padding: 7px 4px;
        border: 1px solid #b8c7d3;
        font-size: 7.5px;
        text-transform: uppercase;
    }
    .main-table td {
        padding: 6px 4px;
        border: 1px solid #d9dee6;
        font-size: 7.8px;
        vertical-align: middle;
    }
    .main-table tr:nth-child(even) td { background: #fbfcfd; }
    .text-left { text-align: left; }
    .text-center { text-align: center; }
    .num { text-align: right; white-space: nowrap; }
    .muted { color: #667085; }
    .small { font-size: 6.8px; }
    .total-cell {
        background: #edf4fb !important;
        font-weight: 800;
    }
    .empty {
        text-align: center;
        color: #667085;
        padding: 18px !important;
    }
    .summary-wrap {
        margin-top: 16px;
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 0;
    }
    .summary-box {
        border: 1px solid #d0d5dd;
        vertical-align: top;
    }
    .summary-title {
        background: #123a5a;
        color: #fff;
        font-weight: 800;
        padding: 7px 9px;
        font-size: 10px;
        text-transform: uppercase;
    }
    .summary-table {
        width: 100%;
        border-collapse: collapse;
    }
    .summary-table td {
        border-top: 1px solid #e4e7ec;
        padding: 6px 9px;
        font-size: 9px;
    }
    .summary-table .amount {
        text-align: right;
        font-weight: 700;
    }
    .grand-total td {
        background: #edf4fb;
        font-size: 10px;
        font-weight: 900;
    }
    .note-box {
        min-height: 96px;
        padding: 9px;
        color: #667085;
        line-height: 1.5;
    }
    .footer {
        position: fixed;
        left: 18px;
        right: 18px;
        bottom: 10px;
        border-top: 1px solid #123a5a;
        text-align: center;
        font-size: 8px;
        color: #475467;
        padding-top: 7px;
    }
</style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 32%;">
                <div class="brand-title">CRONOMETRISTI</div>
                <div class="brand-subtitle">
                    Report amministrativo note spese<br>
                    Generato automaticamente dal sistema
                </div>
            </td>
            <td style="width: 38%;">
                <div class="report-title">REPORT NOTE SPESE</div>
                <div class="report-subtitle">Riepilogo spese per cronometristi</div>
            </td>
            <td style="width: 30%;">
                <table class="meta">
                    <tr><td class="label">Data generazione</td><td class="value">{$dataGenerazione}</td></tr>
                    <tr><td class="label">Periodo</td><td class="value">{$periodoLabel}</td></tr>
                    <tr><td class="label">Stato note</td><td class="value">{$statoLabel}</td></tr>
                    <tr><td class="label">Numero note</td><td class="value">{count($rows)}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="main-table">
        <thead>
            <tr>
                <th style="width: 11%;">Cronometrista</th>
                <th style="width: 11%;">Manifestazione</th>
                <th style="width: 7%;">Data</th>
                <th style="width: 8%;">Forfettario</th>
                <th style="width: 6%;">Ore Extra</th>
                <th style="width: 6%;">€/H</th>
                <th style="width: 8%;">Diarie</th>
                <th style="width: 5%;">Km</th>
                <th style="width: 6%;">€/Km</th>
                <th style="width: 8%;">Rimb. Km</th>
                <th style="width: 8%;">Autostrada</th>
                <th style="width: 8%;">Spesa 1</th>
                <th style="width: 8%;">Totale</th>
            </tr>
        </thead>
        <tbody>
            {$tableRows}
        </tbody>
    </table>

    <table class="summary-wrap">
        <tr>
            <td class="summary-box" style="width: 38%;">
                <div class="summary-title">Riepilogo generale</div>
                <table class="summary-table">
                    <tr><td>Totale Forfettario</td><td class="amount">{euro($totForfettario)}</td></tr>
                    <tr><td>Totale Diarie / Ore Extra</td><td class="amount">{euro($totOreExtraImporto)}</td></tr>
                    <tr><td>Totale Rimborso Km</td><td class="amount">{euro($totRimborsoKm)}</td></tr>
                    <tr><td>Totale Autostrada</td><td class="amount">{euro($totAutostrada)}</td></tr>
                    <tr><td>Totale Spesa 1</td><td class="amount">{euro($totSpesa1)}</td></tr>
                    <tr><td>Somme Ricevute</td><td class="amount">{euro($totSommeRicevute)}</td></tr>
                    <tr class="grand-total"><td>Totale Complessivo</td><td class="amount">{euro($totComplessivo)}</td></tr>
                </table>
            </td>
            <td style="width: 2%;"></td>
            <td class="summary-box" style="width: 24%;">
                <div class="summary-title">Altri totali</div>
                <table class="summary-table">
                    <tr><td>Totale Ore Extra</td><td class="amount">{num($totOreExtra, 2)}</td></tr>
                    <tr><td>Totale Km</td><td class="amount">{num($totKm, 2)}</td></tr>
                    <tr><td>Media per Nota</td><td class="amount">{euro(count($rows) > 0 ? $totComplessivo / count($rows) : 0)}</td></tr>
                    <tr><td>Numero Note</td><td class="amount">{count($rows)}</td></tr>
                </table>
            </td>
            <td style="width: 2%;"></td>
            <td class="summary-box" style="width: 34%;">
                <div class="summary-title">Osservazioni</div>
                <div class="note-box">
                    Report generato sulla base dei dati amministrativi presenti in <strong>tbl_nota_spesa</strong>.<br>
                    Le colonne vitto, alloggio e varie non sono incluse perche non presenti nella struttura attuale del database.<br>
                    Per le spese aggiuntive viene considerato esclusivamente il campo <strong>spesa1_eur</strong>.
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Report generato automaticamente dal sistema - Cronometristi
    </div>
</body>
</html>
HTML;

// -----------------------------------------------------------------------------
// Rendering PDF
// -----------------------------------------------------------------------------
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('chroot', realpath(__DIR__ . '/..') ?: __DIR__);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = 'report_note_spese_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, ['Attachment' => $download]);
