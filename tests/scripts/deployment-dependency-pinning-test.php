<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const SHA256_DIGEST_PATTERN = '/^[^\s@]+:[^\s\/@]+@sha256:[a-f0-9]{64}$/D';
const DEBIAN_SNAPSHOT = '20260824T000000Z';
const PNPM_VERSION = '10.34.5';
const PNPM_SHA512 = 'a4ee05f2f73658255bd6a89859c065a45c28a57daefae2c893a168ee2b73168c37b91e83e57ea67654ad03f03031746430e8bce38e362e042605fb8abc80192e';
const PHPREDIS_VERSION = '6.3.0';
const PHPREDIS_SHA256 = '0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5';

/** @return list<string> */
function logicalDockerfileInstructions(string $contents): array
{
    $instructions = [];
    $current = '';

    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        $trimmed = trim($line);

        if ($current === '' && ($trimmed === '' || str_starts_with($trimmed, '#'))) {
            continue;
        }

        $continues = str_ends_with($trimmed, '\\');
        $fragment = $continues ? rtrim(substr($trimmed, 0, -1)) : $trimmed;
        $current = trim($current.' '.$fragment);

        if (! $continues) {
            $instructions[] = $current;
            $current = '';
        }
    }

    if ($current !== '') {
        $instructions[] = $current;
    }

    return $instructions;
}

function isImmutableImageReference(string $image): bool
{
    return preg_match(SHA256_DIGEST_PATTERN, $image) === 1;
}

/** @param array<string, true> $stages */
function isInternalDockerSource(string $source, array $stages): bool
{
    return ctype_digit($source) || array_key_exists(strtolower($source), $stages);
}

/** @return list<string> */
function validateDockerfile(string $contents, string $path): array
{
    $failures = [];
    $stages = [];

    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        if (preg_match('/^\s*#\s*syntax\s*=\s*(\S+)\s*$/i', $line, $matches) === 1
            && ! isImmutableImageReference($matches[1])) {
            $failures[] = "{$path} has a mutable Dockerfile syntax frontend: {$matches[1]}";
        }
    }

    foreach (logicalDockerfileInstructions($contents) as $instruction) {
        if (preg_match('/^FROM\s+(?:--platform=\S+\s+)?(\S+)(?:\s+AS\s+([a-zA-Z0-9._-]+))?$/i', $instruction, $matches) === 1) {
            $image = $matches[1];
            $isInternalStage = isInternalDockerSource($image, $stages);

            if (! $isInternalStage && strtolower($image) !== 'scratch' && ! isImmutableImageReference($image)) {
                $failures[] = "{$path} has a mutable external FROM reference: {$image}";
            }

            if (isset($matches[2])) {
                $stages[strtolower($matches[2])] = true;
            }

            continue;
        }

        if (preg_match('/^FROM\s+/i', $instruction) === 1) {
            $failures[] = "{$path} has an unparseable FROM instruction: {$instruction}";

            continue;
        }

        $effectiveInstruction = preg_replace('/^ONBUILD\s+/i', '', $instruction, 1) ?? $instruction;

        if (preg_match('/^COPY\s+.*?--from=(?:"([^"]+)"|\'([^\']+)\'|(\S+))/i', $effectiveInstruction, $matches) === 1) {
            $source = $matches[1] !== '' ? $matches[1] : ($matches[2] !== '' ? $matches[2] : $matches[3]);

            if (! isInternalDockerSource($source, $stages) && ! isImmutableImageReference($source)) {
                $failures[] = "{$path} has a mutable external COPY --from reference: {$source}";
            }
        } elseif (preg_match('/^COPY\s+.*--from/i', $effectiveInstruction) === 1) {
            $failures[] = "{$path} has an unparseable COPY --from instruction: {$effectiveInstruction}";
        }

        if (preg_match('/^RUN\s+/i', $effectiveInstruction) !== 1) {
            continue;
        }

        preg_match_all('/--mount=("[^"]+"|\'[^\']+\'|\S+)/i', $effectiveInstruction, $mounts, PREG_SET_ORDER);

        foreach ($mounts as $mount) {
            $definition = trim($mount[1], "\"'");

            foreach (explode(',', $definition) as $option) {
                [$key, $value] = array_pad(explode('=', $option, 2), 2, null);

                if (strtolower(trim($key)) !== 'from' || $value === null) {
                    continue;
                }

                $source = trim($value);

                if (! isInternalDockerSource($source, $stages) && ! isImmutableImageReference($source)) {
                    $failures[] = "{$path} has a mutable external RUN --mount source: {$source}";
                }
            }
        }
    }

    return $failures;
}

