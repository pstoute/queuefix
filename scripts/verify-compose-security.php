<?php

declare(strict_types=1);

function ipv4CidrContains(string $cidr, string $address): bool
{
    [$network, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);

    if ($prefix === null
        || ! preg_match('/^\d+$/', $prefix)
        || (int) $prefix < 1
        || (int) $prefix > 32
        || filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }

    $networkAddress = ip2long($network);
    $candidateAddress = ip2long($address);
    $mask = (0xFFFFFFFF << (32 - (int) $prefix)) & 0xFFFFFFFF;

    return ($networkAddress & $mask) === ($candidateAddress & $mask);
}

$composeCommand = ['docker', 'compose'];
$composeEnvFile = getenv('COMPOSE_ENV_FILE');

if ($composeEnvFile !== false && $composeEnvFile !== '') {
    $composeCommand[] = '--env-file';
    $composeCommand[] = $composeEnvFile;
}

$composeCommand = [...$composeCommand, 'config', '--format', 'json'];

$process = proc_open(
    $composeCommand,
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
$app = $services['app'] ?? [];
$postgres = $services['postgres'] ?? [];
$redis = $services['redis'] ?? [];
$mailpit = $services['mailpit'] ?? [];
$appEnvironment = $app['environment'] ?? [];
$queueEnvironment = $services['queue']['environment'] ?? [];
$queuefixNetwork = $compose['networks']['queuefix'] ?? [];
$ipamConfig = $queuefixNetwork['ipam']['config'][0] ?? [];
$networkSubnet = (string) ($ipamConfig['subnet'] ?? '');
$networkGateway = (string) ($ipamConfig['gateway'] ?? '');

if (! ipv4CidrContains($networkSubnet, $networkGateway)) {
    $failures[] = 'The queuefix network must define a valid IPv4 subnet and gateway on that subnet.';
}

if (($appEnvironment['TRUSTED_PROXY_REQUIRED'] ?? null) !== 'true') {
    $failures[] = 'The app must require a trusted reverse proxy in Docker Compose.';
}

$trustedProxies = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) ($appEnvironment['TRUSTED_PROXIES'] ?? '')),
)));

if ($networkGateway === '' || $trustedProxies !== [$networkGateway]) {
    $failures[] = 'The app must trust only the exact queuefix network gateway as its reverse proxy peer.';
}

foreach ($postgres['ports'] ?? [] as $port) {
    $hostIp = is_array($port) ? ($port['host_ip'] ?? null) : null;

    if (! in_array($hostIp, ['127.0.0.1', '::1'], true)) {
        $failures[] = 'PostgreSQL port publications must use a loopback host address.';
    }
}

$expectedAppPorts = ['8000' => false, '5173' => false];

foreach ($app['ports'] ?? [] as $port) {
    $hostIp = is_array($port) ? ($port['host_ip'] ?? null) : null;
    $target = is_array($port) ? (string) ($port['target'] ?? '') : '';
    $published = is_array($port) ? (string) ($port['published'] ?? '') : '';

    if (! in_array($hostIp, ['127.0.0.1', '::1'], true)) {
        $failures[] = 'Application port publications must use a loopback host address.';
    }

    if (array_key_exists($target, $expectedAppPorts) && $published === $target) {
        $expectedAppPorts[$target] = true;
    }
}

foreach ($expectedAppPorts as $port => $isPublished) {
    if (! $isPublished) {
        $failures[] = "The app must publish host port {$port} to container port {$port} on loopback.";
    }
}

foreach (['app', 'migrate', 'queue', 'scheduler'] as $serviceName) {
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

if (($redis['ports'] ?? []) !== []) {
    $failures[] = 'Redis must not publish any port to the Docker host.';
}

if (! array_key_exists('queuefix', $redis['networks'] ?? [])) {
    $failures[] = 'Redis must remain attached to the private queuefix network.';
}

foreach (['app', 'queue', 'scheduler'] as $serviceName) {
    $service = $services[$serviceName] ?? [];

    if (($service['environment']['REDIS_HOST'] ?? null) !== 'redis') {
        $failures[] = "{$serviceName} must connect to the private redis service hostname.";
    }

    if (($service['depends_on']['redis']['condition'] ?? null) !== 'service_healthy') {
        $failures[] = "{$serviceName} must wait for the private Redis service to become healthy.";
    }
}

foreach (['app', 'queue', 'scheduler'] as $serviceName) {
    if (($services[$serviceName]['depends_on']['migrate']['condition'] ?? null) !== 'service_completed_successfully') {
        $failures[] = "{$serviceName} must wait for successful database migrations.";
    }
}

$migrationCommand = $services['migrate']['command'] ?? [];

if (! in_array('migrate', $migrationCommand, true)
    || ! in_array('--force', $migrationCommand, true)) {
    $failures[] = 'The migrate service must run non-interactive database migrations.';
}

if (in_array('--seed', $migrationCommand, true)) {
    $failures[] = 'The automatic migrate service must not rerun the one-time demo seeder.';
}

foreach (['queue', 'scheduler'] as $serviceName) {
    if (($services[$serviceName]['restart'] ?? null) !== 'unless-stopped') {
        $failures[] = "{$serviceName} must restart unless explicitly stopped.";
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

$expectedMailer = getenv('COMPOSE_EXPECTED_MAILER');

foreach (['app' => $appEnvironment, 'queue' => $queueEnvironment] as $serviceName => $environment) {
    if ($expectedMailer !== false
        && ($environment['MAIL_MAILER'] ?? null) !== $expectedMailer) {
        $failures[] = "The {$serviceName} service must honor MAIL_MAILER={$expectedMailer}.";
    }

    if (($environment['MAIL_HOST'] ?? null) !== 'mailpit'
        || ($environment['MAIL_PORT'] ?? null) !== '1025') {
        $failures[] = "The {$serviceName} service must reach Mailpit internally on mailpit:1025.";
    }
}

if (! array_key_exists('queuefix', $app['networks'] ?? [])) {
    $failures[] = 'The app must remain attached to the queuefix network.';
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures)."\n");
    exit(1);
}

fwrite(STDOUT, "Docker Compose service isolation verified.\n");
