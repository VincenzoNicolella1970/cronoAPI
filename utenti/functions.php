<?php
declare(strict_types=1);

function syncUtente(PDO $pdo, array $sourceUser): array
{
    $wpUserId = (int) ($sourceUser['id'] ?? 0);
    $username = trim((string) ($sourceUser['username'] ?? ''));
    $email = trim((string) ($sourceUser['email'] ?? ''));
    $displayName = trim((string) ($sourceUser['display_name'] ?? ''));
    $nome = trim((string) ($sourceUser['first_name'] ?? ''));
    $cognome = trim((string) ($sourceUser['last_name'] ?? ''));
    $ruolo = trim((string) ($sourceUser['roles'] ?? ''));
    if ($wpUserId <= 0 || $username === '' || $email === '') {
        throw new InvalidArgumentException('Dati utente sorgente non validi.');
    }

    $sql = 'SELECT id_utente, ruolo_app, stato
            FROM tbl_utenti
            WHERE wp_user_id = :wp_user_id
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':wp_user_id' => $wpUserId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $sql = 'UPDATE tbl_utenti
                SET username = :username,
                    email = :email,
                    display_name = :display_name,
                    nome = :nome,
                    cognome = :cognome,
                    ruolo_app =:ruolo_app,
                    ultimo_accesso = NOW(),
                    updated_at = NOW()
                WHERE wp_user_id = :wp_user_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':display_name' => $displayName !== '' ? $displayName : null,
            ':nome' => $nome !== '' ? $nome : null,
            ':cognome' => $cognome !== '' ? $cognome : null,
            ':ruolo_app' => $ruolo !== '' ? $ruolo : null,
            ':wp_user_id' => $wpUserId,
        ]);

        return [
            'id_utente' => $wpUserId,
            'ruolo_app' => (string) $existing['ruolo_app'],
            'stato' => (string) $existing['stato'],
            'is_new' => false,
        ];
    }

    $sql = 'INSERT INTO tbl_utenti (
                wp_user_id,
                username,
                email,
                display_name,
                nome,
                cognome,
                ruolo_app,
                stato,
                ultimo_accesso,
                created_at,
                updated_at
            ) VALUES (
                :wp_user_id,
                :username,
                :email,
                :display_name,
                :nome,
                :cognome,
                :ruolo_app,
                :stato,
                NOW(),
                NOW(),
                NOW()
            )';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':wp_user_id' => $wpUserId,
        ':username' => $username,
        ':email' => $email,
        ':display_name' => $displayName !== '' ? $displayName : null,
        ':nome' => $nome !== '' ? $nome : null,
        ':cognome' => $cognome !== '' ? $cognome : null,
        ':ruolo_app' => $ruolo !== '' ? $ruolo : 'nonruolo',
        ':stato' => 'attivo',
    ]);

    return [
        'id_utente' => $wpUserId,// (int) $pdo->lastInsertId(),
        'ruolo_app' => $ruolo,
        'stato' => 'attivo',
        'is_new' => true,
    ];
}
