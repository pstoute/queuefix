#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const IMMUTABLE_ACTION_PATTERN = '/^[A-Za-z0-9_.-]+(?:\/[A-Za-z0-9_.-]+)+@[0-9a-f]{40}$/D';
const IMMUTABLE_IMAGE_PATTERN = '/^[^@\s]+@sha256:[0-9a-f]{64}$/D';

function reject(string $message): never
{
    throw new RuntimeException($message);
}

function validateExternalReference(mixed $reference, string $location): void
{
    if (! is_string($reference) || $reference === '') {
        reject("{$location} must be a non-empty string");
    }

    if (str_starts_with($reference, 'docker://')) {
        validateImageReference(substr($reference, strlen('docker://')), $location);

        return;
    }

    if (preg_match(IMMUTABLE_ACTION_PATTERN, $reference) !== 1) {
        reject("{$location} uses a mutable external action: {$reference}");
    }
}

/**
 * @param  array<string, true>  $validatedActions
 * @param  array<string, true>  $validatingActions
 */
function validateActionReference(
    mixed $reference,
    string $location,
    string $repositoryRoot,
    array &$validatedActions,
    array &$validatingActions,
): void {
    if (! is_string($reference) || ! str_starts_with($reference, './')) {
        validateExternalReference($reference, $location);

        return;
    }

    $actionDirectory = realpath($repositoryRoot.'/'.$reference);
    $repositoryRoot = realpath($repositoryRoot) ?: reject("{$location} repository root cannot be resolved");

    if (
        $actionDirectory === false
        || ! is_dir($actionDirectory)
        || ! str_starts_with($actionDirectory, $repositoryRoot.DIRECTORY_SEPARATOR)
    ) {
        reject("{$location} references a missing or out-of-repository local action");
    }

    $manifestPaths = array_values(array_filter([
        is_file($actionDirectory.'/action.yml') ? $actionDirectory.'/action.yml' : null,
        is_file($actionDirectory.'/action.yaml') ? $actionDirectory.'/action.yaml' : null,
    ]));

    if (count($manifestPaths) !== 1) {
        reject("{$location} local action must contain exactly one action.yml or action.yaml manifest");
    }

    validateLocalAction($manifestPaths[0], $repositoryRoot, $validatedActions, $validatingActions);
}

function validateImageReference(mixed $reference, string $location): void
{
    if (! is_string($reference) || preg_match(IMMUTABLE_IMAGE_PATTERN, $reference) !== 1) {
        reject("{$location} uses a mutable container image");
    }
}

function validatePermissionOverride(mixed $permissions, string $location): void
{
    if (! is_array($permissions)) {
        reject("{$location} must be an explicit map of read or none permissions");
    }

    foreach ($permissions as $scope => $access) {
        if (! is_string($scope) || ! in_array($access, ['read', 'none'], true)) {
            reject("{$location} grants or ambiguously declares write access");
        }
    }
}

/**
 * @param  array<string, mixed>  $workflow
 * @param  array<string, true>  $validatedActions
 * @param  array<string, true>  $validatingActions
 */
function validateWorkflow(
    array $workflow,
    string $location,
    string $repositoryRoot,
    array &$validatedActions,
    array &$validatingActions,
): void {
    if (($workflow['permissions'] ?? null) !== ['contents' => 'read']) {
        reject("{$location} must grant only workflow-level contents: read permission");
    }

    $jobs = $workflow['jobs'] ?? null;

    if (! is_array($jobs) || $jobs === []) {
        reject("{$location} contains no jobs to validate");
    }

    foreach ($jobs as $jobName => $job) {
        $jobLocation = "{$location} job {$jobName}";

        if (! is_array($job)) {
            reject("{$jobLocation} must be a map");
        }

        if (array_key_exists('permissions', $job)) {
            validatePermissionOverride($job['permissions'], "{$jobLocation} permissions");
        }

        if (array_key_exists('uses', $job)) {
            validateExternalReference($job['uses'], "{$jobLocation} uses");
        }

        if (array_key_exists('container', $job)) {
            $container = $job['container'];
            $image = is_array($container) ? ($container['image'] ?? null) : $container;
            validateImageReference($image, "{$jobLocation} container");
        }

        if (array_key_exists('services', $job)) {
            if (! is_array($job['services'])) {
                reject("{$jobLocation} services must be a map");
            }

            foreach ($job['services'] as $serviceName => $service) {
                if (! is_array($service)) {
                    reject("{$jobLocation} service {$serviceName} must be a map");
                }

                validateImageReference(
                    $service['image'] ?? null,
                    "{$jobLocation} service {$serviceName}",
                );
            }
        }

        if (! array_key_exists('steps', $job)) {
            continue;
        }

        if (! is_array($job['steps'])) {
            reject("{$jobLocation} steps must be a list");
        }

        foreach ($job['steps'] as $stepIndex => $step) {
            if (! is_array($step) || ! array_key_exists('uses', $step)) {
                continue;
            }

            $stepLocation = "{$jobLocation} step {$stepIndex}";
            validateActionReference(
                $step['uses'],
                "{$stepLocation} uses",
                $repositoryRoot,
                $validatedActions,
                $validatingActions,
            );

            if (! str_starts_with((string) $step['uses'], 'actions/checkout@')) {
                continue;
            }

            $with = $step['with'] ?? null;

            if (! is_array($with) || ($with['persist-credentials'] ?? null) !== false) {
                reject("{$stepLocation} must set with.persist-credentials to false");
            }
        }
    }
}

