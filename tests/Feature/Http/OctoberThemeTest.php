<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\WeatherAdminSession;
use App\Models\ForecastDay;
use App\Models\Observation;
use App\Weather\Configuration\WeatherSettings;
use App\Weather\Services\DisplayPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesWeatherData;
use Tests\TestCase;

class OctoberThemeTest extends TestCase
{
    use MakesWeatherData;
    use RefreshDatabase;

    public function test_admin_switching_and_automatic_boundaries_preserve_weather_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 14:00', 'America/New_York'));
        config(['weather_admin.email' => 'weather@example.test', 'weather_admin.password_hash' => password_hash('test', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->withSession(['weather_auth' => ['version' => WeatherAdminSession::version(), 'started' => time(), 'seen' => time()]]);
        $this->storeObservation(now()->toImmutable(), ['temperature' => 82]);
        $settings = app(WeatherSettings::class);
        $values = $settings->defaults();
        $this->assertSame('october-fall', $settings->activeTheme($values));
        $this->get('/admin')->assertSee('October / Fall');
        $before = app(DisplayPresenter::class)->present();
        $values['theme']['selection'] = 'default';
        $this->postJson('/admin/settings', ['revision' => 0, 'values' => $values])->assertOk();
        $this->get('/')->assertSee('data-seasonal-theme="default"', false)->assertDontSee('id="seasonal-theme-stylesheet"', false);
        $after = app(DisplayPresenter::class)->present();
        foreach (['observation', 'forecast', 'sparklines', 'theme'] as $key) {
            $this->assertSame($before[$key], $after[$key]);
        }
        $values['theme']['selection'] = 'october-fall';
        $this->postJson('/admin/settings', ['revision' => 1, 'values' => $values])->assertOk();
        $this->get('/')->assertSee('data-seasonal-theme="october-fall"', false)->assertSee('id="seasonal-theme-stylesheet"', false)->assertSee('october-fall.css?v=2');
        $values['theme']['selection'] = 'automatic';
        foreach (['2026-09-30' => 'default', '2026-10-01' => 'october-fall', '2026-11-30' => 'october-fall', '2026-12-01' => 'default'] as $date => $theme) {
            $this->travelTo(CarbonImmutable::parse($date.' 12:00', 'America/New_York'));
            $this->assertSame($theme, $settings->activeTheme($values));
        }
        $values['theme']['schedules'][0] = ['theme' => 'october-fall', 'start' => '09-01', 'end' => '12-15'];
        $this->assertSame('october-fall', $settings->activeTheme($values));
    }

    public function test_presentation_context_retains_weather_and_local_light_combinations(): void
    {
        foreach ([
            ['08:00', 45, 0, 'fog', 'morning', 44, 'cold'],
            ['14:00', 3, 0, 'overcast', 'afternoon', 62, 'mild'],
            ['18:00', 0, 0, 'clear', 'evening', 82, 'warm'],
            ['23:00', 0, 0, 'clear', 'night', 70, 'mild'],
            ['23:00', 0, 0.15, 'rain', 'night', 70, 'mild'],
            ['14:00', 95, 0.3, 'thunderstorm', 'afternoon', 92, 'hot'],
        ] as [$time, $code, $rate, $kind, $period, $temp, $mood]) {
            $now = CarbonImmutable::parse('2026-10-05 '.$time, 'America/New_York');
            $this->travelTo($now);
            Observation::query()->delete();
            $this->storeObservation($now, ['temperature' => $temp, 'rain_hourly' => $rate, 'wind_gust' => 5, 'solar_radiation' => 350]);
            ForecastDay::query()->delete();
            $this->storeForecastDay($now->startOfDay(), ['weather_code' => $code, 'sunrise' => $now->setTime(7, 30)->utc(), 'sunset' => $now->setTime(19, 15)->utc()]);
            $theme = app(DisplayPresenter::class)->present($now)['theme'];
            $this->assertSame($kind, $theme['atmosphere']);
            $this->assertSame($period, $theme['time_of_day']);
            $this->assertSame($mood, $theme['temperature_mood']);
        }
    }

    public function test_first_theme_range_migration_preserves_existing_choices_and_is_idempotent(): void
    {
        $settings = app(WeatherSettings::class);
        $migration = require database_path('migrations/2026_10_05_000002_seed_october_fall_theme_range.php');
        $values = $settings->defaults();
        $values['theme']['schedules'] = [];
        $settings->save($values, 0);
        $migration->up();
        $seeded = $settings->read();
        $this->assertSame($settings->defaults()['theme'], $seeded['values']['theme']);
        $this->assertSame($values['map'], $seeded['values']['map']);
        $migration->up();
        $this->assertSame($seeded, $settings->read());
        foreach ([['selection' => 'default', 'schedules' => []], ['selection' => 'automatic', 'schedules' => [['theme' => 'default', 'start' => '10-01', 'end' => '10-31']]]] as $theme) {
            $values['theme'] = $theme;
            DB::table('weather_settings')->where('id', 1)->update(['configuration' => json_encode($values)]);
            $migration->up();
            $this->assertSame([...$theme, 'atmosphere' => $settings->defaults()['theme']['atmosphere']], $settings->read()['values']['theme']);
        }
    }
}
