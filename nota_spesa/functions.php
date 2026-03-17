<?php
declare(strict_types=1);

function nsNullIfEmpty(mixed $value): mixed
{
    if ($value === null) {
        return null;
    }

    if (is_string($value)) {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    return $value;
}

function nsRequireInt(array $data, string $key): int
{
    if (!isset($data[$key]) || !is_numeric($data[$key])) {
        throw new InvalidArgumentException("Campo obbligatorio mancante o non valido: {$key}");
    }

    return (int) $data[$key];
}

function nsValidateStato(?string $stato): string
{
    $allowed = ['BOZZA', 'INVIATA', 'APPROVATA', 'RESPINTA', 'LIQUIDATA'];
    $stato = strtoupper(trim((string) $stato));

    if ($stato === '') {
        return 'BOZZA';
    }

    if (!in_array($stato, $allowed, true)) {
        throw new InvalidArgumentException('Valore stato non valido.');
    }

    return $stato;
}

function nsGetPayload(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Payload JSON non valido.');
    }

    return $data;
}

function nsNotaSpesaExists(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT id FROM tbl_nota_spesa WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return (bool) $stmt->fetchColumn();
}

function nsUtenteExists(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT id_utente FROM tbl_utenti WHERE wp_user_id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return (bool) $stmt->fetchColumn();
}

function nsGaraExists(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT id_gara FROM tbl_gare WHERE id_gara = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return (bool) $stmt->fetchColumn();
}