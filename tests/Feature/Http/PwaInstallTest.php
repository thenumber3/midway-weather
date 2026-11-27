<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class PwaInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_available_with_install_metadata_and_icons(): void
    {
        $response = $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');

        $response->assertJson([
            'name' => 'Midway Weather',
            'short_name' => 'Midway',
            'description' => 'Live weather conditions, radar, and forecast for the Turner Home in Powder Springs, Georgia.',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#021b39',
            'theme_color' => '#04385c',
        ]);

        $response->assertJsonPath('icons.0.sizes', '192x192');
        $response->assertJsonPath('icons.1.sizes', '512x512');
        $response->assertJsonPath('icons.2.sizes', '512x512');
        $response->assertJsonPath('icons.2.purpose', 'maskable');
    }

    public function test_manifest_urls_follow_the_configured_deployment_root(): void
    {
        URL::forceRootUrl('https://example.test/midway-weather/public');

        $manifest = $this->get('https://example.test/manifest.webmanifest')->assertOk()->json();

        $this->assertSame(
            'https://example.test/midway-weather/public',
            $manifest['start_url'],
        );
        $this->assertSame(
            'https://example.test/midway-weather/public/display/weather',
            $manifest['id'],
        );
        $this->assertSame(
            'https://example.test/midway-weather/public/',
            $manifest['scope'],
        );
        $this->assertStringStartsWith(
            'https://example.test/midway-weather/public/icons/',
            $manifest['icons'][0]['src'],
        );
        $this->assertStringNotContainsString('weather.rockpaperfire.com', json_encode($manifest));
    }

    public function test_blade_includes_pwa_and_apple_metadata_and_mobile_install_markup(): void
    {
        $this->get('/display/weather')
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('name="theme-color" content="#04385c"', false)
            ->assertSee('name="apple-mobile-web-app-capable" content="yes"', false)
            ->assertSee('name="apple-mobile-web-app-status-bar-style" content="black-translucent"', false)
            ->assertSee('name="apple-mobile-web-app-title" content="Midway"', false)
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee('id="install-button"', false)
            ->assertSee('type="button"', false)
            ->assertSee('id="ios-install-dialog"', false)
            ->assertSee('Tap the <strong>Share</strong> button.', false)
            ->assertSee('Choose <strong>Add to Home Screen</strong>.', false);
    }

    public function test_leaflet_and_pwa_assets_use_cache_busting_versions(): void
    {
        $this->get('/display/weather')
            ->assertOk()
            ->assertSee('vendor/leaflet/leaflet.css?v=2', false)
            ->assertSee('vendor/leaflet/leaflet.js?v=2', false)
            ->assertSee('display/app.css?v=21', false)
            ->assertSee('display/app.js?v=16', false)
            ->assertSee('display/atmosphere.css?v=1', false)
            ->assertSee('display/atmosphere.js?v=1', false)
            ->assertSee('display/pwa.js?v=6', false)
            ->assertSee('service-worker.js?v=20', false);
    }

    public function test_required_icon_files_are_present(): void
    {
        $icons = [
            'icons/midway-weather.svg',
            'icons/midway-weather-192.png',
            'icons/midway-weather-512.png',
            'icons/midway-weather-maskable-512.png',
            'icons/apple-touch-icon.png',
            'icons/favicon-32.png',
            'favicon.ico',
        ];

        foreach ($icons as $icon) {
            $this->assertFileExists(public_path($icon));
        }
    }

    public function test_pwa_client_registers_once_and_hides_install_ui_in_standalone_mode(): void
    {
        $script = file_get_contents(public_path('display/pwa.js'));

        $this->assertIsString($script);
        $this->assertSame(1, substr_count($script, "addEventListener('beforeinstallprompt'"));
        $this->assertSame(1, substr_count($script, "addEventListener('appinstalled'"));
        $this->assertSame(1, substr_count($script, 'serviceWorker.register('));
        $this->assertStringContainsString('return registration.update();', $script);
        $this->assertStringContainsString('.catch(function () {', $script);
        $this->assertStringContainsString("matchMedia('(display-mode: standalone)')", $script);
        $this->assertStringContainsString('window.navigator.standalone === true', $script);
        $this->assertStringContainsString('if (isStandalone() && isIPhone())', $script);
        $this->assertStringContainsString("classList.add('iphone-standalone')", $script);
        $this->assertStringContainsString('if (isStandalone())', $script);
        $this->assertStringContainsString('hideInstallButton();', $script);
        $this->assertStringContainsString('event.preventDefault();', $script);
        $this->assertStringContainsString('promptEvent.prompt();', $script);
        $this->assertStringContainsString('promptEvent.userChoice', $script);
        $this->assertStringContainsString("choiceResult.outcome === 'accepted'", $script);
    }

    public function test_pwa_visual_preview_is_available_only_in_local_environment(): void
    {
        $this->app->detectEnvironment(static fn () => 'local');

        $this->get('/display/weather?pwa_preview=ios')
            ->assertOk()
            ->assertSee('data-preview-mode="ios"', false);

        $this->app->detectEnvironment(static fn () => 'production');

        $this->get('/display/weather?pwa_preview=ios')
            ->assertOk()
            ->assertSee('data-preview-mode=""', false);
    }

    public function test_service_worker_does_not_cache_weather_json_or_third_party_tiles(): void
    {
        $script = file_get_contents(public_path('service-worker.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString("var CACHE_NAME = 'midway-shell-v23';", $script);
        $this->assertStringContainsString('Promise.all(SHELL_URLS.map(function (url)', $script);
        $this->assertStringContainsString('return cache.add(url).catch(function ()', $script);
        $this->assertStringNotContainsString('cache.addAll(', $script);
        $this->assertStringContainsString("requestUrl.pathname.indexOf('/api/') !== -1", $script);
        $this->assertStringContainsString("request.mode === 'navigate'", $script);
        $this->assertStringContainsString('requestUrl.origin !== self.location.origin', $script);
        $this->assertStringContainsString('caches.delete(cacheName)', $script);
        $this->assertContains('display/atmosphere.js?v=1', $this->shellPaths($script));
        $this->assertContains('display/atmosphere.css?v=1', $this->shellPaths($script));
        $this->assertStringNotContainsString('api/display/weather', implode("\n", $this->shellPaths($script)));
        $this->assertStringNotContainsString('rainviewer', strtolower(implode("\n", $this->shellPaths($script))));
        $this->assertStringNotContainsString('openstreetmap', strtolower(implode("\n", $this->shellPaths($script))));
    }

    /**
     * @return list<string>
     */
    private function shellPaths(string $script): array
    {
        preg_match('/var SHELL_PATHS = \\[(.*?)\\];/s', $script, $match);
        preg_match_all("/'([^']+)'/", $match[1] ?? '', $paths);

        return $paths[1] ?? [];
    }
}
