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

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures)."\n");
    exit(1);
}

fwrite(STDOUT, "Docker Compose service isolation verified.\n");
