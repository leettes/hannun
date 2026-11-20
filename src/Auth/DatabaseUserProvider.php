<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database;
use Closure;
use PDO;

final class DatabaseUserProvider implements UserProvider
{
    private const COLUMNS = 'id, email, name, role, is_active, password_hash, last_login_at, phone, sms_scope, meeting_access, totp_enabled_at';

    /**
     * @param Closure(): PDO $connection 처음 사용할 때 연결한다
     */
    public function __construct(private readonly Closure $connection)
    {
    }

    public function findById(int $id): ?User
    {
        return $this->fetch('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?', [$id]);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->fetch('SELECT ' . self::COLUMNS . ' FROM users WHERE email = ?', [$email]);
    }

    public function recordLogin(int $id, ?string $newPasswordHash = null): void
    {
        ($this->connection)()
            ->prepare('UPDATE users SET last_login_at = ?, password_hash = COALESCE(?, password_hash) WHERE id = ?')
            ->execute([Database::now(), $newPasswordHash, $id]);
    }

    public function all(): array
    {
        return array_map(
            User::fromRow(...),
            ($this->connection)()->query('SELECT ' . self::COLUMNS . ' FROM users ORDER BY is_active DESC, name, id')->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function update(int $id, string $name, string $role, bool $active, ?string $passwordHash = null): void
    {
        ($this->connection)()
            ->prepare('UPDATE users SET name = ?, role = ?, is_active = ?, password_hash = COALESCE(?, password_hash) WHERE id = ?')
            ->execute([$name, $role, (int) $active, $passwordHash, $id]);
    }

    public function updateContact(int $id, ?string $phone, string $smsScope): void
    {
        ($this->connection)()
            ->prepare('UPDATE users SET phone = ?, sms_scope = ? WHERE id = ?')
            ->execute([$phone, $smsScope, $id]);
    }

    public function create(string $email, string $name, string $passwordHash, string $role): int
    {
        $pdo = ($this->connection)();
        $pdo->prepare('INSERT INTO users (email, name, password_hash, role, sms_scope) VALUES (?, ?, ?, ?, ?)')
            ->execute([$email, $name, $passwordHash, $role, User::defaultSmsScope($role)]);

        return (int) $pdo->lastInsertId();
    }

    public function updateMeetingAccess(int $id, string $access): void
    {
        ($this->connection)()->prepare('UPDATE users SET meeting_access = ? WHERE id = ?')->execute([$access, $id]);
    }

    public function totpState(int $id): array
    {
        $statement = ($this->connection)()->prepare('SELECT totp_secret, totp_last_step FROM users WHERE id = ? AND totp_enabled_at IS NOT NULL');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'secret' => $row === false || $row['totp_secret'] === null ? null : (string) $row['totp_secret'],
            'last_step' => $row === false || $row['totp_last_step'] === null ? null : (int) $row['totp_last_step'],
        ];
    }

    public function enableTotp(int $id, string $encryptedSecret, int $step): void
    {
        ($this->connection)()
            ->prepare('UPDATE users SET totp_secret = ?, totp_enabled_at = ?, totp_last_step = ? WHERE id = ?')
            ->execute([$encryptedSecret, Database::now(), $step, $id]);
    }

    public function advanceTotpStep(int $id, int $step): bool
    {
        $statement = ($this->connection)()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)');
        $statement->execute([$step, $id, $step]);

        return $statement->rowCount() === 1;
    }

    public function resetTotp(int $id): void
    {
        ($this->connection)()
            ->prepare('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = ?')
            ->execute([$id]);
    }

    /**
     * @param list<mixed> $params
     */
    private function fetch(string $sql, array $params): ?User
    {
        $statement = ($this->connection)()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : User::fromRow($row);
    }
}
