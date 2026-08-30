<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class UpdateCheckController extends Controller
{
    public function index(): Response
    {
        $installedVersion = config('updates.version');
        $latestRelease = $this->latestRelease();

        return Inertia::render('Settings/Updates', [
            'updateCheck' => [
                'installedVersion' => $installedVersion,
                'latestVersion' => $latestRelease['version'] ?? null,
                'releaseUrl' => $latestRelease['url'] ?? null,
                'publishedAt' => $latestRelease['publishedAt'] ?? null,
                'notes' => $latestRelease['notes'] ?? null,
                'updateAvailable' => isset($latestRelease['version'])
                    && version_compare(ltrim($latestRelease['version'], 'v'), ltrim($installedVersion, 'v'), '>'),
                'error' => $latestRelease['error'] ?? null,
            ],
        ]);
    }

    /**
     * @return array{version?: string, url?: string, publishedAt?: string, notes?: string, error?: string}
     */
    private function latestRelease(): array
    {
        return Cache::remember('queuefix.latest-release', now()->addHours(config('updates.cache_hours')), function (): array {
            try {
                $release = Http::acceptJson()
                    ->timeout(config('updates.timeout_seconds'))
                    ->get('https://api.github.com/repos/'.config('updates.repository').'/releases/latest')
                    ->throw()
                    ->json();

                return [
                    'version' => $release['tag_name'],
                    'url' => $release['html_url'],
                    'publishedAt' => $release['published_at'],
                    'notes' => $release['body'],
                ];
            } catch (ConnectionException $exception) {
                report($exception);

                return ['error' => 'Unable to check GitHub for updates right now.'];
            } catch (\Illuminate\Http\Client\RequestException $exception) {
                report($exception);

                return ['error' => 'Unable to check GitHub for updates right now.'];
            }
        });
    }
}
