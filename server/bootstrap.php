<?php

declare(strict_types=1);

use ChatApp\Core\Env;

require_once __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Shanghai');

$envPath = dirname(__DIR__) . '/.env';
if (!is_file($envPath)) {
    $example = dirname(__DIR__) . '/.env.example';
    if (is_file($example)) {
        copy($example, $envPath);
    }
}

Env::load($envPath);

date_default_timezone_set((string) Env::get('TIMEZONE', 'Asia/Shanghai'));
