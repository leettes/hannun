<?php

declare(strict_types=1);

// 관리자 계정 생성
// php bin/create-user.php --email=admin@example.com --name="홍길동" [--role=admin|operator|viewer]
// 비밀번호는 실행 중에 입력받는다 (화면에 표시되지 않음).

use App\Auth\DatabaseUserProvider;
use App\Auth\User;
use App\Database;

require dirname(__DIR__) . '/config/env.php';

$options = getopt('', ['email:', 'name:', 'role::']);
$email = strtolower(trim((string) ($options['email'] ?? '')));
$name = trim((string) ($options['name'] ?? ''));
$role = (string) ($options['role'] ?? 'admin');

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
    $fail('사용법: php bin/create-user.php --email=admin@example.com --name="이름" [--role=admin|operator|viewer]');
}
if (!isset(User::ROLES[$role])) {
    $fail('role 은 ' . implode(', ', array_keys(User::ROLES)) . ' 중 하나여야 합니다.');
}

$prompt = static function (string $label): string {
    fwrite(STDOUT, $label);
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }

    return $value;
};

$password = $prompt('비밀번호: ');
if (mb_strlen($password) < User::MIN_PASSWORD_LENGTH) {
    $fail('비밀번호는 ' . User::MIN_PASSWORD_LENGTH . '자 이상이어야 합니다.');
}
if ($prompt('비밀번호 확인: ') !== $password) {
    $fail('비밀번호가 일치하지 않습니다.');
}

$users = new DatabaseUserProvider(Database::connection(...));
if ($users->findByEmail($email) !== null) {
    $fail("이미 존재하는 이메일입니다: {$email}");
}

$id = $users->create($email, $name, password_hash($password, PASSWORD_DEFAULT), $role);
echo "계정을 만들었습니다. #{$id} {$email} ({$role})" . PHP_EOL;
