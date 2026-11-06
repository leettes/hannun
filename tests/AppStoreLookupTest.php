<?php

declare(strict_types=1);

namespace Tests;

use App\Monitoring\AppStoreLookup;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestClient;

final class AppStoreLookupTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $responses = [];

    /** @var list<string> */
    private array $requests = [];

    /**
     * @return array<string, mixed>
     */
    private static function storeResult(string $version, string $bundleId = 'kr.found.app'): array
    {
        return ['resultCount' => 1, 'results' => [[
            'kind' => 'software', 'trackId' => 123, 'trackName' => '찾은 앱', 'bundleId' => $bundleId, 'version' => $version,
            'currentVersionReleaseDate' => '2026-11-04T03:58:37Z', 'sellerName' => 'Example Corp',
            'trackViewUrl' => 'https://apps.apple.com/kr/app/found/id123?uo=4', 'description' => '긴 설명은 저장하지 않는다',
        ]]];
    }

    public function testQueryAndParse(): void
    {
        $this->assertSame('id=123', AppStoreLookup::query('https://apps.apple.com/kr/app/x/id123?l=en', 'kr.a.app'), '주소의 id 우선');
        $this->assertSame('bundleId=kr.a.app', AppStoreLookup::query(null, 'kr.a.app'));
        $this->assertNull(AppStoreLookup::query('https://example.com', null));

        $parsed = AppStoreLookup::parse(self::storeResult('1.2.17'));
        $this->assertSame('1.2.17', $parsed['version']);
        $this->assertSame(date('Y-m-d', strtotime('2026-11-04T03:58:37Z')), $parsed['released_at']);
        $this->assertSame('https://apps.apple.com/kr/app/found/id123', $parsed['store_url'], '추적 파라미터 제거');
        $this->assertArrayNotHasKey('description', $parsed['raw']);
        $this->assertNull(AppStoreLookup::parse(['resultCount' => 0, 'results' => []]));
    }

    public function testUpdatesVersionFillsBundleIdAndKeepsValuesOnFailure(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $pdo = $client->pdo();
        // 번들 ID 없이 App Store 주소만으로 등록할 수 있다
        $this->assertSame(302, $client->post('/settings/apps', ['platform' => 'ios', 'system_id' => '1', 'name' => '앱', 'store_url' => 'https://apps.apple.com/kr/app/id123', 'version' => '9.9', 'released_at' => '2000-01-01'])->status);
        $this->assertSame(422, $client->post('/settings/apps', ['platform' => 'ios', 'system_id' => '1', 'name' => '주소없음'])->status, '주소도 번들 ID 도 없으면 거부');
        $client->post('/settings/apps', ['platform' => 'android', 'system_id' => '1', 'name' => '안드', 'bundle_id' => 'kr.a.app', 'version' => '1.0']);
        $id = (int) $pdo->query("SELECT id FROM apps WHERE platform = 'ios'")->fetchColumn();
        $this->assertNull($pdo->query("SELECT version FROM apps WHERE id = {$id}")->fetchColumn() ?: null, 'iOS 는 버전을 직접 받지 않는다');

        $this->responses['https://itunes.apple.com/lookup?id=123&country=kr'] = json_encode(self::storeResult('1.2.17'));
        $result = $this->lookup($client)->run();

        $this->assertSame(['앱(iOS) 1.2.17'], $result['updated']);
        $this->assertSame(['https://itunes.apple.com/lookup?id=123&country=kr'], $this->requests, 'Android 는 조회하지 않는다');
        $row = $pdo->query("SELECT version, released_at, bundle_id, store_info, store_error FROM apps WHERE id = {$id}")->fetch();
        $this->assertSame(['1.2.17', date('Y-m-d', strtotime('2026-11-04T03:58:37Z')), 'kr.found.app', null], [$row['version'], $row['released_at'], $row['bundle_id'], $row['store_error']]);
        $this->assertSame('Example Corp', json_decode($row['store_info'], true)['sellerName']);
        $this->assertStringContainsString('1.2.17', $client->get('/settings/apps')->body);
        $this->assertStringContainsString('App Store 자동 조회', $client->get("/settings/apps/{$id}")->body);

        // 수정 화면에서 버전을 보내도 자동 값을 지킨다
        $client->post("/settings/apps/{$id}", ['system_id' => '1', 'name' => '앱', 'bundle_id' => 'kr.found.app', 'store_url' => 'https://apps.apple.com/kr/app/id123', 'version' => '0.0.1', 'active' => '1']);
        $this->assertSame('1.2.17', $pdo->query("SELECT version FROM apps WHERE id = {$id}")->fetchColumn());

        // 하루 안에는 다시 조회하지 않는다
        $this->requests = [];
        $this->assertSame(['updated' => [], 'errors' => []], $this->lookup($client)->run());
        $this->assertSame([], $this->requests);

        // 실패: 이전 값을 두고 오류만. 화면에 경고
        $pdo->exec("UPDATE apps SET checked_at = '2000-01-01 00:00:00' WHERE id = {$id}");
        $this->responses['https://itunes.apple.com/lookup?id=123&country=kr'] = json_encode(['resultCount' => 0, 'results' => []]);
        $this->assertStringContainsString('찾을 수 없음', $this->lookup($client)->run()['errors'][0]);
        $row = $pdo->query("SELECT version, store_error FROM apps WHERE id = {$id}")->fetch();
        $this->assertSame('1.2.17', $row['version']);
        $this->assertNotNull($row['store_error']);
        $this->assertStringContainsString('자동 조회 실패', $client->get('/settings/apps')->body);
    }

    public function testDoesNotFillBundleIdUsedByAnotherApp(): void
    {
        $client = new TestClient();
        $client->loginAs('admin');
        $pdo = $client->pdo();
        $client->post('/settings/apps', ['platform' => 'ios', 'system_id' => '1', 'name' => '먼저', 'bundle_id' => 'kr.found.app']);
        $client->post('/settings/apps', ['platform' => 'ios', 'system_id' => '1', 'name' => '나중', 'store_url' => 'https://apps.apple.com/kr/app/id123']);
        $this->responses['https://itunes.apple.com/lookup?bundleId=kr.found.app&country=kr'] = json_encode(self::storeResult('1.0'));
        $this->responses['https://itunes.apple.com/lookup?id=123&country=kr'] = json_encode(self::storeResult('1.0'));

        $this->lookup($client)->run();

        $this->assertNull($pdo->query("SELECT bundle_id FROM apps WHERE name = '나중'")->fetchColumn() ?: null);
        $this->assertSame('1.0', $pdo->query("SELECT version FROM apps WHERE name = '나중'")->fetchColumn(), '버전은 갱신');
    }

    private function lookup(TestClient $client): AppStoreLookup
    {
        return new AppStoreLookup($client->pdo(...), function (string $url): ?string {
            $this->requests[] = $url;

            return $this->responses[$url] ?? null;
        });
    }
}
