<?php

use App\Http\Middleware\AddContentSecurityPolicy;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('render dependency origin checks reject browser cross-origin URL forms', function (string $href) {
    expect(renderDependencyIsSameOrigin($href, 'https://queuefix.test'))->toBeFalse();
})->with([
    'alternate port' => ['https://queuefix.test:444/evil.css'],
    'data stylesheet' => ['data:text/css,@import url(https://evil.example/style.css)'],
    'external protocol-relative URL' => ['//evil.example/style.css'],
    'leading control whitespace' => ["\thttps://evil.example/style.css"],
    'browser-normalized backslash network path' => ['\\\\evil.example/style.css'],
]);

test('application shell loads render dependencies only from the application origin', function () {
    $response = get(route('login'))->assertOk();

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $loaded = $document->loadHTML($response->getContent());
    libxml_clear_errors();

    expect($loaded)->toBeTrue();

    $xpath = new DOMXPath($document);
    $normalizedRel = 'concat(" ", translate(normalize-space(@rel), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " ")';
    $stylesheets = $xpath->query("//link[contains({$normalizedRel}, \" stylesheet \")]");
    $renderDependencies = $xpath->query("//link[contains({$normalizedRel}, \" stylesheet \") or contains({$normalizedRel}, \" preconnect \")]");

    if ($stylesheets === false || $renderDependencies === false) {
        throw new RuntimeException('Unable to inspect application shell links.');
    }

    expect($stylesheets->length)->toBeGreaterThan(0);

    $applicationUrl = config('app.url');

    if (! is_string($applicationUrl)) {
        throw new RuntimeException('The configured application URL must be a string.');
    }

    foreach ($renderDependencies as $link) {
        if (! $link instanceof DOMElement) {
            throw new RuntimeException('Application shell link parsing returned an unexpected node.');
        }

        $href = $link->getAttribute('href');

        expect(renderDependencyIsSameOrigin($href, $applicationUrl))->toBeTrue(
            "Application shell contains a cross-origin render dependency: {$href}",
        );
    }

    $compiledStylesheets = glob(public_path('build/assets/*.css'));

    if ($compiledStylesheets === false) {
        throw new RuntimeException('Unable to inspect compiled application stylesheets.');
    }

    expect($compiledStylesheets)->not->toBeEmpty();

    foreach ($compiledStylesheets as $stylesheet) {
        $css = file_get_contents($stylesheet);

        if ($css === false) {
            throw new RuntimeException("Unable to read compiled stylesheet: {$stylesheet}");
        }

        expect(preg_match("/(?:@import\\s+(?:url\\(\\s*)?|url\\(\\s*)['\"]?(?:https?:)?\\/\\//i", $css))->toBe(0);
    }
});

function renderDependencyIsSameOrigin(string $href, string $applicationUrl): bool
{
    if ($href === '' || str_contains($href, '\\') || preg_match('/[\x00-\x20\x7f]/', $href) === 1) {
        return false;
    }

    $url = parse_url($href);
    $application = parse_url($applicationUrl);

    if ($url === false || ! is_array($application) || ! isset($application['scheme'], $application['host'])) {
        return false;
    }

    if (! isset($url['host'])) {
        return ! isset($url['scheme']);
    }

    $defaultPort = fn (string $scheme): ?int => match ($scheme) {
        'http' => 80,
        'https' => 443,
        default => null,
    };
    $applicationScheme = strtolower($application['scheme']);
    $scheme = strtolower($url['scheme'] ?? $applicationScheme);

    return $scheme === $applicationScheme
        && strtolower($url['host']) === strtolower($application['host'])
        && ($url['port'] ?? $defaultPort($scheme)) === ($application['port'] ?? $defaultPort($applicationScheme));
}

test('agent and customer ticket views restrict active message content', function () {
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);

    actingAs(User::factory()->create());
    get(route('agent.tickets.show', $ticket))
        ->assertOk()
        ->assertHeader('content-security-policy', AddContentSecurityPolicy::POLICY);

    actingAs($customer, 'customer');
    get(route('customer.tickets.show', $ticket))
        ->assertOk()
        ->assertHeader('content-security-policy', AddContentSecurityPolicy::POLICY);
});