/** @param array<string, mixed> $compose
 * @return list<string>
 */
function validateCompose(array $compose, string $path): array
{
    $failures = [];
    $services = $compose['services'] ?? [];

    if (! is_array($services)) {
        return ["{$path} does not define a services mapping."];
    }

    foreach ($services as $name => $service) {
        if (! is_array($service)) {
            continue;
        }

        if (array_key_exists('image', $service)) {
            $image = $service['image'];

            if (! is_string($image) || ! isImmutableImageReference($image)) {
                $rendered = is_scalar($image) ? (string) $image : gettype($image);
                $failures[] = "{$path} service {$name} has a mutable image reference: {$rendered}";
            }
        }

        $build = $service['build'] ?? null;

        if (! is_array($build)) {
            continue;
        }

        if (array_key_exists('dockerfile_inline', $build)) {
            $inlineDockerfile = $build['dockerfile_inline'];

            if (! is_string($inlineDockerfile)) {
                $failures[] = "{$path} service {$name} has a non-string inline Dockerfile.";
            } else {
                $failures = [
                    ...$failures,
                    ...validateDockerfile($inlineDockerfile, "{$path} service {$name} dockerfile_inline"),
                ];
            }
        }

        $contexts = [];

        if (is_string($build['context'] ?? null)) {
            $contexts[] = $build['context'];
        }

        $additionalContexts = $build['additional_contexts'] ?? [];

        if (! is_array($additionalContexts)) {
            $failures[] = "{$path} service {$name} has an unparseable additional_contexts definition.";
            $additionalContexts = [];
        }

        foreach ($additionalContexts as $contextName => $context) {
            if (is_string($context)) {
                $contexts[] = is_int($contextName) && str_contains($context, '=')
                    ? explode('=', $context, 2)[1]
                    : $context;
            }
        }

        foreach ($contexts as $context) {
            if (! str_starts_with($context, 'docker-image://')) {
                continue;
            }

            $contextImage = substr($context, strlen('docker-image://'));

            if (! isImmutableImageReference($contextImage)) {
                $failures[] = "{$path} service {$name} has a mutable Docker image build context: {$contextImage}";
            }
        }
    }

    return $failures;
}