/**
 * @param  array<string, true>  $validatedActions
 * @param  array<string, true>  $validatingActions
 */
function validateLocalAction(
    string $manifestPath,
    string $repositoryRoot,
    array &$validatedActions,
    array &$validatingActions,
): void {
    $manifestPath = realpath($manifestPath) ?: reject("{$manifestPath} cannot be resolved");

    if (isset($validatedActions[$manifestPath])) {
        return;
    }

    if (isset($validatingActions[$manifestPath])) {
        reject("{$manifestPath} contains a local action reference cycle");
    }

    $validatingActions[$manifestPath] = true;
    $action = parseYaml((string) file_get_contents($manifestPath), $manifestPath);
    $runs = $action['runs'] ?? null;

    if (! is_array($runs) || ! is_string($runs['using'] ?? null)) {
        reject("{$manifestPath} must declare runs.using");
    }

    $using = $runs['using'];

    if ($using === 'docker') {
        $image = $runs['image'] ?? null;

        if (! is_string($image) || ! str_starts_with($image, 'docker://')) {
            reject("{$manifestPath} must use a digest-pinned docker:// image instead of a local Dockerfile");
        }

        validateImageReference(substr($image, strlen('docker://')), "{$manifestPath} runs.image");
    } elseif ($using === 'composite') {
        validateCompositeAction($action, $manifestPath, $repositoryRoot, $validatedActions, $validatingActions);
    } elseif (preg_match('/^node(?:12|16|20|24)$/D', $using) !== 1) {
        reject("{$manifestPath} uses an unsupported action runtime: {$using}");
    }

    unset($validatingActions[$manifestPath]);
    $validatedActions[$manifestPath] = true;
}

/**
 * @param  array<string, mixed>  $action
 * @param  array<string, true>  $validatedActions
 * @param  array<string, true>  $validatingActions
 */
function validateCompositeAction(
    array $action,
    string $location,
    string $repositoryRoot,
    array &$validatedActions,
    array &$validatingActions,
): void {
    $steps = $action['runs']['steps'] ?? null;

    if (! is_array($steps)) {
        reject("{$location} composite steps must be a list");
    }

    foreach ($steps as $stepIndex => $step) {
        if (! is_array($step) || ! array_key_exists('uses', $step)) {
            continue;
        }

        validateActionReference(
            $step['uses'],
            "{$location} step {$stepIndex} uses",
            $repositoryRoot,
            $validatedActions,
            $validatingActions,
        );
    }
}

/**
 * @return array<string, mixed>
 */
function parseYaml(string $yaml, string $location): array
{
    $parsed = Yaml::parse($yaml);

    if (! is_array($parsed)) {
        reject("{$location} must contain a YAML map");
    }

    return $parsed;
}

function validateWorkflowYaml(string $yaml, string $location, string $repositoryRoot): void
{
    $validatedActions = [];
    $validatingActions = [];

    validateWorkflow(
        parseYaml($yaml, $location),
        $location,
        $repositoryRoot,
        $validatedActions,
        $validatingActions,
    );
}

function expectRejected(string $yaml, string $case, string $repositoryRoot): void
{
    try {
        validateWorkflowYaml($yaml, $case, $repositoryRoot);
    } catch (RuntimeException) {
        return;
    }

    reject("validator self-test accepted {$case}");
}

