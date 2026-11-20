<?php

declare(strict_types=1);

namespace App\Auth;

interface UserProvider
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    /**
     * 로그인 성공 기록. $newPasswordHash 가 있으면 해시를 갱신한다(알고리즘/비용 변경 시).
     */
    public function recordLogin(int $id, ?string $newPasswordHash = null): void;

    /**
     * @return list<User> 활성 계정 먼저, 그다음 이름 순
     */
    public function all(): array;

    public function create(string $email, string $name, string $passwordHash, string $role): int;

    /**
     * $passwordHash 가 null 이면 비밀번호는 바꾸지 않는다.
     */
    public function update(int $id, string $name, string $role, bool $active, ?string $passwordHash = null): void;

    /**
     * 장애 문자 알림 연락처. $phone 은 숫자만 (User::normalizePhone), $smsScope 는 User::SMS_SCOPES 키.
     */
    public function updateContact(int $id, ?string $phone, string $smsScope): void;

    public function updateMeetingAccess(int $id, string $access): void;

    /**
     * @return array{secret: ?string, last_step: ?int} secret 은 암호화된 값
     */
    public function totpState(int $id): array;

    public function enableTotp(int $id, string $encryptedSecret, int $step): void;

    /**
     * 마지막 통과 단계를 올린다. 같은 단계를 동시에 두 번 쓰는 경우 한쪽만 true
     */
    public function advanceTotpStep(int $id, int $step): bool;

    public function resetTotp(int $id): void;
}
