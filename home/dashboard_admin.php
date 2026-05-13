<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function mesiPeriodo(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $mesi = [];
    $nomiMesi = [
        '01' => 'Gen',
        '02' => 'Feb',
        '03' => 'Mar',
        '04' => 'Apr',
        '05' => 'Mag',
        '06' => 'Giu',
        '07' => 'Lug',
        '08' => 'Ago',
        '09' => 'Set',
        '10' => 'Ott',
        '11' => 'Nov',
        '12' => 'Dic',
    ];

    $cursor = $start->modify('first day of this month');

    while ($cursor <= $end) {
        $key = $cursor->format('Y-m');
        $meseNum = $cursor->format('m');

        $mesi[$key] = [
            'mese' => $nomiMesi[$meseNum] ?? $cursor->format('M'),
            'gare' => 0,
            'note' => 0,
        ];

        $cursor = $cursor->modify('+1 month');
    }

    return $mesi;
}

function formattaPeriodo(DateTimeImmutable $start, DateTimeImmutable $end): string
{
    return $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y');
}

function etichettaStato(string $stato): ?string
{
    return match (strtoupper($stato)) {
        'BOZZA' => 'Bozza',
        'INVIATA' => 'Inviate',
        'APPROVATA' => 'Approvate',
        'RESPINTA' => 'Rifiutate',
        'LIQUIDATA' => 'Liquidate',
        default => null,
    };
}

