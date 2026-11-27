<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\WeatherAdminSession;
use App\Models\Observation;
use App\Weather\Configuration\ConfiguredPhraseSource;
use App\Weather\Configuration\WeatherSettings;
use App\Weather\Services\ConditionSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeatherAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['weather_admin.email' => 'weather@example.test', 'weather_admin.password_hash' => password_hash('isolated-test-password', PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    private function admin(): self
    {
        return $this->withSession(['weather_auth' => ['version' => WeatherAdminSession::version(), 'started' => time(), 'seen' => time()]]);
    }

    public function test_guests_cannot_read_or_mutate_admin_but_displays_stay_public(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->getJson('/admin/diagnostics')->assertUnauthorized();
        $this->postJson('/admin/settings', [])->assertUnauthorized();
        $this->postJson('/admin/refresh')->assertUnauthorized();
        $this->postJson('/admin/preview', [])->assertUnauthorized();
        $this->get('/display/weather')->assertOk();
        $this->get('/login')->assertSee('auth-weather')->assertSee('Email address');
    }

    public function test_credentials_login_logout_and_credential_revocation(): void
    {
        $this->post('/login', ['email' => 'wrong@example.test', 'password' => 'isolated-test-password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'WEATHER@example.test', 'password' => 'isolated-test-password'])->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
        config(['weather_admin.password_hash' => password_hash('rotated-test-password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->get('/admin')->assertRedirect('/login');
        $this->admin()->post('/logout')->assertRedirect('/login');
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_unconfigured_credentials_and_expired_sessions_fail_closed(): void
    {
        config(['weather_admin.password_hash' => '']);
        $this->post('/login', ['email' => 'weather@example.test', 'password' => 'isolated-test-password'])->assertSessionHasErrors();
        config(['weather_admin.password_hash' => password_hash('isolated-test-password', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->withSession(['weather_auth' => ['version' => WeatherAdminSession::version(), 'started' => time() - 43201, 'seen' => time()]])->get('/admin')->assertRedirect('/login');
        $this->withSession(['weather_auth' => ['version' => WeatherAdminSession::version(), 'started' => time(), 'seen' => time() - 7201]])->getJson('/admin/diagnostics')->assertUnauthorized();
    }

    public function test_configuration_persists_and_live_display_consumes_it(): void
    {
        $s = app(WeatherSettings::class);
        $v = $s->defaults();
        $v['tiles'][1]['enabled'] = false;
        $v['map']['zoom'] = 7;
        $v['map']['radar'] = false;
        $v['display']['clock'] = false;
        $v['theme']['selection'] = 'default';
        $this->admin()->postJson('/admin/settings', ['revision' => 0, 'values' => $v])->assertOk()->assertJsonPath('revision', 1);
        $this->assertSame($v['tiles'], $s->read()['values']['tiles']);
        $this->getJson('/api/display/weather')->assertJsonPath('meta.map_zoom', 7)->assertJsonPath('meta.radar.enabled', false)->assertJsonPath('configuration.values.tiles.1.enabled', false)->assertJsonPath('seasonal_theme', 'default');
        $this->postJson('/admin/settings', ['revision' => 0, 'values' => $v])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame(1, $s->read()['revision']);
    }

    public function test_atmosphere_preferences_survive_legacy_saves_and_never_accept_simulations(): void
    {
        $settings = app(WeatherSettings::class);
        $values = $settings->defaults();
        $this->getJson('/api/display/weather')->assertJsonPath('theme.scene.settings.intensity', 'immersive');
        $values['theme']['atmosphere']['intensity'] = 'subtle';
        $values['theme']['atmosphere']['effects']['lightning'] = false;
        $this->admin()->postJson('/admin/settings', ['revision' => 0, 'values' => $values])->assertOk();
        $this->getJson('/api/display/weather')
            ->assertJsonPath('theme.scene.settings.intensity', 'subtle')
            ->assertJsonPath('theme.scene.settings.effects.lightning', false);

        $legacy = $values;
        unset($legacy['theme']['atmosphere']);
        $this->postJson('/admin/settings', ['revision' => 1, 'values' => $legacy])->assertOk();
        $this->assertSame($values['theme']['atmosphere'], $settings->read()['values']['theme']['atmosphere']);

        $values['theme']['atmosphere']['simulation'] = ['temperature' => 120];
        $this->postJson('/admin/settings', ['revision' => 2, 'values' => $values])
            ->assertUnprocessable()->assertJsonValidationErrors('theme.atmosphere');
        $this->assertSame(2, $settings->read()['revision']);
    }

    public function test_invalid_payloads_cannot_replace_configuration(): void
    {
        $v = app(WeatherSettings::class)->defaults();
        $v['tiles'][1]['id'] = 'outdoor';
        $v['map']['latitude'] = 999;
        $v['thresholds']['cold'] = 100;
        $this->admin()->postJson('/admin/settings', ['revision' => 0, 'values' => $v])->assertUnprocessable()->assertJsonValidationErrors(['tiles.1.id', 'map.latitude', 'thresholds.cold']);
        $this->assertSame(0, app(WeatherSettings::class)->read()['revision']);
        $v = app(WeatherSettings::class)->defaults();
        $v['tiles'][3]['size'] = 'wide';
        $this->postJson('/admin/settings', ['revision' => 0, 'values' => $v])->assertUnprocessable()->assertJsonValidationErrors('tiles');
    }

    public function test_quips_edit_disable_delete_and_contexts_drive_selection(): void
    {
        $s = app(WeatherSettings::class);
        $v = $s->defaults();
        $v['quips'] = [['id' => 'custom.rain', 'text' => 'Rain from admin', 'enabled' => true, 'contexts' => ['rain'], 'daylight_only' => false], ['id' => 'custom.default', 'text' => 'General from admin', 'enabled' => true, 'contexts' => ['general'], 'daylight_only' => false]];
        $this->admin()->postJson('/admin/settings', ['revision' => 0, 'values' => $v])->assertOk();
        $service = app(ConditionSummaryService::class);
        $o = new Observation(['temperature' => 65, 'rain_hourly' => 0.1]);
        $this->assertSame('Rain from admin', $service->summarize($o));
        $v['quips'][0]['text'] = 'Edited rain';
        $s->save($v, 1);
        $this->assertSame('Edited rain', $service->summarize($o));
        $v['quips'][0]['enabled'] = false;
        $s->save($v, 2);
        $this->assertSame('General from admin', $service->summarize($o));
        $v['quips'] = [];
        $s->save($v, 3);
        $this->assertNotSame('Rain from admin', $service->summarize($o));
        $this->travelTo(now()->setTimezone(config('weather.timezone'))->setTime(9, 0));
        $v['quips'] = [['id' => 'morning', 'text' => 'Morning weather', 'enabled' => true, 'contexts' => ['morning'], 'daylight_only' => false]];
        $s->save($v, 4);
        $this->assertSame(['morning' => 'Morning weather'], app(ConfiguredPhraseSource::class)->phrasesFor('pleasant'));
    }

    public function test_thresholds_change_matching_and_preview_does_not_save(): void
    {
        $s = app(WeatherSettings::class);
        $v = $s->defaults();
        $v['thresholds']['wind'] = 12;
        $v['map']['zoom'] = 6;
        $v['map']['offset_lat'] = 1;
        $this->admin()->postJson('/admin/preview', ['values' => $v])->assertOk()->assertSee('"revision":-1', false)->assertDontSee('id="pwa-client"', false);
        $this->assertSame(0, $s->read()['revision']);
        $s->save($v, 0);
        $s->apply();
        $this->assertSame('windy', app(ConditionSummaryService::class)->categoryFor(new Observation(['temperature' => 65, 'wind_speed' => 14])));
    }

    public function test_map_draft_preview_preserves_positioning_and_shared_attribution_without_saving(): void
    {
        $settings = app(WeatherSettings::class);
        $settings->save($settings->defaults(), 0);
        $original = $settings->read();
        $draft = $original['values'];
        $draft['map'] = array_replace($draft['map'], [
            'latitude' => 34.0, 'longitude' => -84.0, 'zoom' => 12,
            'offset_lat' => 0.25, 'offset_lon' => -0.5, 'radar' => false,
        ]);

        $this->admin()->postJson('/admin/preview', ['values' => $draft])
            ->assertOk()
            ->assertSee('data-lat="34"', false)
            ->assertSee('data-lon="-84"', false)
            ->assertSee('"offset_lat":0.25', false)
            ->assertSee('"offset_lon":-0.5', false)
            ->assertSee('data-zoom="12"', false)
            ->assertSee('data-radar-enabled="false"', false)
            ->assertDontSee('href="https://stadiamaps.com/attribution/"', false)
            ->assertSee('data-basemap-provider="openstreetmap"', false)
            ->assertSee('href="https://www.openstreetmap.org/copyright"', false)
            ->assertSee('display/app.js?v=16', false)
            ->assertDontSee('id="pwa-client"', false);

        $this->assertSame($original, $settings->read());
    }

    public function test_stadia_preview_keeps_provider_configuration_and_private_response_headers(): void
    {
        config(['weather.basemap.provider' => 'stadia', 'weather.basemap.stadia_api_key' => 'preview-owner-key']);
        $settings = app(WeatherSettings::class);
        $before = $settings->read();
        $this->admin()->postJson('/admin/preview', ['values' => $before['values']])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('data-basemap-provider="stadia"', false)
            ->assertSee('?api_key=preview-owner-key', false);
        $this->assertSame($before, $settings->read());
    }

    public function test_zip_lookup_safe_payload_and_failure(): void
    {
        Http::fake(['api.zippopotam.us/*' => Http::sequence()->push(['places' => [['place name' => 'Powder Springs', 'state' => 'Georgia', 'latitude' => '33.85', 'longitude' => '-84.67']]])->push([], 404)]);
        $this->admin()->postJson('/admin/locate', ['zip' => '30127'])->assertOk()->assertJsonPath('latitude', 33.85);
        $this->postJson('/admin/locate', ['zip' => 'https://localhost'])->assertUnprocessable();
        $this->postJson('/admin/locate', ['zip' => '00000'])->assertUnprocessable();
    }

    public function test_automatic_date_ranges_including_year_wrap(): void
    {
        $s = app(WeatherSettings::class);
        $v = $s->defaults();
        $v['theme']['schedules'] = [['theme' => 'default', 'start' => '12-01', 'end' => '02-15']];
        $this->travelTo(now()->setDate(2026, 12, 15));
        $this->assertSame('default', $s->activeTheme($v));
        $this->admin()->postJson('/admin/settings', ['revision' => 0, 'values' => $v])->assertOk();
        $v['theme']['schedules'][0]['start'] = '02-30';
        $this->postJson('/admin/settings', ['revision' => 1, 'values' => $v])->assertUnprocessable();
    }

    public function test_diagnostics_are_authenticated_and_do_not_expose_provider_secrets(): void
    {
        config(['weather.ambient.api_key' => 'sentinel-private-key']);
        $this->admin()->getJson('/admin/diagnostics')->assertOk()->assertJsonPath('configuration_revision', 0)->assertJsonPath('sources.2.status', 'not_configured')->assertDontSee('sentinel-private-key');
    }

    public function test_named_theme_registry_selection_and_draft_quips_without_cache_changes(): void
    {
        config(['weather_themes.autumn-test' => ['name' => 'Test season', 'description' => 'Isolated test theme', 'stylesheet' => 'display/test-season.css']]);
        $s = app(WeatherSettings::class);
        $v = $s->defaults();
        $v['theme']['selection'] = 'autumn-test';
        $v['quips'] = [['id' => 'preview.general', 'text' => 'A draft-only weather message', 'enabled' => true, 'contexts' => ['general'], 'daylight_only' => false]];
        Observation::query()->create(['observed_at' => now(), 'temperature' => 70]);
        $sentinel = ['id' => 'live.id', 'category' => 'fallback', 'selected_at' => time(), 'is_daytime' => true];
        cache()->put('weather.condition-summary.current', $sentinel);
        $this->admin()->postJson('/admin/preview', ['values' => $v])->assertOk()->assertSee('A draft-only weather message')->assertSee('autumn-test')->assertSee('test-season.css');
        $this->assertSame($sentinel, cache()->get('weather.condition-summary.current'));
        $this->assertSame(0, $s->read()['revision']);
        $this->postJson('/admin/settings', ['revision' => 0, 'values' => $v])->assertOk();
        $this->getJson('/api/display/weather')->assertJsonPath('seasonal_theme', 'autumn-test')->assertJsonPath('summary', 'A draft-only weather message');
        $v['theme']['selection'] = 'automatic';
        $v['theme']['schedules'] = [['theme' => 'autumn-test', 'start' => '12-01', 'end' => '02-15']];
        $this->travelTo(now()->setDate(2026, 12, 15));
        $this->assertSame('autumn-test', $s->activeTheme($v));
        $this->travelTo(now()->setDate(2026, 6, 15));
        $this->assertSame('default', $s->activeTheme($v));
    }

    public function test_numeric_boolean_forms_are_normalized_for_the_browser(): void
    {
        $v = app(WeatherSettings::class)->defaults();
        $v['tiles'][0]['enabled'] = '0';
        $v['display']['clock'] = '0';
        $v['map']['radar'] = '0';
        $this->admin()->postJson('/admin/settings', ['revision' => 0, 'values' => $v])->assertOk()->assertJsonPath('values.tiles.0.enabled', false)->assertJsonPath('values.display.clock', false)->assertJsonPath('values.map.radar', false);
    }
}
