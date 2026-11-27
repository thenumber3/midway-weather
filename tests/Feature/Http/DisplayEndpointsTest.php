<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\MakesWeatherData;
use Tests\TestCase;

/**
 * The two public endpoints are the entire external surface of the appliance.
 *
 * The single most important guarantee these tests enforce: the browser-facing
 * routes are served ENTIRELY from local storage and never reach out to Ambient
 * Weather or Open-Meteo. That is what keeps API credentials and the private
 * coordinates server-side — the browser only ever talks to this app.
 */
final class DisplayEndpointsTest extends TestCase
{
    use MakesWeatherData;
    use RefreshDatabase;

    public function test_the_application_root_renders_the_weather_display_directly(): void
    {
        $this->storeObservation(CarbonImmutable::now('UTC')->subMinutes(2), ['temperature' => 78.0]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Midway Weather')
            ->assertSee('Powder Springs, Georgia');
    }

    public function test_the_display_page_renders_for_the_kiosk(): void
    {
        $this->storeObservation(CarbonImmutable::now('UTC')->subMinutes(2), ['temperature' => 78.0]);

        $this->get('/display/weather')
            ->assertOk()
            ->assertSee('Midway Weather')
            ->assertSee('Powder Springs, Georgia');
    }

    public function test_the_display_generates_subdirectory_safe_frontend_urls(): void
    {
        URL::forceRootUrl('https://example.test/midway-weather/public');

        $this->get('https://example.test/display/weather')
            ->assertOk()
            ->assertSee('href="https://example.test/midway-weather/public/favicon.ico"', false)
            ->assertSee('href="https://example.test/midway-weather/public/vendor/leaflet/leaflet.css?v=2"', false)
            ->assertSee('href="https://example.test/midway-weather/public/display/app.css?v=21"', false)
            ->assertSee('src="https://example.test/midway-weather/public/vendor/leaflet/leaflet.js?v=2"', false)
            ->assertSee('src="https://example.test/midway-weather/public/display/app.js?v=16"', false)
            ->assertSee('data-weather-url="https://example.test/midway-weather/public/api/display/weather"', false)
            ->assertSee('data-leaflet-images-url="https://example.test/midway-weather/public/vendor/leaflet/images/"', false);
    }

    public function test_browser_client_uses_blade_generated_urls(): void
    {
        $script = file_get_contents(public_path('display/app.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString("displayUrl('data-weather-url')", $script);
        $this->assertStringContainsString("displayUrl('data-leaflet-images-url')", $script);
        $this->assertStringNotContainsString("fetch('/", $script);
        $this->assertStringNotContainsString("imagePath = '/", $script);
        $this->assertStringNotContainsString('window.location.origin', $script);
    }

    public function test_the_display_uses_the_configured_map_zoom(): void
    {
        config(['weather.radar.zoom' => 9]);

        $this->get('/display/weather')
            ->assertOk()
            ->assertSee('data-zoom="9"', false);
    }

    public function test_the_display_exposes_radar_configuration_as_data_attributes(): void
    {
        config([
            'weather.radar.enabled' => true,
            'weather.radar.metadata_url' => 'https://api.rainviewer.com/public/weather-maps.json',
            'weather.radar.refresh_minutes' => 10,
            'weather.radar.opacity' => 0.65,
        ]);

        $this->get('/display/weather')
            ->assertOk()
            ->assertSee('data-radar-enabled="true"', false)
            ->assertSee('data-radar-metadata-url="https://api.rainviewer.com/public/weather-maps.json"', false)
            ->assertSee('data-radar-refresh-minutes="10"', false)
            ->assertSee('data-radar-refresh-ms="600000"', false)
            ->assertSee('data-radar-opacity="0.65"', false)
            ->assertSee('id="radar-time">Radar · Updating…</div>', false);
    }

    public function test_radar_can_be_disabled_without_removing_the_static_map(): void
    {
        config(['weather.radar.enabled' => false]);

        $this->get('/display/weather')
            ->assertOk()
            ->assertSee('id="map"', false)
            ->assertSee('data-radar-enabled="false"', false)
            ->assertSee('id="radar-time">Map</div>', false)
            ->assertDontSee('href="https://stadiamaps.com/attribution/"', false)
            ->assertSee('data-basemap-provider="openstreetmap"', false)
            ->assertSee('href="https://www.openstreetmap.org/copyright"', false);
    }

    public function test_default_basemap_is_keyless_and_shared_by_html_and_display_api(): void
    {
        config(['weather.basemap.stadia_api_key' => 'unused-owner-key']);
        $this->get('/')->assertOk()
            ->assertSee('data-basemap-url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"', false)
            ->assertSee('OpenStreetMap contributors')
            ->assertDontSee('unused-owner-key');
        $this->getJson('/api/display/weather')->assertOk()
            ->assertJsonPath('meta.basemap.provider', 'openstreetmap')
            ->assertJsonPath('meta.basemap.max_zoom', 19);
    }

    public function test_stadia_is_opt_in_and_supports_domain_auth_or_an_owner_tile_key(): void
    {
        config(['weather.basemap.provider' => 'stadia', 'weather.basemap.stadia_api_key' => '']);
        $url = 'https://tiles.stadiamaps.com/tiles/alidade_smooth_dark/{z}/{x}/{y}{r}.png';
        $this->get('/')->assertOk()
            ->assertSee('data-basemap-provider="stadia"', false)
            ->assertSee('href="https://stadiamaps.com/attribution/"', false)
            ->assertSee('href="https://openmaptiles.org/"', false);
        $this->getJson('/api/display/weather')->assertJsonPath('meta.basemap.tile_url', $url);

        config(['weather.basemap.stadia_api_key' => 'owner-only+tile/key&']);
        cache()->flush();
        $this->getJson('/api/display/weather')->assertJsonPath(
            'meta.basemap.tile_url', $url.'?api_key=owner-only%2Btile%2Fkey%26',
        );
        $this->get('/')->assertOk()->assertSee('?api_key=owner-only%2Btile%2Fkey%26', false);
    }

    public function test_radar_configuration_has_raspberry_pi_friendly_defaults(): void
    {
        $this->assertTrue(config('weather.radar.enabled'));
        $this->assertSame(8, config('weather.radar.zoom'));
        $this->assertSame(10, config('weather.radar.refresh_minutes'));
        $this->assertSame(0.65, config('weather.radar.opacity'));
        $this->assertSame(
            'https://api.rainviewer.com/public/weather-maps.json',
            config('weather.radar.metadata_url'),
        );
    }

    public function test_browser_client_uses_only_the_latest_radar_frame_without_animation(): void
    {
        $script = file_get_contents(public_path('display/app.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('function latestRadarFrame(metadata)', $script);
        $this->assertStringContainsString('metadata.radar.past', $script);
        $this->assertStringContainsString('frames.reduce(', $script);
        $this->assertStringContainsString('Number(frame.time) > Number(latest.time)', $script);
        $this->assertStringNotContainsString('metadata.radar.nowcast', $script);
        $this->assertStringNotContainsString('frameIndex', $script);
        $this->assertStringNotContainsString('radarAnimation', $script);
        $this->assertStringContainsString('fadeAnimation: false', $script);
        $this->assertStringContainsString('zoomAnimation: false', $script);
    }

    public function test_browser_client_refreshes_one_layer_every_ten_minutes_and_falls_back_to_the_base_map(): void
    {
        $script = file_get_contents(public_path('display/app.js'));

        $this->assertIsString($script);
        $this->assertSame(1, substr_count($script, 'L.tileLayer(radarTileUrl'));
        $this->assertStringContainsString('refreshMinutes * 60 * 1000', $script);
        $this->assertStringContainsString('setInterval(refreshRadar, radarRefreshMs)', $script);
        $this->assertStringContainsString("nextLayer.once('tileerror', fail)", $script);
        $this->assertStringContainsString('map.removeLayer(previousLayer)', $script);
        $this->assertStringContainsString('function showRadarFallback()', $script);
        $this->assertStringContainsString('map.removeLayer(radarLayer)', $script);
        $this->assertStringContainsString("setText($('radar-time'), 'Radar unavailable')", $script);
    }

    public function test_local_temperature_theme_preview_only_overrides_the_visual_mood(): void
    {
        $this->app->detectEnvironment(static fn () => 'local');
        $this->storeObservation(CarbonImmutable::now('UTC')->subMinutes(2), ['temperature' => 78.0]);

        $this->get('/display/weather?temperature_theme=frigid')
            ->assertOk()
            ->assertSee('data-temperature-mood="frigid"', false)
            ->assertSee('data-temperature-preview="frigid"', false)
            ->assertSee('78°');
    }

    public function test_production_ignores_temperature_theme_preview(): void
    {
        $this->app->detectEnvironment(static fn () => 'production');
        $this->storeObservation(CarbonImmutable::now('UTC')->subMinutes(2), ['temperature' => 78.0]);

        $this->get('/display/weather?temperature_theme=frigid')
            ->assertOk()
            ->assertSee('data-temperature-mood="warm"', false)
            ->assertDontSee('data-temperature-preview=', false);
    }

    public function test_the_display_page_awaits_data_when_nothing_is_collected(): void
    {
        // With an empty database the page must still render (no full-page error)
        // and simply announce that it is waiting for the station.
        $this->get('/display/weather')
            ->assertOk()
            ->assertSee('Awaiting station data');
    }

    public function test_the_json_endpoint_returns_the_view_model(): void
    {
        $this->storeObservation(CarbonImmutable::now('UTC')->subMinutes(2), ['temperature' => 78.0]);

        $response = $this->getJson('/api/display/weather')->assertOk();

        $response->assertJsonStructure([
            'meta' => ['station_name', 'location_name', 'timezone', 'map_zoom', 'radar', 'has_data', 'poll_seconds', 'demo_mode'],
            'observation',
            'trends',
            'summary',
            'sparklines' => ['temperature', 'pressure', 'humidity', 'wind'],
            'rain',
            'forecast' => ['source', 'days'],
            'health' => ['freshness', 'ambient', 'forecast'],
            'theme' => ['key', 'is_day', 'temperature_mood'],
        ]);

        $this->assertTrue($response->json('meta.has_data'));
        $this->assertSame('warm', $response->json('theme.temperature_mood'));
    }

    public function test_browser_client_updates_the_root_mood_without_reloading(): void
    {
        $script = file_get_contents(public_path('display/app.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('function updateTemperatureMood(model)', $script);
        $this->assertStringContainsString("setAttribute('data-temperature-mood', mood)", $script);
        $this->assertStringContainsString('updateTemperatureMood(model);', $script);
        $this->assertStringNotContainsString('window.location.reload', $script);
    }

    public function test_the_json_endpoint_is_not_cacheable_by_the_browser(): void
    {
        $response = $this->getJson('/api/display/weather')->assertOk();

        // The JS owns the polling cadence; no intermediary or browser cache may
        // pin a stale snapshot. Symfony normalizes directive order, so assert on
        // the directive rather than an exact string.
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function test_the_json_endpoint_reports_awaiting_data_without_a_full_page_failure(): void
    {
        $response = $this->getJson('/api/display/weather')->assertOk();

        $this->assertFalse($response->json('meta.has_data'));
        $this->assertNull($response->json('observation'));
        $this->assertSame('Awaiting station data', $response->json('summary'));
        $this->assertSame('no_data', $response->json('health.freshness'));
    }

    public function test_the_compact_summary_endpoint_returns_the_versioned_ecosystem_contract(): void
    {
        $now = CarbonImmutable::now('UTC');
        $this->storeObservation($now->subMinutes(2), ['temperature' => 75.0, 'rain_hourly' => 0.12, 'solar_radiation' => 45.0]);
        $this->storeForecastDay($now->setTimezone('America/New_York')->startOfDay(), [
            'weather_code' => 2, 'temperature_high' => 88.0, 'temperature_low' => 64.0,
        ]);

        $response = $this->getJson('/api/v1/summary/current')->assertOk();

        $response->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('contractVersion', 1)
            ->assertJsonPath('kind', 'weather.summary.current')
            ->assertJsonPath('source', 'midway-weather')
            ->assertJsonPath('stale', false)
            ->assertJsonPath('data.temperature.display', '75°F')
            ->assertJsonPath('data.today.high', 88)
            ->assertJsonPath('data.atmosphere.kind', 'rain')
            ->assertJsonPath('data.atmosphere.rain_rate', 0.12)
            ->assertJsonPath('data.mood.key', 'warm');
        $this->assertContains($response->json('data.condition.icon'), ['partly-cloudy-day', 'partly-cloudy-night']);
        $this->assertIsString($response->json('data.summary.message'));
        $this->assertIsString($response->json('data.summary.phraseId'));
    }

    public function test_the_compact_summary_endpoint_marks_last_valid_data_as_stale(): void
    {
        $now = CarbonImmutable::now('UTC');
        $this->storeObservation($now->subMinutes(30), ['temperature' => 24.0]);
        $this->storeForecastDay($now->setTimezone('America/New_York')->startOfDay());

        $this->getJson('/api/v1/summary/current')
            ->assertOk()
            ->assertJsonPath('stale', true)
            ->assertJsonPath('data.mood.key', 'frigid');
    }

    public function test_the_compact_summary_endpoint_returns_a_json_error_when_no_data_exists(): void
    {
        $this->getJson('/api/v1/summary/current')
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('data', null)
            ->assertJsonPath('error.code', 'weather_data_unavailable');
    }

    public function test_browser_facing_endpoints_never_call_third_party_apis(): void
    {
        // Configure real-looking credentials and base URLs so that a stray
        // client call WOULD actually fire if the code path existed.
        config([
            'weather.ambient.api_key' => 'real-looking-key',
            'weather.ambient.application_key' => 'real-looking-app-key',
            'weather.ambient.device_mac' => 'AA:BB:CC:DD:EE:FF',
            'weather.ambient.base_url' => 'https://rt.ambientweather.net/v1',
            'weather.open_meteo.base_url' => 'https://api.open-meteo.com/v1/forecast',
        ]);

        // Seed local data so the presenter renders the full (has-data) path,
        // not merely the empty path.
        $this->storeObservation(CarbonImmutable::now('UTC')->subMinutes(2), ['temperature' => 78.0]);
        $this->storeForecastDay(CarbonImmutable::now('America/New_York')->startOfDay());

        // Intercept ALL outbound HTTP. Any call to Ambient or Open-Meteo from a
        // request-serving path would be recorded here.
        Http::fake();

        $this->get('/display/weather')->assertOk();
        $this->getJson('/api/display/weather')->assertOk();

        // Proof: serving the browser touched no third-party API whatsoever.
        Http::assertNothingSent();
    }
}