/** @return list<string> */
function validateRootDockerfilePolicy(string $contents): array
{
    $failures = [];
    $instructions = logicalDockerfileInstructions($contents);

    foreach ([
        'ENV PNPM_VERSION='.PNPM_VERSION => 'the exact pnpm version',
        'ENV PNPM_SHA512='.PNPM_SHA512 => 'the pnpm archive checksum',
        'ENV PHPREDIS_VERSION='.PHPREDIS_VERSION => 'the exact PHP Redis extension version',
        'ENV PHPREDIS_SHA256='.PHPREDIS_SHA256 => 'the PHP Redis archive checksum',
    ] as $requiredInstruction => $description) {
        if (! in_array($requiredInstruction, $instructions, true)) {
            $failures[] = "Dockerfile must retain {$description}.";
        }
    }

    $runChains = [];

    foreach ($instructions as $instruction) {
        if (preg_match('/^RUN\s+(.+)$/i', $instruction, $matches) === 1) {
            $runChains[] = array_map('trim', preg_split('/\s*&&\s*/', $matches[1]) ?: []);
        }
    }

    $snapshotChainFound = false;
    $pnpmChainFound = false;
    $phpRedisChainFound = false;

    foreach ($runChains as $chain) {
        foreach ($chain as $index => $command) {
            if (str_starts_with($command, 'apt-get update') && $index > 0) {
                $snapshotCommand = $chain[$index - 1];
                $snapshotChainFound = str_starts_with($snapshotCommand, 'sed -i ')
                    && str_contains($snapshotCommand, 'URIs: https://snapshot.debian.org/archive/debian/'.DEBIAN_SNAPSHOT)
                    && str_contains($snapshotCommand, 'URIs: https://snapshot.debian.org/archive/debian-security/'.DEBIAN_SNAPSHOT)
                    && str_contains($snapshotCommand, "-e '/^Signed-By:/a Check-Valid-Until: no'")
                    && str_ends_with($snapshotCommand, '/etc/apt/sources.list.d/debian.sources');
            }

            if (str_starts_with($command, 'npm pack ') && isset($chain[$index + 2])) {
                $pnpmChainFound = $command === 'npm pack "pnpm@${PNPM_VERSION}" --ignore-scripts --pack-destination /tmp'
                    && $chain[$index + 1] === 'echo "${PNPM_SHA512}  /tmp/pnpm-${PNPM_VERSION}.tgz" | sha512sum --check --strict'
                    && $chain[$index + 2] === 'npm install --global --ignore-scripts "/tmp/pnpm-${PNPM_VERSION}.tgz"';
            }

            if (str_starts_with($command, 'curl ') && isset($chain[$index + 2])) {
                $phpRedisChainFound = str_contains($command, '--fail')
                    && str_contains($command, 'https://pecl.php.net/get/redis-${PHPREDIS_VERSION}.tgz')
                    && str_contains($command, '--output "/tmp/redis-${PHPREDIS_VERSION}.tgz"')
                    && $chain[$index + 1] === 'echo "${PHPREDIS_SHA256}  /tmp/redis-${PHPREDIS_VERSION}.tgz" | sha256sum --check --strict'
                    && $chain[$index + 2] === 'pecl install "/tmp/redis-${PHPREDIS_VERSION}.tgz"';
            }
        }
    }

    if (! $snapshotChainFound) {
        $failures[] = 'Dockerfile must rewrite signed Debian sources to the reviewed snapshot before package installation.';
    }

    if (! $pnpmChainFound) {
        $failures[] = 'Dockerfile must checksum the exact pnpm archive before installing it in the same fail-closed command chain.';
    }

    if (! $phpRedisChainFound) {
        $failures[] = 'Dockerfile must checksum the exact PHP Redis archive before installing it in the same fail-closed command chain.';
    }

    if (preg_match('/\bpecl\s+install\s+redis(?:\s|&&|;|$)/i', $contents) === 1) {
        $failures[] = 'Dockerfile must not install the mutable redis PECL channel release.';
    }

    return $failures;
}

