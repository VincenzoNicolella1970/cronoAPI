<?php
declare(strict_types=1);

function normalizzaGaraPayload(array $data): array
{
    return [
        'nome_gara' => trim((string)($data['nome_gara'] ?? '')),
        'rif_disciplina' => isset($data['rif_disciplina']) ? (int)$data['rif_disciplina'] : null,
        'rif_manifestazione' => isset($data['rif_manifestazione']) ? (int)$data['rif_manifestazione'] : null,
        'rif_regione' => trim((string)($data['rif_regione'] ?? '')),
        'rif_provincia' => trim((string)($data['rif_provincia'] ?? '')),
        'rif_comune' => trim((string)($data['rif_comune'] ?? '')),
        'data_inizio' => trim((string)($data['data_inizio'] ?? '')),
        'data_fine' => trim((string)($data['data_fine'] ?? '')),
        'stato' => trim((string)($data['stato'] ?? 'ATTIVA')),
        'note' => trim((string)($data['note'] ?? '')),
    ];
}

function validaGaraPayload(array $payload): array
{
    $errori = [];

    if ($payload['nome_gara'] === '') {
        $errori['nome_gara'] = 'Il nome della gara è obbligatorio.';
    }

    if (empty($payload['rif_disciplina'])) {
        $errori['rif_disciplina'] = 'La disciplina è obbligatoria.';
    }

    if (empty($payload['rif_manifestazione'])) {
        $errori['rif_manifestazione'] = 'La manifestazione è obbligatoria.';
    }

    if ($payload['data_inizio'] === '') {
        $errori['data_inizio'] = 'La data di inizio è obbligatoria.';
    }

    if ($payload['data_fine'] === '') {
        $errori['data_fine'] = 'La data di fine è obbligatoria.';
    }

    if ($payload['data_inizio'] !== '' && $payload['data_fine'] !== '' && $payload['data_fine'] < $payload['data_inizio']) {
        $errori['data_fine'] = 'La data di fine non può essere precedente alla data di inizio.';
    }

    return $errori;
}
