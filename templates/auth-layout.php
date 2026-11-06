<?php /** @var string $title @var string $content */ ?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · 한눈</title>
    <link rel="icon" href="<?= asset('logo.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= asset('app.css') ?>">
    <script src="<?= asset('theme.js') ?>"></script>
</head>
<body class="auth">
<?= $content ?>
<footer class="site-footer">© <?= date('Y') ?> leetes. All rights reserved.</footer>
</body>
</html>