/** @return list<string> */
function discoverFiles(string $root, callable $matches): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file): bool {
                return ! $file->isDir() || ! in_array($file->getFilename(), ['.git', 'node_modules', 'vendor'], true);
            },
        ),
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $matches($file->getFilename())) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** @return list<string> */
function runMutationSelfTests(string $dockerfileContents): array
{
    $failures = [];
    $dockerMutations = [
        'tag-only FROM' => "FROM node:22.23.2-bookworm-slim\n",
        'mutable external COPY source' => "FROM scratch AS production\nCOPY --from=composer:latest /usr/bin/composer /usr/bin/composer\n",
        'mutable ONBUILD COPY source' => "FROM scratch AS production\nONBUILD COPY --from=composer:latest /usr/bin/composer /usr/bin/composer\n",
        'mutable RUN mount source' => "FROM scratch AS production\nRUN --mount=type=bind,from=alpine:latest,target=/source true\n",
        'mutable syntax frontend' => "# syntax=docker/dockerfile:1.7\nFROM scratch\n",
        'variable external image' => "FROM \${PHP_IMAGE} AS production\n",
    ];

    foreach ($dockerMutations as $description => $mutation) {
        if (validateDockerfile($mutation, 'mutation.Dockerfile') === []) {
            $failures[] = "The validator accepted its {$description} mutation.";
        }
    }

    if (validateCompose(['services' => ['app' => ['image' => 'redis:7-alpine']]], 'mutation-compose.yml') === []) {
        $failures[] = 'The validator accepted its tag-only Compose image mutation.';
    }

    if (validateCompose([
        'services' => ['app' => ['build' => ['dockerfile_inline' => "FROM alpine:latest\n"]]],
    ], 'mutation-compose.yml') === []) {
        $failures[] = 'The validator accepted its mutable inline Dockerfile mutation.';
    }

    if (validateCompose([
        'services' => ['app' => ['build' => ['additional_contexts' => ['tools' => 'docker-image://alpine:latest']]]],
    ], 'mutation-compose.yml') === []) {
        $failures[] = 'The validator accepted its mutable Docker image build-context mutation.';
    }

    $policyMutations = [
        'bare PECL install' => str_replace(
            'pecl install "/tmp/redis-${PHPREDIS_VERSION}.tgz"',
            'pecl install redis',
            $dockerfileContents,
        ),
        'live Debian package source' => str_replace(
            'https://snapshot.debian.org/archive/debian/'.DEBIAN_SNAPSHOT,
            'http://deb.debian.org/debian',
            $dockerfileContents,
        ),
        'unverified pnpm install' => str_replace('sha512sum --check --strict', 'true', $dockerfileContents),
        'comment-only pnpm checksum' => str_replace(
            'echo "${PNPM_SHA512}  /tmp/pnpm-${PNPM_VERSION}.tgz" | sha512sum --check --strict',
            "true\n# echo \"\${PNPM_SHA512}  /tmp/pnpm-\${PNPM_VERSION}.tgz\" | sha512sum --check --strict",
            $dockerfileContents,
        ),
    ];

    foreach ($policyMutations as $description => $mutation) {
        if (validateRootDockerfilePolicy($mutation) === []) {
            $failures[] = "The validator accepted its {$description} mutation.";
        }
    }

    return $failures;
}

$repositoryRoot = dirname(__DIR__, 2);
$failures = [];
$dockerfiles = discoverFiles(
    $repositoryRoot,
    static fn (string $filename): bool => preg_match('/^Dockerfile(?:\.|$)/', $filename) === 1,
);
$composeFiles = discoverFiles(
    $repositoryRoot,
    static fn (string $filename): bool => preg_match('/^(?:docker-)?compose(?:[.-][a-zA-Z0-9_-]+)*\.ya?ml$/', $filename) === 1,
);

foreach ($dockerfiles as $dockerfile) {
    $relativePath = ltrim(substr($dockerfile, strlen($repositoryRoot)), DIRECTORY_SEPARATOR);
    $contents = file_get_contents($dockerfile);

    if ($contents === false) {
        $failures[] = "Unable to read {$relativePath}.";

        continue;
    }

    $failures = [...$failures, ...validateDockerfile($contents, $relativePath)];
}

foreach ($composeFiles as $composeFile) {
    $relativePath = ltrim(substr($composeFile, strlen($repositoryRoot)), DIRECTORY_SEPARATOR);

    try {
        $compose = Yaml::parseFile($composeFile);
    } catch (Throwable $exception) {
        $failures[] = "Unable to parse {$relativePath}: {$exception->getMessage()}";

        continue;
    }

    if (! is_array($compose)) {
        $failures[] = "{$relativePath} must contain a YAML mapping.";

        continue;
    }

    $failures = [...$failures, ...validateCompose($compose, $relativePath)];
}

$rootDockerfile = file_get_contents($repositoryRoot.'/Dockerfile');

if ($rootDockerfile === false) {
    $failures[] = 'Unable to read the root Dockerfile.';
} else {
    $failures = [...$failures, ...validateRootDockerfilePolicy($rootDockerfile)];
    $failures = [...$failures, ...runMutationSelfTests($rootDockerfile)];
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", array_unique($failures))."\n");
    exit(1);
}

fwrite(STDOUT, "Deployment dependency pinning verified.\n");
