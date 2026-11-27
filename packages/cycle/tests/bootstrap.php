<?php

declare(strict_types=1);

$packageAutoload = dirname(__DIR__) . '/vendor/autoload.php';
$loader = require is_file($packageAutoload) ? $packageAutoload : dirname(__DIR__, 3) . '/vendor/autoload.php';
$loader->addPsr4('Componenta\\Queue\\Cycle\\Tests\\', __DIR__);
$loader->addPsr4('Componenta\\Queue\\Cycle\\', dirname(__DIR__) . '/src');
$corePath = \Composer\InstalledVersions::isInstalled('componenta/queue')
    ? \Composer\InstalledVersions::getInstallPath('componenta/queue')
    : dirname(__DIR__, 3);
if ($corePath !== null) {
    $loader->addPsr4('Componenta\\Queue\\Tests\\Support\\', $corePath . '/tests/Support');
}

if (getenv('QUEUE_TEST_DSN') === false) {
    throw new RuntimeException('Set QUEUE_TEST_DSN to an isolated PostgreSQL test database; no database is selected implicitly.');
}
