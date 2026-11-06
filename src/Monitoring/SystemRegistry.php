<?php

declare(strict_types=1);

namespace App\Monitoring;

use Closure;
use PDO;

/**
 * 관리 화면에서 서비스(systems)와 감시 리소스(resources)를 등록·수정한다.
 * 장애·점검 이력이 참조하므로 삭제하지 않고 비활성화한다.
 */
final class SystemRegistry
{
    public const ENVIRONMENTS = ['prod' => '운영', 'stage' => '스테이징', 'dev' => '개발'];

    public const RESOURCE_TYPES = ['http' => '웹/API', 'ec2' => 'EC2', 'rds' => 'RDS'];

    /** 웹/API 리소스의 구분 (resources.config.kind). 관리자가 둘 중 하나를 고른다 */
    public const HTTP_KINDS = ['web' => '웹', 'api' => 'API'];

    /** EC2·RDS 는 AWS 연동 단계 전까지 화면에서 숨긴다 (AppFactory 설정 aws_resources) */
    public const AWS_RESOURCE_TYPES = ['ec2', 'rds'];

    public const CLOUD_PROVIDERS = ['aws' => 'AWS', 'ncloud' => '네이버 클라우드', 'gcp' => 'Google Cloud', 'azure' => 'Azure', 'vercel' => 'Vercel', 'other' => '기타'];

    /** 화면에서 입력받는 resources.config 키 (타입별). 그 외 키는 수정 시 그대로 둔다 */
    public const CONFIG_FIELDS = ['ec2' => ['instance_type'], 'rds' => ['engine', 'max_connections'], 'http' => ['kind']];

    /**
     * 웹/API 구분. 아직 정해지지 않은 리소스(cron 이 JSON 응답 여부로 채우기 전)는 웹으로 본다.
     *
     * @param array<string, mixed> $config
     */
    public static function httpKind(array $config, string $url): string
    {
        $kind = $config['kind'] ?? null;
        if (is_string($kind) && isset(self::HTTP_KINDS[$kind])) {
            return $kind;
        }

        return 'web';
    }

    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(private readonly Closure $connection)
    {
    }

    /**
     * @return list<array<string, mixed>> 비활성 포함, 사용 중인 것 먼저
     */
    public function systems(): array
    {
        return $this->query(
            "SELECT s.id, s.code, s.name, s.environment, s.is_active, s.cloud_provider,
                    (SELECT GROUP_CONCAT(name, ', ') FROM (
                        SELECT u.name FROM system_owners so JOIN users u ON u.id = so.user_id
                        WHERE so.system_id = s.id ORDER BY u.name)) AS owner,
                    a.account_id AS aws_account_id, a.alias AS aws_alias,
                    c.id AS company_id, c.name AS company_name, s.company_id IS NOT NULL AS company_direct,
                    (SELECT COUNT(*) FROM resources r WHERE r.system_id = s.id AND r.is_active = 1) AS resource_count,
                    (SELECT COUNT(*) FROM incidents i WHERE i.system_id = s.id AND i.status <> 'resolved') AS active_incidents
             FROM systems s
             LEFT JOIN aws_accounts a ON a.id = s.aws_account_id
             LEFT JOIN companies c ON c.id = COALESCE(s.company_id, CASE WHEN s.cloud_provider = 'aws' THEN a.company_id END)
             ORDER BY s.is_active DESC, s.id",
        );
    }

    /**
     * 웹/API 리소스별 연결 IP 와 도메인 등록 정보 (DomainInspector 가 갱신). 사용 중인 것만.
     *
     * @return list<array<string, mixed>> domain_info 는 배열로 풀어서 준다
     */
    public function domains(): array
    {
        $overrides = DomainInfo::overrides(($this->connection)());

        return array_map(static function (array $row) use ($overrides): array {
            $row['host'] = (string) parse_url((string) $row['identifier'], PHP_URL_HOST);
            $auto = $row['domain_info'] === null ? null : (array) json_decode((string) $row['domain_info'], true);
            $row['domain'] = DomainInfo::domainOf($row['host'], $auto);
            $row['domain_auto'] = $auto;
            $row['domain_info'] = DomainInfo::merge($auto, $row['domain'] === null ? null : $overrides[$row['domain']] ?? null, $row['domain']);

            return $row;
        }, $this->query(
            "SELECT r.id, r.name, r.identifier, r.resolved_ips, r.resolved_at, r.domain_info, s.id AS system_id, s.name AS system_name
             FROM resources r
             JOIN systems s ON s.id = r.system_id
             WHERE r.type = 'http' AND r.is_active = 1 AND s.is_active = 1
             ORDER BY s.id, r.id",
        ));
    }

