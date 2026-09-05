<?php

declare(strict_types=1);

$process = proc_open(
    ['docker', 'compose', 'config', '--format', 'json'],
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    dirname(__DIR__),
);

if (! is_resource($process)) {
    fwrite(STDERR, "Unable to start Docker Compose.\n");
    exit(1);
}

$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

if (proc_close($process) !== 0) {
    fwrite(STDERR, $error);
    exit(1);
}

try {
    $compose = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, "Docker Compose returned invalid JSON: {$exception->getMessage()}\n");
    exit(1);
}

$failures = [];
$services = $compose['services'] ?? [];
$postgres = $services['postgres'] ?? [];
$mailpit = $services['mailpit'] ?? [];

foreach ($postgres['ports'] ?? [] as $port) {
    $hostIp = is_array($port) ? ($port['host_ip'] ?? null) : null;

    if (! in_array($hostIp, ['127.0.0.1', '::1'], true)) {
        $failures[] = 'PostgreSQL port publications must use a loopback host address.';
    }
}

foreach (['app', 'queue', 'scheduler'] as $serviceName) {
    $service = $services[$serviceName] ?? [];
    $environment = $service['environment'] ?? [];
    $networks = $service['networks'] ?? [];

    if (($environment['DB_HOST'] ?? null) !== 'postgres') {
        $failures[] = "{$serviceName} must connect to the postgres service hostname.";
    }

    if (($environment['DB_PORT'] ?? null) !== '5432') {
        $failures[] = "{$serviceName} must connect to PostgreSQL on port 5432.";
    }

    if (! array_key_exists('queuefix', $networks)) {
        $failures[] = "{$serviceName} must remain attached to the queuefix network.";
    }
}

if (! array_key_exists('queuefix', $postgres['networks'] ?? [])) {
    $failures[] = 'PostgreSQL must remain attached to the queuefix network.';
}

$mailpitUiPublished = false;

foreach ($mailpit['ports'] ?? [] as $port) {
    $hostIp = is_array($port) ? ($port['host_ip'] ?? null) : null;
    $target = is_array($port) ? (string) ($port['target'] ?? '') : '';
    $published = is_array($port) ? (string) ($port['published'] ?? '') : '';

    if (! in_array($hostIp, ['127.0.0.1', '::1'], true)) {
        $failures[] = 'Mailpit port publications must use a loopback host address.';
    }

    if ($target !== '8025' || $published !== '8025') {
        $failures[] = 'Mailpit must publish only its web UI on host port 8025.';
    } else {
        $mailpitUiPublished = true;
    }
}

if (! $mailpitUiPublished) {
    $failures[] = 'Mailpit must publish its web UI on loopback port 8025.';
}

if (! array_key_exists('queuefix', $mailpit['networks'] ?? [])) {
    $failures[] = 'Mailpit must remain attached to the queuefix network.';
}

$appEnvironment = $services['app']['environment'] ?? [];
$expectedMailer = getenv('COMPOSE_EXPECTED_MAILER');

if ($expectedMailer !== false
    && ($appEnvironment['MAIL_MAILER'] ?? null) !== $expectedMailer) {
    $failures[] = "The app must honor MAIL_MAILER={$expectedMailer}.";
}

if (($appEnvironment['MAIL_HOST'] ?? null) !== 'mailpit'
    || ($appEnvironment['MAIL_PORT'] ?? null) !== '1025') {
    $failures[] = 'The app must reach Mailpit internally on mailpit:1025.';
}

if (! array_key_exists('queuefix', $services['app']['networks'] ?? [])) {
    $failures[] = 'The app must remain attached to the queuefix network.';
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures)."\n");
    exit(1);
}

fwrite(STDOUT, "Docker Compose service isolation verified.\n");
