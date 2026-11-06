<?php

declare(strict_types=1);

namespace App\Auth;

use DateTimeImmutable;

final class User
{
    public const ROLES = [
        'viewer' => '조회',
        'operator' => '운영자',
        'admin' => '관리자',
    ];

    /** 장애 문자 받는 범위 */
    public const SMS_SCOPES = [
        'none' => '안함',
        'all' => '전체 서비스',
        'owned' => '담당 서비스',
    ];

    public const MIN_PASSWORD_LENGTH = 6;

    private const RANK = ['viewer' => 0, 'operator' => 1, 'admin' => 2];

    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
        public readonly string $role,
        public readonly bool $active,
        public readonly string $passwordHash,
        public readonly ?DateTimeImmutable $lastLoginAt = null,
        public readonly ?string $phone = null,
        public readonly string $smsScope = 'owned',
    ) {
    }

    /**
     * @param array<string, mixed> $row users 테이블 행
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['name'],
            (string) $row['role'],
            (bool) $row['is_active'],
            (string) $row['password_hash'],
            isset($row['last_login_at']) ? new DateTimeImmutable($row['last_login_at']) : null,
            $row['phone'] ?? null,
            (string) ($row['sms_scope'] ?? 'owned'),
        );
    }

    /**
     * 역할 등급: viewer < operator < admin
     */
    public function can(string $role): bool
    {
        return (self::RANK[$this->role] ?? -1) >= (self::RANK[$role] ?? PHP_INT_MAX);
    }

    /**
     * 휴대폰 번호를 숫자만 남겨 돌려준다. 국내 휴대폰 번호가 아니면 null.
     */
    public static function normalizePhone(string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        return preg_match('/^01[016789]\d{7,8}$/', $digits) === 1 ? $digits : null;
    }

    public function phoneLabel(): string
    {
        return $this->phone === null ? '-' : (string) preg_replace('/^(\d{3})(\d{3,4})(\d{4})$/', '$1-$2-$3', $this->phone);
    }

    /**
     * 역할의 기본 문자 범위: 관리자는 전체, 나머지는 담당 서비스
     */
    public static function defaultSmsScope(string $role): string
    {
        return $role === 'admin' ? 'all' : 'owned';
    }

    /**
     * 역할이 고를 수 있는 문자 범위. 전체 서비스는 운영자 이상만.
     *
     * @return array<string, string>
     */
    public static function smsScopesFor(string $role): array
    {
        $scopes = self::SMS_SCOPES;
        if ($role === 'viewer') {
            unset($scopes['all']);
        }

        return $scopes;
    }

    public function smsScopeLabel(): string
    {
        return self::SMS_SCOPES[$this->smsScope] ?? $this->smsScope;
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }
}