    /**
     * @return array{registrar: ?string, expires_at: ?string, nameservers: ?list<string>, memo: ?string, updated_at: string, updated_by: ?string}|null
     */
    public function domainOverride(string $domain): ?array
    {
        return DomainInfo::overrides(($this->connection)())[$domain] ?? null;
    }

    /**
     * 모든 값이 비어 있으면 수동 입력을 지운다 (자동 수집 값으로 되돌림).
     *
     * @param array{registrar: ?string, expires_at: ?string, nameservers: ?list<string>, memo: ?string} $values
     */
    public function saveDomainOverride(string $domain, array $values, ?int $userId): void
    {
        if (array_filter($values, static fn ($v): bool => $v !== null) === []) {
            $this->execute('DELETE FROM domain_overrides WHERE domain = ?', [$domain]);

            return;
        }
        $this->execute(
            'INSERT INTO domain_overrides (domain, registrar, expires_at, nameservers, memo, updated_by_user_id, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (domain) DO UPDATE SET registrar = excluded.registrar, expires_at = excluded.expires_at,
                nameservers = excluded.nameservers, memo = excluded.memo,
                updated_by_user_id = excluded.updated_by_user_id, updated_at = excluded.updated_at',
            [
                $domain,
                $values['registrar'],
                $values['expires_at'],
                $values['nameservers'] === null ? null : json_encode($values['nameservers'], JSON_UNESCAPED_SLASHES),
                $values['memo'],
                $userId,
                \App\Database::now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function system(int $id): ?array
    {
        $system = $this->query('SELECT id, code, name, short_name, environment, cloud_provider, aws_account_id, company_id, description, is_active FROM systems WHERE id = ?', [$id])[0] ?? null;
        if ($system !== null) {
            $system['owner_user_ids'] = $this->ownerIds($id);
        }

        return $system;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resources(int $systemId): array
    {
        return array_map(self::decode(...), $this->query(
            'SELECT * FROM resources WHERE system_id = ? ORDER BY is_active DESC, type, id',
            [$systemId],
        ));
    }

    /**
     * @return array<string, mixed>|null config 는 배열로 풀어서 준다
     */
    public function resource(int $id): ?array
    {
        $row = $this->query('SELECT * FROM resources WHERE id = ?', [$id])[0] ?? null;

        return $row === null ? null : self::decode($row);
    }

    /**
     * @param array{code: string, name: string, short_name?: ?string, environment: string, cloud_provider?: string, aws_account_id?: ?int, company_id?: ?int, owner_user_ids?: list<int>, description: ?string, active: bool} $values
     */
    public function createSystem(array $values): int
    {
        $this->execute(
            'INSERT INTO systems (code, name, short_name, environment, cloud_provider, aws_account_id, company_id, description, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$values['code'], $values['name'], $values['short_name'] ?? null, $values['environment'], $values['cloud_provider'] ?? 'aws', $values['aws_account_id'] ?? null, $values['company_id'] ?? null, $values['description'], (int) $values['active']],
        );
        $id = (int) ($this->connection)()->lastInsertId();
        $this->setOwners($id, $values['owner_user_ids'] ?? []);

        return $id;
    }

    /**
     * @return list<int> 서비스 담당자 계정 ID
     */
    public function ownerIds(int $systemId): array
    {
        return array_map(
            intval(...),
            array_column($this->query('SELECT user_id FROM system_owners WHERE system_id = ? ORDER BY user_id', [$systemId]), 'user_id'),
        );
    }

    /**
     * 담당자를 주어진 목록으로 바꾼다.
     *
     * @param list<int> $userIds
     */
    public function setOwners(int $systemId, array $userIds): void
    {
        $pdo = ($this->connection)();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM system_owners WHERE system_id = ?')->execute([$systemId]);
            $insert = $pdo->prepare('INSERT INTO system_owners (system_id, user_id) VALUES (?, ?)');
            foreach (array_unique($userIds) as $userId) {
                $insert->execute([$systemId, $userId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 코드는 주소(/systems/{code})에 쓰이므로 바꾸지 않는다.
     *
     * @param array{name: string, short_name?: ?string, environment: string, cloud_provider: string, aws_account_id: ?int, company_id?: ?int, owner_user_ids: list<int>, description: ?string, active: bool} $values
     */
    public function updateSystem(int $id, array $values): void
    {
        $this->execute(
            'UPDATE systems SET name = ?, short_name = ?, environment = ?, cloud_provider = ?, aws_account_id = ?, company_id = ?, description = ?, is_active = ? WHERE id = ?',
            [$values['name'], $values['short_name'] ?? null, $values['environment'], $values['cloud_provider'], $values['aws_account_id'], $values['company_id'] ?? null, $values['description'], (int) $values['active'], $id],
        );
        $this->setOwners($id, $values['owner_user_ids']);
    }

    /**
     * @param array{type: string, name: string, identifier: string, region: ?string, external_ref: ?string, config: array<string, mixed>, active: bool} $values
     */
    public function createResource(int $systemId, array $values): int
    {
        $this->execute(
            'INSERT INTO resources (system_id, type, name, identifier, region, external_ref, config, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$systemId, $values['type'], $values['name'], $values['identifier'], $values['region'], $values['external_ref'], self::encode($values['config']), (int) $values['active']],
        );

        return (int) ($this->connection)()->lastInsertId();
    }

    /**
     * 종류(type)는 알림 키·지표 이름과 묶여 있어 바꾸지 않는다. config 는 기존 값에 덮어쓴다.
     *
     * @param array{name: string, identifier: string, region: ?string, external_ref: ?string, config: array<string, mixed>, active: bool} $values
     */
    public function updateResource(int $id, array $values): void
    {
        // 비운 값(null)은 encode 에서 빠진다
        $config = array_merge($this->resource($id)['config'] ?? [], $values['config']);

        $this->execute(
            'UPDATE resources SET name = ?, identifier = ?, region = ?, external_ref = ?, config = ?, is_active = ? WHERE id = ?',
            [$values['name'], $values['identifier'], $values['region'], $values['external_ref'], self::encode($config), (int) $values['active'], $id],
        );
    }

    /**
     * AWS 계정 (루트 계정 관리 정보). 연결된 서비스 이름을 함께 준다.
     *
     * @return list<array<string, mixed>>
     */
    public function awsAccounts(): array
    {
        return $this->query(
            "SELECT a.id, a.account_id, a.alias, a.root_email, a.mfa_enabled, a.default_region, a.notes, a.updated_at,
                    a.company_id, c.name AS company_name,
                    (SELECT group_concat(s.name, ', ') FROM systems s WHERE s.aws_account_id = a.id) AS systems
             FROM aws_accounts a
             LEFT JOIN companies c ON c.id = a.company_id
             ORDER BY a.alias, a.account_id",
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function awsAccount(int $id): ?array
    {
        return $this->query('SELECT id, account_id, alias, root_email, mfa_enabled, default_region, notes, company_id FROM aws_accounts WHERE id = ?', [$id])[0] ?? null;
    }

    public function awsAccountIdTaken(string $accountId): bool
    {
        return $this->query('SELECT 1 FROM aws_accounts WHERE account_id = ?', [$accountId]) !== [];
    }

    /**
     * @param array{account_id: string, alias: string, root_email: ?string, mfa_enabled: ?bool, notes: ?string, company_id?: ?int} $values
     */
    public function createAwsAccount(array $values): int
    {
        $this->execute(
            'INSERT INTO aws_accounts (account_id, alias, root_email, mfa_enabled, notes, company_id) VALUES (?, ?, ?, ?, ?, ?)',
            [$values['account_id'], $values['alias'], $values['root_email'], self::flag($values['mfa_enabled']), $values['notes'], $values['company_id'] ?? null],
        );

        return (int) ($this->connection)()->lastInsertId();
    }

    /**
     * 계정 ID 는 바꾸지 않는다.
     *
     * @param array{alias: string, root_email: ?string, mfa_enabled: ?bool, notes: ?string, company_id?: ?int} $values
     */
    public function updateAwsAccount(int $id, array $values): void
    {
        $this->execute(
            "UPDATE aws_accounts SET alias = ?, root_email = ?, mfa_enabled = ?, notes = ?, company_id = ?, updated_at = datetime('now', '+9 hours') WHERE id = ?",
            [$values['alias'], $values['root_email'], self::flag($values['mfa_enabled']), $values['notes'], $values['company_id'] ?? null, $id],
        );
    }

    /**
     * 관리회사. 관리하는 AWS 계정 별칭을 함께 준다.
     *
     * @return list<array<string, mixed>>
     */
    public function companies(): array
    {
        return $this->query(
            "SELECT c.id, c.name, c.ceo_name, c.business_no, c.notes, c.updated_at,
                    (SELECT group_concat(alias, ', ') FROM (SELECT a.alias FROM aws_accounts a WHERE a.company_id = c.id ORDER BY a.alias)) AS accounts,
                    (SELECT group_concat(name, ', ') FROM (
                        SELECT s.name FROM systems s LEFT JOIN aws_accounts a ON a.id = s.aws_account_id
                        WHERE s.is_active = 1 AND COALESCE(s.company_id, CASE WHEN s.cloud_provider = 'aws' THEN a.company_id END) = c.id ORDER BY s.name)) AS systems,
                    (SELECT group_concat(name, ', ') FROM (SELECT d.name FROM app_store_accounts d WHERE d.company_id = c.id AND d.is_active = 1 ORDER BY d.name)) AS app_accounts
             FROM companies c
             ORDER BY c.name, c.id",
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function company(int $id): ?array
    {
        return $this->query('SELECT id, name, ceo_name, business_no, notes FROM companies WHERE id = ?', [$id])[0] ?? null;
    }

    public function businessNoTaken(string $businessNo, ?int $exceptId): bool
    {
        return $this->query('SELECT 1 FROM companies WHERE business_no = ? AND id <> ?', [$businessNo, $exceptId ?? 0]) !== [];
    }

    /**
     * @param array{name: string, ceo_name: ?string, business_no: ?string, notes: ?string} $values
     */
    public function createCompany(array $values): int
    {
        $this->execute(
            'INSERT INTO companies (name, ceo_name, business_no, notes) VALUES (?, ?, ?, ?)',
            [$values['name'], $values['ceo_name'], $values['business_no'], $values['notes']],
        );

        return (int) ($this->connection)()->lastInsertId();
    }

    /**
     * @param array{name: string, ceo_name: ?string, business_no: ?string, notes: ?string} $values
     */
    public function updateCompany(int $id, array $values): void
    {
        $this->execute(
            "UPDATE companies SET name = ?, ceo_name = ?, business_no = ?, notes = ?, updated_at = datetime('now', '+9 hours') WHERE id = ?",
            [$values['name'], $values['ceo_name'], $values['business_no'], $values['notes'], $id],
        );
    }

    public function codeTaken(string $code): bool
    {
        return $this->query('SELECT 1 FROM systems WHERE code = ?', [$code]) !== [];
    }

    /**
     * 같은 종류 안에서 $column(identifier / external_ref) 값을 다른 리소스가 쓰고 있으면 그 리소스 id.
     */
    public function resourceUsing(string $type, string $column, string $value, ?int $exceptId = null): ?int
    {
        $column = match ($column) {
            'identifier' => 'identifier',
            'external_ref' => 'external_ref',
        };
        $row = $this->query("SELECT id FROM resources WHERE type = ? AND {$column} = ? AND id <> ?", [$type, $value, $exceptId ?? 0])[0] ?? null;

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * 진행 중인 장애 id. $column 은 system_id / resource_id.
     *
     * @return list<int>
     */
    public function activeIncidentIds(string $column, int $id): array
    {
        $column = match ($column) {
            'system_id' => 'system_id',
            'resource_id' => 'resource_id',
        };

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->query("SELECT id FROM incidents WHERE {$column} = ? AND status <> 'resolved' ORDER BY id", [$id]),
        );
    }

    private static function flag(?bool $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $row['config'] = $row['config'] === null ? [] : (array) json_decode((string) $row['config'], true);

        return $row;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function encode(array $config): ?string
    {
        $config = array_filter($config, static fn (mixed $v): bool => $v !== null);

        return $config === [] ? null : json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function query(string $sql, array $params = []): array
    {
        $statement = ($this->connection)()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<mixed> $params
     */
    private function execute(string $sql, array $params): void
    {
        ($this->connection)()->prepare($sql)->execute($params);
    }
}