function runValidatorSelfTests(): void
{
    $fixtureRoot = sys_get_temp_dir().'/queuefix-ci-validator-'.bin2hex(random_bytes(8));
    $localActionRoot = $fixtureRoot.'/tools/ci-action';
    $dockerActionRoot = $fixtureRoot.'/tools/docker-action';

    if (! mkdir($localActionRoot, 0700, true) || ! mkdir($dockerActionRoot, 0700, true)) {
        reject('unable to create validator self-test fixtures');
    }

    $sha = '11d5960a326750d5838078e36cf38b85af677262';
    $valid = <<<YAML
name: Validator self-test
on: push
permissions:
  contents: read
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@{$sha}
        with:
          persist-credentials: false
YAML;

    try {
        validateWorkflowYaml($valid, 'valid self-test', $fixtureRoot);

        expectRejected(
            str_replace("uses: actions/checkout@{$sha}", 'uses : actions/checkout@v4', $valid),
            'spaced mutable uses key',
            $fixtureRoot,
        );
        expectRejected(
            str_replace("uses: actions/checkout@{$sha}", '"uses": actions/checkout@v4', $valid),
            'quoted mutable uses key',
            $fixtureRoot,
        );
        expectRejected(
            str_replace('    steps:', "    services:\n      postgres:\n        image : postgres:16-alpine\n    steps:", $valid),
            'spaced mutable service image key',
            $fixtureRoot,
        );
        expectRejected(
            str_replace('    runs-on: ubuntu-latest', "    runs-on: ubuntu-latest\n    container: node:latest", $valid),
            'mutable job container shorthand',
            $fixtureRoot,
        );
        expectRejected(
            str_replace("permissions:\n  contents: read", 'permissions : write-all', $valid),
            'spaced write-all permissions key',
            $fixtureRoot,
        );
        expectRejected(
            str_replace(
                "        with:\n          persist-credentials: false",
                "        with:\n          persist-credentials: true\n        env:\n          persist-credentials: false",
                $valid,
            ),
            'checkout credential setting outside with scope',
            $fixtureRoot,
        );

        file_put_contents(
            $localActionRoot.'/action.yml',
            "name: Mutable local action\nruns:\n  using: composite\n  steps:\n    - uses: actions/cache@v4\n",
        );
        expectRejected(
            str_replace("actions/checkout@{$sha}", './tools/ci-action', $valid),
            'mutable dependency in recursively resolved local action',
            $fixtureRoot,
        );

        file_put_contents(
            $dockerActionRoot.'/action.yml',
            "name: Mutable Docker action\nruns:\n  using: docker\n  image: docker://alpine:latest\n",
        );
        expectRejected(
            str_replace("actions/checkout@{$sha}", './tools/docker-action', $valid),
            'mutable local Docker action image',
            $fixtureRoot,
        );

        file_put_contents(
            $dockerActionRoot.'/action.yml',
            "name: Local Dockerfile action\nruns:\n  using: docker\n  image: Dockerfile\n",
        );
        expectRejected(
            str_replace("actions/checkout@{$sha}", './tools/docker-action', $valid),
            'unverified local Dockerfile action image',
            $fixtureRoot,
        );
    } finally {
        @unlink($localActionRoot.'/action.yml');
        @unlink($dockerActionRoot.'/action.yml');
        @rmdir($localActionRoot);
        @rmdir($dockerActionRoot);
        @rmdir($fixtureRoot.'/tools');
        @rmdir($fixtureRoot);
    }
}

try {
    runValidatorSelfTests();

    $repositoryRoot = dirname(__DIR__, 2);
    $workflowPaths = [
        ...glob($repositoryRoot.'/.github/workflows/*.yml') ?: [],
        ...glob($repositoryRoot.'/.github/workflows/*.yaml') ?: [],
    ];

    if ($workflowPaths === []) {
        reject('no GitHub Actions workflows found');
    }

    foreach ($workflowPaths as $workflowPath) {
        validateWorkflowYaml(
            (string) file_get_contents($workflowPath),
            $workflowPath,
            $repositoryRoot,
        );
    }

    $actionRoot = $repositoryRoot.'/.github/actions';

    if (is_dir($actionRoot)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($actionRoot));

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getFilename(), ['action.yml', 'action.yaml'], true)) {
                continue;
            }

            $path = $file->getPathname();
            $validatedActions = [];
            $validatingActions = [];
            validateLocalAction($path, $repositoryRoot, $validatedActions, $validatingActions);
        }
    }

    fwrite(STDOUT, "GitHub Actions dependencies are immutable and least privileged.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "CI dependency pinning regression failed: {$exception->getMessage()}\n");
    exit(1);
}
