<?php

declare(strict_types=1);

namespace App\Monitoring;

use Closure;
use PDO;

/**
 * 서비스별 앱과 개발자 계정 (docs/APPS.md). 참조되므로 삭제하지 않고 비활성화한다.
 *
 * 앱의 관리회사 = 개발자 계정의 관리회사, 없으면 서비스의 관리회사(서비스 직접 지정, 없으면 AWS 계정의 관리회사).
 */
final class AppRegistry
{
    public const PLATFORMS = ['ios' => 'iOS', 'android' => 'Android', 'web' => '웹앱'];

    public const ACCOUNT_PLATFORMS = ['apple' => 'Apple', 'google' => 'Google'];

    /** 개발자 답변까지 주는 리뷰 출처. 이 출처의 리뷰만 답변 여부를 센다 (App Store 공개 피드 itunes_rss 는 답변이 없음) */
    public const REPLY_SOURCES = ['app_store_connect', 'google_play'];

    /** 스토어 상세 이동 버튼 문구. 웹앱은 앱 주소를 연다 */
    public const STORE_LABELS = ['ios' => 'App Store', 'android' => 'Google Play', 'web' => '앱 열기'];

    /** 앱 플랫폼 → 올릴 수 있는 개발자 계정 플랫폼. 웹앱은 계정 없음 */
    public const ACCOUNT_FOR = ['ios' => 'apple', 'android' => 'google'];

    private const COMPANY = "COALESCE(acc.company_id, s.company_id, CASE WHEN s.cloud_provider = 'aws' THEN aws.company_id END)";

    private const APP_SELECT = 'SELECT p.id, p.system_id, p.platform, p.name, p.bundle_id, p.store_url, p.account_id, p.version, p.released_at,
                p.checked_at, p.notes, p.is_active, p.created_at, p.updated_at, p.store_info, p.icon_file, p.icon_error, p.store_error,
                s.code AS system_code, s.name AS system_name,
                acc.name AS account_name, acc.membership_expires_at, acc.is_active AS account_active,
                c.id AS company_id, c.name AS company_name
         FROM apps p
         JOIN systems s ON s.id = p.system_id
         LEFT JOIN app_store_accounts acc ON acc.id = p.account_id
         LEFT JOIN aws_accounts aws ON aws.id = s.aws_account_id
         LEFT JOIN companies c ON c.id = ' . self::COMPANY;

    /**
     * @param Closure(): PDO $connection
     */
    public function __construct(private readonly Closure $connection)
    {
    }

    /** 관리회사 필터에서 '미지정' */
    public const NO_COMPANY = 'none';

    /**
     * @param int|string|null $company 관리회사 ID, NO_COMPANY(미지정), null(전체)
     * @return list<array<string, mixed>> 사용 중인 것 먼저, 서비스·플랫폼·이름 순
     */
    public function apps(?string $platform = null, int|string|null $company = null): array
    {
        [$where, $params] = self::filters($platform, $company);

        return $this->query(
            self::APP_SELECT . $where . "
             ORDER BY p.is_active DESC, s.id, CASE p.platform WHEN 'ios' THEN 0 WHEN 'android' THEN 1 ELSE 2 END, p.name, p.id",
            $params,
        );
    }

    /**
     * 앱이 있는 관리회사와 앱 수. 미지정 앱이 있으면 마지막에 id = NO_COMPANY 로 붙인다.
     *
     * @return list<array{id: int|string, name: string, count: int}>
     */
    public function companiesWithApps(): array
    {
        $rows = [];
        $none = 0;
        foreach ($this->query('SELECT c.id, c.name, COUNT(*) AS n FROM (' . self::APP_SELECT . ') x LEFT JOIN companies c ON c.id = x.company_id GROUP BY c.id ORDER BY c.name') as $row) {
            if ($row['id'] === null) {
                $none = (int) $row['n'];
            } else {
                $rows[] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'count' => (int) $row['n']];
            }
        }
        if ($none > 0) {
            $rows[] = ['id' => self::NO_COMPANY, 'name' => '미지정', 'count' => $none];
        }

