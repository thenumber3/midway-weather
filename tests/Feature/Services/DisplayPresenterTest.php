<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Weather\Services\DisplayPresenter;
use App\Weather\Services\StationHealthService;
use App\Weather\Support\ConditionPhraseLibrary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\MakesWeatherData;
use Tests\TestCase;

/**
 * The presenter is the single source of truth shared by the server-rendered
 * page and the JSON endpoint. These tests pin the shape of that view model and,
 * critically, its behavior when the station has produced no data yet — it must
 * degrade to an "awaiting data" state, never a zeroed-out or broken payload.
 */
final class DisplayPresenterTest extends TestCase
{
    use MakesWeatherData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'weather.timezone' => 'America/New_York',
            'weather.station_name' => 'Midway Weather',
            'weather.location_name' => 'Powder Springs, Georgia',
        ]);
    }

    private function presenter(): DisplayPresenter
    {
        return app(DisplayPresenter::class);
    }

    public function test_present_returns_the_full_view_model_shape(): void
    {
        $now = CarbonImmutable::parse('2026-07-21T17:00:00Z');
        $this->storeObservation($now->subMinutes(3), ['temperature' => 81.0]);

        $vm = $this->presenter()->present($now);

        $this->assertSame(
            ['configuration', 'sensor_tiles', 'identity', 'theme_catalog', 'seasonal_theme', 'meta', 'observation', 'trends', 'summary', 'sparklines', 'rain', 'forecast', 'health', 'theme'],
            array_keys($vm),
        );

        $this->assertSame('Midway Weather', $vm['meta']['station_name']);
        $this->assertSame('Powder Springs, Georgia', $vm['meta']['location_name']);
        $this->assertSame(8, $vm['meta']['map_zoom']);
        $this->assertSame([
            'enabled' => true,
            'metadata_url' => 'https://api.rainviewer.com/public/weather-maps.json',
            'refresh_minutes' => 10,
            'opacity' => 0.65,
        ], $vm['meta']['radar']);
        $this->assertTrue($vm['meta']['has_data']);
        $this->assertSame(81.0, $vm['observation']['temperature']);
        $this->assertSame('warm', $vm['theme']['temperature_mood']);
    }

    public function test_display_scene_uses_live_precipitation_and_retires_stale_inputs(): void
    {
        $now = CarbonImmutable::parse('2026-11-26 17:40', 'America/New_York');
        $this->travelTo($now);
        $forecast = $this->storeForecastDay($now, [
            'weather_code' => 95,
            'fetched_at' => $now->subMinutes(15)->utc(),
            'sunrise' => $now->setTime(7, 20)->utc(),
            'sunset' => $now->setTime(17, 35)->utc(),
        ]);
        $this->storeObservation($now->subMinute()->utc(), ['temperature' => 39, 'rain_hourly' => 0]);
        $dry = $this->presenter()->present($now)['theme']['scene'];
        $this->assertSame('clear', $dry['kind']);
        $this->assertSame($now->setTime(17, 35)->timestamp, $dry['solar']['sunset']);

        $this->storeObservation($now->utc(), ['temperature' => 39, 'rain_hourly' => 0.24, 'wind_direction' => 225]);
        $storm = $this->presenter()->present($now)['theme']['scene'];
        $this->assertSame('thunderstorm', $storm['kind']);
        $this->assertSame(39.0, $storm['temperature']);
        $this->assertSame(225, $storm['direction']);

        $forecast->update(['fetched_at' => $now->subHours(8)->utc()]);
        $this->assertSame('rain', $this->presenter()->present($now)['theme']['scene']['kind']);

        $later = $this->presenter()->present($now->addHour());
        $this->assertSame('stale', $later['health']['freshness']);
        $this->assertSame(39.0, $later['observation']['temperature']);
        $this->assertFalse($later['theme']['scene']['available']);
        $this->assertNull($later['theme']['scene']['rain']);
    }

    public function test_present_uses_the_configured_map_zoom(): void
    {
        config(['weather.radar.zoom' => 9]);

        $vm = $this->presenter()->present(CarbonImmutable::parse('2026-07-21T17:00:00Z'));

        $this->assertSame(9, $vm['meta']['map_zoom']);
    }

    public function test_present_uses_live_shaped_observation_and_forecast_context_for_the_phrase(): void
    {
        $now = CarbonImmutable::now('UTC');
        $this->storeObservation($now->subMinutes(2), [
            'temperature' => 70.0,
            'rain_hourly' => 0.0,
            'wind_gust' => 8.0,
        ]);
        $this->storeForecastDay($now->setTimezone('America/New_York')->startOfDay(), [
            'weather_code' => 95,
        ]);

        $vm = $this->presenter()->present($now);

        $this->assertContains($vm['summary'], app(ConditionPhraseLibrary::class)->phrasesFor('stormy'));
    }

    public function test_present_awaits_data_when_there_are_no_observations(): void
    {
        $vm = $this->presenter()->present(CarbonImmutable::parse('2026-07-21T17:00:00Z'));

        // No data must NOT become zeros or a failure — it becomes a clean
        // "awaiting" state the UI can render calmly.
        $this->assertFalse($vm['meta']['has_data']);
        $this->assertNull($vm['observation']);
        $this->assertSame([], $vm['trends']);
        $this->assertSame('Awaiting station data', $vm['summary']);
        $this->assertSame(StationHealthService::NO_DATA, $vm['health']['freshness']);
        $this->assertNull($vm['health']['observation_age_minutes']);
        $this->assertSame('mild', $vm['theme']['temperature_mood']);
    }

    public function test_present_never_calls_third_party_apis(): void
    {
        Http::fake();

        $this->storeObservation(CarbonImmutable::now('UTC')->subMinutes(2));
        $this->presenter()->present();

        // Building the view model reads only local storage.
        Http::assertNothingSent();
    }

    public function test_current_summary_uses_day_and_night_condition_icons_from_the_shared_presenter(): void
    {
        $today = CarbonImmutable::now('America/New_York')->startOfDay();
        $this->storeObservation(CarbonImmutable::now('UTC')->subMinutes(2), ['temperature' => 70.0]);
        $this->storeForecastDay($today, ['weather_code' => 2]);

        $day = $this->presenter()->currentSummary($today->setTime(12, 0)->utc());
        $night = $this->presenter()->currentSummary($today->setTime(23, 0)->utc());

        $this->assertSame('partly-cloudy-day', $day['condition']['icon']);
        $this->assertSame('partly-cloudy-night', $night['condition']['icon']);
        $this->assertContains(
            $day['summary']['message'],
            app(ConditionPhraseLibrary::class)->phrasesFor($day['summary']['category'], true),
        );
        $this->assertContains(
            $night['summary']['message'],
            app(ConditionPhraseLibrary::class)->phrasesFor($night['summary']['category'], false),
        );
    }

    public function test_current_summary_prioritizes_live_station_rain_for_the_ecosystem_atmosphere(): void
    {
        $now = CarbonImmutable::now('UTC');
        $this->storeObservation($now->subMinutes(2), ['temperature' => 70.0, 'rain_hourly' => 0.35, 'solar_radiation' => 28.0]);
        $this->storeForecastDay($now->setTimezone('America/New_York')->startOfDay(), ['weather_code' => 0]);

        $summary = $this->presenter()->currentSummary($now);

        $this->assertSame('heavy-rain', $summary['atmosphere']['kind']);
        $this->assertSame(0.8, $summary['atmosphere']['severity']);
        $this->assertSame(0.35, $summary['atmosphere']['rain_rate']);
        $this->assertSame(28.0, $summary['atmosphere']['solar_radiation']);
    }

    public function test_sparklines_expose_geometry_for_each_series(): void
    {
        $now = CarbonImmutable::parse('2026-07-21T17:00:00Z');
        // Two points an hour apart so a line (not just a dot) is produced.
        $this->storeObservation($now->subMinutes(60), ['temperature' => 70.0, 'pressure_relative' => 30.0, 'humidity' => 55, 'wind_speed' => 3.0]);
        $this->storeObservation($now->subMinutes(5), ['temperature' => 78.0, 'pressure_relative' => 29.9, 'humidity' => 60, 'wind_speed' => 6.0]);

        $vm = $this->presenter()->present($now);

        foreach (['temperature', 'pressure', 'humidity', 'wind'] as $series) {
            $this->assertArrayHasKey('path', $vm['sparklines'][$series]);
            $this->assertArrayHasKey('view_box', $vm['sparklines'][$series]);
            $this->assertCount(2, $vm['sparklines'][$series]['points']);
        }
    }
}