function parseDateOrDefault(?string $value, DateTimeImmutable $default): DateTimeImmutable
{
    if (!$value) {
        return $default;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if ($dt instanceof DateTimeImmutable) {
        return $dt;
    }

    return $default;
}

try {
    $oggi = new DateTimeImmutable('today');
    $inizioDefault = new DateTimeImmutable($oggi->format('Y-01-01'));

    $dataDaRaw = isset($_GET['data_da']) ? trim((string) $_GET['data_da']) : '';
    $dataARaw = isset($_GET['data_a']) ? trim((string) $_GET['data_a']) : '';

    $inizio = parseDateOrDefault($dataDaRaw, $inizioDefault);
    $fine = parseDateOrDefault($dataARaw, $oggi);

    if ($fine < $inizio) {
        [$inizio, $fine] = [$fine, $inizio];
    }

    // KPI
    $stmtKpi = $pdo->prepare("
        SELECT
            (SELECT COUNT(*)
             FROM tbl_gare g
             WHERE g.data_inizio BETWEEN :data_inizio_1 AND :data_fine_1
               AND UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA') AS gare_totali,

            (SELECT COUNT(*)
             FROM tbl_nota_spesa ns
             LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
             WHERE ns.data_servizio BETWEEN :data_inizio_2 AND :data_fine_2
               AND (g.id_gara IS NULL OR UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA')) AS note_totali,

            (SELECT COUNT(*)
             FROM tbl_nota_spesa ns
             LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
             WHERE ns.data_servizio BETWEEN :data_inizio_3 AND :data_fine_3
               AND ns.stato = 'INVIATA'
               AND (g.id_gara IS NULL OR UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA')) AS note_inviate,

            (SELECT COUNT(*)
             FROM tbl_nota_spesa ns
             LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
             WHERE ns.data_servizio BETWEEN :data_inizio_4 AND :data_fine_4
               AND ns.stato = 'APPROVATA'
               AND (g.id_gara IS NULL OR UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA')) AS note_approvate,

            (SELECT COUNT(*)
             FROM tbl_nota_spesa ns
             LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
             WHERE ns.data_servizio BETWEEN :data_inizio_5 AND :data_fine_5
               AND ns.stato = 'RESPINTA'
               AND (g.id_gara IS NULL OR UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA')) AS note_rifiutate,

            (SELECT COUNT(*)
             FROM tbl_nota_spesa ns
             LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
             WHERE ns.data_servizio BETWEEN :data_inizio_6 AND :data_fine_6
               AND ns.stato = 'LIQUIDATA'
               AND (g.id_gara IS NULL OR UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA')) AS note_liquidate
    ");
    $stmtKpi->execute([
        ':data_inizio_1' => $inizio->format('Y-m-d'),
        ':data_fine_1' => $fine->format('Y-m-d'),
        ':data_inizio_2' => $inizio->format('Y-m-d'),
        ':data_fine_2' => $fine->format('Y-m-d'),
        ':data_inizio_3' => $inizio->format('Y-m-d'),
        ':data_fine_3' => $fine->format('Y-m-d'),
        ':data_inizio_4' => $inizio->format('Y-m-d'),
        ':data_fine_4' => $fine->format('Y-m-d'),
        ':data_inizio_5' => $inizio->format('Y-m-d'),
        ':data_fine_5' => $fine->format('Y-m-d'),
        ':data_inizio_6' => $inizio->format('Y-m-d'),
        ':data_fine_6' => $fine->format('Y-m-d'),
    ]);
    $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC) ?: [];

    // Andamento mensile
    $mesi = mesiPeriodo($inizio, $fine);

    $stmtGareMensili = $pdo->prepare("
        SELECT DATE_FORMAT(g.data_inizio, '%Y-%m') AS ym, COUNT(*) AS totale
        FROM tbl_gare g
        WHERE g.data_inizio BETWEEN :data_inizio AND :data_fine
          AND UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA'
        GROUP BY DATE_FORMAT(g.data_inizio, '%Y-%m')
        ORDER BY ym
    ");
    $stmtGareMensili->execute([
        ':data_inizio' => $inizio->format('Y-m-d'),
        ':data_fine' => $fine->format('Y-m-d'),
    ]);
    foreach ($stmtGareMensili->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($mesi[$row['ym']])) {
            $mesi[$row['ym']]['gare'] = (int) $row['totale'];
        }
    }

    $stmtNoteMensili = $pdo->prepare("
        SELECT DATE_FORMAT(ns.data_servizio, '%Y-%m') AS ym, COUNT(*) AS totale
        FROM tbl_nota_spesa ns
        LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
        WHERE ns.data_servizio BETWEEN :data_inizio AND :data_fine
          AND (g.id_gara IS NULL OR UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA')
        GROUP BY DATE_FORMAT(ns.data_servizio, '%Y-%m')
        ORDER BY ym
    ");
    $stmtNoteMensili->execute([
        ':data_inizio' => $inizio->format('Y-m-d'),
        ':data_fine' => $fine->format('Y-m-d'),
    ]);
    foreach ($stmtNoteMensili->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($mesi[$row['ym']])) {
            $mesi[$row['ym']]['note'] = (int) $row['totale'];
        }
    }

    // Stato note
    $statoNote = [
        'Bozza' => 0,
        'Inviate' => 0,
        'Approvate' => 0,
        'Rifiutate' => 0,
        'Liquidate' => 0,
    ];

    $stmtStati = $pdo->prepare("
        SELECT ns.stato, COUNT(*) AS totale
        FROM tbl_nota_spesa ns
        LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
        WHERE ns.data_servizio BETWEEN :data_inizio AND :data_fine
          AND ns.stato IN ('BOZZA', 'INVIATA', 'APPROVATA', 'RESPINTA', 'LIQUIDATA')
          AND (g.id_gara IS NULL OR UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA')
        GROUP BY ns.stato
    ");
    $stmtStati->execute([
        ':data_inizio' => $inizio->format('Y-m-d'),
        ':data_fine' => $fine->format('Y-m-d'),
    ]);
    foreach ($stmtStati->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $label = etichettaStato((string) $row['stato']);
        if ($label !== null) {
            $statoNote[$label] = (int) $row['totale'];
        }
    }

    // Ultime attività
    $stmtAttivita = $pdo->prepare("
        SELECT *
        FROM (
            SELECT
                CONCAT('Creata gara \"', g.nome_gara, '\"') AS testo,
                DATE(g.created_at) AS data_attivita,
                g.created_at AS data_sort,
                1 AS ordinamento
            FROM tbl_gare g
            WHERE DATE(g.created_at) BETWEEN :data_inizio_1 AND :data_fine_1
              AND UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA'

            UNION ALL

            SELECT
                CONCAT('Nota spesa inviata da ', COALESCE(u.nome, u.display_name, u.username, CONCAT('Utente #', ns.rif_utente))) AS testo,
                DATE(COALESCE(ns.updated_at, ns.created_at)) AS data_attivita,
                COALESCE(ns.updated_at, ns.created_at) AS data_sort,
                2 AS ordinamento
            FROM tbl_nota_spesa ns
            LEFT JOIN tbl_utenti u ON u.wp_user_id = ns.rif_utente
            LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
            WHERE ns.stato = 'INVIATA'
              AND DATE(COALESCE(ns.updated_at, ns.created_at)) BETWEEN :data_inizio_2 AND :data_fine_2
              AND (g.id_gara IS NULL OR UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA')

            UNION ALL

            SELECT
                CONCAT('Nota spesa approvata per ', COALESCE(g.nome_gara, CONCAT('Gara #', ns.rif_gara))) AS testo,
                DATE(COALESCE(ns.updated_at, ns.created_at)) AS data_attivita,
                COALESCE(ns.updated_at, ns.created_at) AS data_sort,
                3 AS ordinamento
            FROM tbl_nota_spesa ns
            LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
            WHERE ns.stato = 'APPROVATA'
              AND DATE(COALESCE(ns.updated_at, ns.created_at)) BETWEEN :data_inizio_3 AND :data_fine_3
              AND (g.id_gara IS NULL OR UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA')

            UNION ALL

            SELECT
                CONCAT('Nota spesa rifiutata per ', COALESCE(g.nome_gara, CONCAT('Gara #', ns.rif_gara))) AS testo,
                DATE(COALESCE(ns.updated_at, ns.created_at)) AS data_attivita,
                COALESCE(ns.updated_at, ns.created_at) AS data_sort,
                4 AS ordinamento
            FROM tbl_nota_spesa ns
            LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
            WHERE ns.stato = 'RESPINTA'
              AND DATE(COALESCE(ns.updated_at, ns.created_at)) BETWEEN :data_inizio_4 AND :data_fine_4
              AND (g.id_gara IS NULL OR UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA')

            UNION ALL

            SELECT
                CONCAT('Liquidata nota spesa #NS', LPAD(ns.id, 4, '0')) AS testo,
                DATE(COALESCE(ns.updated_at, ns.created_at)) AS data_attivita,
                COALESCE(ns.updated_at, ns.created_at) AS data_sort,
                5 AS ordinamento
            FROM tbl_nota_spesa ns
            LEFT JOIN tbl_gare g ON g.id_gara = ns.rif_gara
            WHERE ns.stato = 'LIQUIDATA'
              AND DATE(COALESCE(ns.updated_at, ns.created_at)) BETWEEN :data_inizio_5 AND :data_fine_5
              AND (g.id_gara IS NULL OR UPPER(COALESCE(g.stato, '')) <> 'ANNULLATA')
        ) t
        ORDER BY t.data_sort DESC, t.ordinamento ASC
        LIMIT 8
    ");
    $stmtAttivita->execute([
        ':data_inizio_1' => $inizio->format('Y-m-d'),
        ':data_fine_1' => $fine->format('Y-m-d'),
        ':data_inizio_2' => $inizio->format('Y-m-d'),
        ':data_fine_2' => $fine->format('Y-m-d'),
        ':data_inizio_3' => $inizio->format('Y-m-d'),
        ':data_fine_3' => $fine->format('Y-m-d'),
        ':data_inizio_4' => $inizio->format('Y-m-d'),
        ':data_fine_4' => $fine->format('Y-m-d'),
        ':data_inizio_5' => $inizio->format('Y-m-d'),
        ':data_fine_5' => $fine->format('Y-m-d'),
    ]);

    $ultimeAttivita = [];
    $idx = 1;
    foreach ($stmtAttivita->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ultimeAttivita[] = [
            'id' => $idx++,
            'testo' => (string) $row['testo'],
            'data' => (new DateTimeImmutable((string) $row['data_attivita']))->format('d/m/Y'),
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'periodo' => formattaPeriodo($inizio, $fine),
            'dataDa' => $inizio->format('Y-m-d'),
            'dataA' => $fine->format('Y-m-d'),
            'gareTotali' => (int) ($kpi['gare_totali'] ?? 0),
            'noteTotali' => (int) ($kpi['note_totali'] ?? 0),
            'noteInviate' => (int) ($kpi['note_inviate'] ?? 0),
            'noteApprovate' => (int) ($kpi['note_approvate'] ?? 0),
            'noteRifiutate' => (int) ($kpi['note_rifiutate'] ?? 0),
            'noteLiquidate' => (int) ($kpi['note_liquidate'] ?? 0),
            'ultimeAttivita' => $ultimeAttivita,
            'andamentoMensile' => array_values($mesi),
            'statoNote' => [
                ['nome' => 'Bozza', 'valore' => $statoNote['Bozza']],
                ['nome' => 'Inviate', 'valore' => $statoNote['Inviate']],
                ['nome' => 'Approvate', 'valore' => $statoNote['Approvate']],
                ['nome' => 'Rifiutate', 'valore' => $statoNote['Rifiutate']],
                ['nome' => 'Liquidate', 'valore' => $statoNote['Liquidate']],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Errore caricamento dashboard admin.',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