        return $rows;
    }

    /**
     * @param int|string|null $company
     * @return array{string, list<mixed>}
     */
    private static function filters(?string $platform, int|string|null $company): array
    {
        $conditions = [];
        $params = [];
        if ($platform !== null) {
            $conditions[] = 'p.platform = ?';
            $params[] = $platform;
        }
        if ($company === self::NO_COMPANY) {
            $conditions[] = 'c.id IS NULL';
        } elseif ($company !== null) {
            $conditions[] = 'c.id = ?';
            $params[] = (int) $company;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * 서비스 상세용. 사용 중인 앱만.
     *
     * @return list<array<string, mixed>>
     */
    public function appsForSystem(string $code): array
    {
        return $this->query(
            self::APP_SELECT . " WHERE s.code = ? AND p.is_active = 1
             ORDER BY CASE p.platform WHEN 'ios' THEN 0 WHEN 'android' THEN 1 ELSE 2 END, p.name, p.id",
            [$code],
        );
    }

    /**
     * @param int|string|null $company 관리회사 필터 (탭 숫자도 같은 회사 기준)
     * @return array<string, int> 플랫폼 => 앱 수 (비활성 포함)
     */
    public function platformCounts(int|string|null $company = null): array
    {
        [$where, $params] = self::filters(null, $company);
        $counts = array_fill_keys(array_keys(self::PLATFORMS), 0);
        foreach ($this->query('SELECT platform, COUNT(*) AS n FROM (' . self::APP_SELECT . $where . ') x GROUP BY platform', $params) as $row) {
            $counts[$row['platform']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function app(int $id): ?array
    {
        return $this->query(self::APP_SELECT . ' WHERE p.id = ?', [$id])[0] ?? null;
    }

    /**
     * 같은 플랫폼에 이미 쓰는 번들 ID 의 앱 ID
     */
    public function appUsingBundleId(string $platform, string $bundleId, ?int $exceptId): ?int
    {
        $id = $this->query('SELECT id FROM apps WHERE platform = ? AND bundle_id = ? AND id <> ?', [$platform, $bundleId, $exceptId ?? 0])[0]['id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /**
     * @param array{system_id: int, platform: string, name: string, bundle_id: ?string, store_url: ?string, account_id: ?int, version: ?string, released_at: ?string, notes: ?string, active: bool} $values
     */
    public function createApp(array $values): int
    {
        $this->execute(
            'INSERT INTO apps (system_id, platform, name, bundle_id, store_url, account_id, version, released_at, notes, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$values['system_id'], $values['platform'], $values['name'], $values['bundle_id'], $values['store_url'], $values['account_id'],
                $values['version'], $values['released_at'], $values['notes'], (int) $values['active']],
        );

        return (int) ($this->connection)()->lastInsertId();
    }

    /**
     * 플랫폼은 바꾸지 않는다.
     *
     * @param array{system_id: int, name: string, bundle_id: ?string, store_url: ?string, account_id: ?int, version: ?string, released_at: ?string, notes: ?string, active: bool} $values
     */
    public function updateApp(int $id, array $values): void
    {
        // 스토어 주소나 번들 ID 가 바뀌면 다음 cron 에서 아이콘·스토어 정보를 다시 가져온다
        $this->execute(
            'UPDATE apps SET icon_checked_at = NULL, checked_at = NULL WHERE id = ? AND (COALESCE(store_url, \'\') <> ? OR COALESCE(bundle_id, \'\') <> ?)',
            [$id, $values['store_url'] ?? '', $values['bundle_id'] ?? ''],
        );
        $this->execute(
            "UPDATE apps SET system_id = ?, name = ?, bundle_id = ?, store_url = ?, account_id = ?, version = ?, released_at = ?, notes = ?, is_active = ?,
                updated_at = datetime('now', '+9 hours') WHERE id = ?",
            [$values['system_id'], $values['name'], $values['bundle_id'], $values['store_url'], $values['account_id'],
                $values['version'], $values['released_at'], $values['notes'], (int) $values['active'], $id],
        );
    }

    /**
     * 개발자 계정. 관리회사와 연결된 앱 이름(사용 중인 것)을 함께 준다.
     *
     * @return list<array<string, mixed>>
     */
    public function accounts(?string $platform = null): array
    {
        return $this->query(
            "SELECT a.id, a.platform, a.name, a.team_id, a.login_email, a.company_id, a.membership_expires_at, a.notes, a.is_active,
                    c.name AS company_name,
                    (SELECT group_concat(label, ', ') FROM (
                        SELECT s.name || ' ' || p.name AS label FROM apps p JOIN systems s ON s.id = p.system_id
                        WHERE p.account_id = a.id AND p.is_active = 1 ORDER BY s.id, p.name)) AS apps
             FROM app_store_accounts a
             LEFT JOIN companies c ON c.id = a.company_id" . ($platform === null ? '' : ' WHERE a.platform = ?') . '
             ORDER BY a.is_active DESC, a.platform, a.name, a.id',
            $platform === null ? [] : [$platform],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function account(int $id): ?array
    {
        return $this->query(
            'SELECT id, platform, name, team_id, login_email, company_id, membership_expires_at, notes, is_active FROM app_store_accounts WHERE id = ?',
            [$id],
        )[0] ?? null;
    }

    /**
     * @return list<string> 이 계정을 쓰는 사용 중인 앱 이름
     */
    public function activeAppsOfAccount(int $accountId): array
    {
        return array_column($this->query(
            "SELECT s.name || ' ' || p.name AS label FROM apps p JOIN systems s ON s.id = p.system_id WHERE p.account_id = ? AND p.is_active = 1 ORDER BY p.id",
            [$accountId],
        ), 'label');
    }

    /**
     * @param array{platform: string, name: string, team_id: ?string, login_email: ?string, company_id: ?int, membership_expires_at: ?string, notes: ?string, active: bool} $values
     */
    public function createAccount(array $values): int
    {
        $this->execute(
            'INSERT INTO app_store_accounts (platform, name, team_id, login_email, company_id, membership_expires_at, notes, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$values['platform'], $values['name'], $values['team_id'], $values['login_email'], $values['company_id'],
                $values['membership_expires_at'], $values['notes'], (int) $values['active']],
        );

        return (int) ($this->connection)()->lastInsertId();
    }

    /**
     * 플랫폼은 바꾸지 않는다.
     *
     * @param array{name: string, team_id: ?string, login_email: ?string, company_id: ?int, membership_expires_at: ?string, notes: ?string, active: bool} $values
     */
    public function updateAccount(int $id, array $values): void
    {
        $this->execute(
            "UPDATE app_store_accounts SET name = ?, team_id = ?, login_email = ?, company_id = ?, membership_expires_at = ?, notes = ?, is_active = ?,
                updated_at = datetime('now', '+9 hours') WHERE id = ?",
            [$values['name'], $values['team_id'], $values['login_email'], $values['company_id'],
                $values['membership_expires_at'], $values['notes'], (int) $values['active'], $id],
        );
    }

    /**
     * 같은 앱의 플랫폼별 행: 같은 서비스에서 이름이 같은(대소문자 무시) 앱. 자기 자신 포함, iOS → Android → 웹앱 순.
     *
     * @param array<string, mixed> $app
     * @return list<array<string, mixed>>
     */
    public function siblings(array $app): array
    {
        return $this->query(
            self::APP_SELECT . " WHERE p.system_id = ? AND LOWER(TRIM(p.name)) = LOWER(TRIM(?))
             ORDER BY CASE p.platform WHEN 'ios' THEN 0 WHEN 'android' THEN 1 ELSE 2 END, p.is_active DESC, p.id",
            [$app['system_id'], $app['name']],
        );
    }

    /**
     * 일별 통계 (app_store_daily_stats). 날짜·플랫폼별로 합친다.
     * 같은 앱·날짜를 여러 출처가 주면 큰 값 하나만 쓴다 (출처별로 따로 쌓이므로 더하면 중복).
     *
     * @param list<int> $appIds
     * @return list<array{date: string, platform: string, downloads: ?int, active_users: ?int}>
     */
    public function dailyStats(array $appIds, string $from, string $to): array
    {
        if ($appIds === []) {
            return [];
        }

        return array_map(static fn (array $r): array => [
            'date' => (string) $r['stat_date'],
            'platform' => (string) $r['platform'],
            'downloads' => $r['downloads'] === null ? null : (int) $r['downloads'],
            'active_users' => $r['active_users'] === null ? null : (int) $r['active_users'],
        ], $this->query(
            'SELECT d.stat_date, p.platform, SUM(d.downloads) AS downloads, SUM(d.active_users) AS active_users
             FROM (' . self::perDay($appIds) . ') d JOIN apps p ON p.id = d.app_id
             WHERE d.stat_date BETWEEN ? AND ?
             GROUP BY d.stat_date, p.platform ORDER BY d.stat_date',
            [...$appIds, $from, $to],
        ));
    }

    /**
     * 다운로드 합계: 전체 누적, 최근 기간, 그 앞 같은 길이 기간 (증감 비교). 기록이 없으면 null.
     * 스토어 리포트는 1~2일 늦게 나오므로 기간은 오늘이 아니라 마지막으로 집계된 날짜에서 끝난다.
     *
     * @param list<int> $appIds
     * @return array{total: ?int, recent: ?int, previous: ?int, last_date: ?string}
     */
    public function downloadTotals(array $appIds, int $days): array
    {
        $empty = ['total' => null, 'recent' => null, 'previous' => null, 'last_date' => null];
        if ($appIds === []) {
            return $empty;
        }
        $today = $this->query(
            'SELECT MAX(stat_date) AS d FROM app_store_daily_stats WHERE downloads IS NOT NULL AND app_id IN (' . self::placeholders($appIds) . ')',
            $appIds,
        )[0]['d'];
        if ($today === null) {
            return $empty;
        }
        $recentFrom = date('Y-m-d', strtotime("{$today} -" . ($days - 1) . ' days'));
        $previousFrom = date('Y-m-d', strtotime("{$recentFrom} -{$days} days"));
        $row = $this->query(
            'SELECT SUM(downloads) AS total,
                    SUM(CASE WHEN stat_date >= ? AND stat_date <= ? THEN downloads END) AS recent,
                    SUM(CASE WHEN stat_date >= ? AND stat_date < ? THEN downloads END) AS previous,
                    MAX(CASE WHEN downloads IS NOT NULL THEN stat_date END) AS last_date
             FROM (' . self::perDay($appIds) . ')',
            [$recentFrom, $today, $previousFrom, $recentFrom, ...$appIds],
        )[0];
        if ($row['last_date'] === null) {
            return $empty;
        }

        return [
            'total' => (int) $row['total'],
            'recent' => $row['recent'] === null ? null : (int) $row['recent'],
            'previous' => $row['previous'] === null ? null : (int) $row['previous'],
            'last_date' => (string) $row['last_date'],
        ];
    }

    /**
     * 수집한 리뷰 요약 (app_store_reviews): 개수, 평균, 별점별 개수, 답변 안 한 리뷰 수(답변을 주는 출처만).
     *
     * @param list<int> $appIds
     * @return array{count: int, average: ?float, distribution: array<int, int>, unanswered: int}
     */
    public function reviewSummary(array $appIds): array
    {
        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $summary = ['count' => 0, 'average' => null, 'distribution' => $distribution, 'unanswered' => 0];
        if ($appIds === []) {
            return $summary;
        }
        foreach ($this->query(
            'SELECT rating, COUNT(*) AS n,
                    SUM(developer_reply IS NULL AND source IN (' . self::placeholders(self::REPLY_SOURCES) . ')) AS unanswered
             FROM app_store_reviews
             WHERE app_id IN (' . self::placeholders($appIds) . ') GROUP BY rating',
            [...self::REPLY_SOURCES, ...$appIds],
        ) as $r) {
            $summary['distribution'][(int) $r['rating']] = (int) $r['n'];
            $summary['count'] += (int) $r['n'];
            $summary['unanswered'] += (int) $r['unanswered'];
        }
        if ($summary['count'] > 0) {
            $sum = 0;
            foreach ($summary['distribution'] as $rating => $n) {
                $sum += $rating * $n;
            }
            $summary['average'] = round($sum / $summary['count'], 2);
        }

        return $summary;
    }

    /**
     * 최근 리뷰
     *
     * @param list<int> $appIds
     * @return list<array<string, mixed>>
     */
    public function recentReviews(array $appIds, int $limit = 5): array
    {
        if ($appIds === []) {
            return [];
        }

        return $this->query(
            'SELECT r.id, r.app_id, p.platform, r.rating, r.title, r.content, r.reviewer_name, r.app_version, r.review_created_at,
                    r.developer_reply, r.developer_reply_at, r.source
             FROM app_store_reviews r JOIN apps p ON p.id = r.app_id
             WHERE r.app_id IN (' . self::placeholders($appIds) . ')
             ORDER BY r.review_created_at DESC, r.id DESC LIMIT ' . max(1, $limit),
            $appIds,
        );
    }

    /**
     * 앱별 마지막 수집 결과 (app_store_sync_logs)
     *
     * @param list<int> $appIds
     * @return array<int, array{sync_status: string, records_count: int, started_at: string, finished_at: ?string, error_message: ?string, last_success_at: ?string}> app_id => 결과
     */
    public function lastSyncs(array $appIds, string $syncType): array
    {
        if ($appIds === []) {
            return [];
        }
        $rows = $this->query(
            'SELECT l.app_id, l.sync_status, l.records_count, l.started_at, l.finished_at, l.error_message,
                    (SELECT MAX(started_at) FROM app_store_sync_logs s
                     WHERE s.app_id = l.app_id AND s.sync_type = l.sync_type AND s.sync_status = \'success\') AS last_success_at
             FROM app_store_sync_logs l
             WHERE l.sync_type = ? AND l.app_id IN (' . self::placeholders($appIds) . ')
               AND l.id = (SELECT id FROM app_store_sync_logs WHERE app_id = l.app_id AND sync_type = l.sync_type ORDER BY started_at DESC, id DESC LIMIT 1)',
            [$syncType, ...$appIds],
        );
        $result = [];
        foreach ($rows as $r) {
            $result[(int) $r['app_id']] = [
                'sync_status' => (string) $r['sync_status'],
                'records_count' => (int) $r['records_count'],
                'started_at' => (string) $r['started_at'],
                'finished_at' => $r['finished_at'],
                'error_message' => $r['error_message'],
                'last_success_at' => $r['last_success_at'],
            ];
        }

        return $result;
    }

    /**
     * 앱·날짜마다 한 행 (출처가 여럿이면 큰 값). 자리표시자는 $appIds 순서대로 채운다.
     *
     * @param list<int> $appIds
     */
    private static function perDay(array $appIds): string
    {
        return 'SELECT app_id, stat_date, MAX(downloads) AS downloads, MAX(active_users) AS active_users
                FROM app_store_daily_stats WHERE app_id IN (' . self::placeholders($appIds) . ')
                GROUP BY app_id, stat_date';
    }

    /**
     * @param list<mixed> $values
     */
    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
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
