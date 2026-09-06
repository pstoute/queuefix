<?php

test('the generated https deployment requires secure cookies and hsts', function () {
    $setupScript = file_get_contents(base_path('deploy/setup-server.sh'));

    expect($setupScript)
        ->not->toBeFalse()
        ->toContain("\nSESSION_SECURE_COOKIE=true\n")
        ->toContain('Strict-Transport-Security "max-age=31536000"')
        ->not->toContain('includeSubDomains')
        ->not->toContain('preload');
});

test('database configuration reads a managed credential file without an environment password', function () {
    $passwordFile = tempnam(sys_get_temp_dir(), 'queuefix-database-password-');
    expect($passwordFile)->not->toBeFalse();

    $managedPassword = str_repeat('a1', 32);
    file_put_contents($passwordFile, $managedPassword);

    $previousEnvironmentFile = $_ENV['DB_PASSWORD_FILE'] ?? null;
    $previousServerFile = $_SERVER['DB_PASSWORD_FILE'] ?? null;
    $hadEnvironmentFile = array_key_exists('DB_PASSWORD_FILE', $_ENV);
    $hadServerFile = array_key_exists('DB_PASSWORD_FILE', $_SERVER);

    $_ENV['DB_PASSWORD_FILE'] = $passwordFile;
    $_SERVER['DB_PASSWORD_FILE'] = $passwordFile;

    try {
        /** @var array<string, mixed> $database */
        $database = require base_path('config/database.php');

        expect(data_get($database, 'connections.pgsql.password'))->toBe($managedPassword)
            ->and(data_get($database, 'connections.mysql.password'))->toBe($managedPassword);
    } finally {
        if ($hadEnvironmentFile) {
            $_ENV['DB_PASSWORD_FILE'] = $previousEnvironmentFile;
        } else {
            unset($_ENV['DB_PASSWORD_FILE']);
        }

        if ($hadServerFile) {
            $_SERVER['DB_PASSWORD_FILE'] = $previousServerFile;
        } else {
            unset($_SERVER['DB_PASSWORD_FILE']);
        }

        unlink($passwordFile);
    }
});
