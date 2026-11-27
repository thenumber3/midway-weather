<?php

namespace App\Weather\Configuration;

use App\Weather\Presentation\Atmosphere;
use App\Weather\Presentation\SensorTiles;
use App\Weather\Support\ConditionPhraseLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class WeatherSettings
{
    private ?array $draft = null;

    public function useDraft(?array $draft): void
    {
        $this->draft = $draft;
        config(['weather.admin_preview' => $draft !== null]);
    }

    public static function tiles(): array
    {
        return array_replace([
            'outdoor' => ['label' => 'Outdoor conditions', 'sizes' => ['standard', 'wide'], 'region' => 'main'],
            'wind' => ['view' => 'display.tiles.wind', 'label' => 'Wind', 'sizes' => ['standard', 'wide'], 'region' => 'metrics'],
            'rain' => ['view' => 'display.tiles.rain', 'label' => 'Rain', 'sizes' => ['standard', 'wide'], 'region' => 'metrics'],
            'humidity' => ['view' => 'display.tiles.humidity', 'label' => 'Humidity', 'sizes' => ['standard'], 'region' => 'metrics'],
            'pressure' => ['view' => 'display.tiles.pressure', 'label' => 'Pressure', 'sizes' => ['standard'], 'region' => 'metrics'],
            'uv' => ['view' => 'display.tiles.uv', 'label' => 'UV index', 'sizes' => ['standard'], 'region' => 'metrics'],
            'solar' => ['view' => 'display.tiles.solar', 'label' => 'Solar radiation', 'sizes' => ['standard'], 'region' => 'metrics'],
            'indoor' => ['view' => 'display.tiles.indoor', 'label' => 'Indoor conditions', 'sizes' => ['standard', 'wide'], 'region' => 'metrics'],
            'battery' => ['view' => 'display.tiles.battery', 'label' => 'Station batteries', 'sizes' => ['standard'], 'region' => 'metrics'],
            'map' => ['label' => 'Radar map', 'sizes' => ['standard', 'wide'], 'region' => 'main'],
            'forecast' => ['label' => 'Forecast', 'sizes' => ['standard'], 'region' => 'forecast'],
            'pool' => ['view' => 'display.tiles.sensor', 'label' => 'Pool temperature', 'sizes' => ['standard', 'wide'], 'region' => 'metrics', 'integration' => 'Select a station and temperature measurement'],
        ], app(SensorTiles::class)->definitions());
    }

    public static function identityIcons(): array
    {
        return [
            'dynamic' => ['label' => 'Dynamic weather / time of day', 'symbol' => null],
            'sun' => ['label' => 'Sun', 'symbol' => 'wi-clear-day'],
            'moon' => ['label' => 'Moon', 'symbol' => 'wi-clear-night'],
            'partly-cloudy' => ['label' => 'Partly cloudy', 'symbol' => 'wi-partly-cloudy-day'],
            'cloudy' => ['label' => 'Cloudy', 'symbol' => 'wi-cloudy'],
            'rain' => ['label' => 'Rain', 'symbol' => 'wi-rain'],
            'thunderstorm' => ['label' => 'Thunderstorm', 'symbol' => 'wi-thunderstorm'],
            'snow' => ['label' => 'Snow', 'symbol' => 'wi-snow'],
            'wind' => ['label' => 'Wind', 'symbol' => 'ui-wind'],
            'thermometer' => ['label' => 'Thermometer', 'symbol' => 'ui-thermometer'],
        ];
    }

    public static function contexts(): array
    {
        return array_values(array_unique(array_merge((new ConditionPhraseLibrary)->categories(), ['general', 'clear', 'cloudy', 'rain', 'storms', 'hot', 'cold', 'humid', 'morning', 'afternoon', 'evening', 'night'])));
    }

    public static function themes(): array
    {
        return config('weather_themes');
    }

    private function identityDefaults(): array
    {
        return ['title' => 'Midway Weather', 'subtitle' => (string) config('weather.location_name'), 'icon' => 'dynamic'];
    }

    public function defaults(): array
    {
        return ['identity' => $this->identityDefaults(),
            'basemap' => ['provider' => 'environment', 'authentication' => 'domain'],
            'tiles' => array_map(fn ($id) => ['id' => $id, 'enabled' => $id !== 'pool' && ! str_starts_with($id, 'sensor-'), 'size' => in_array($id, ['wind', 'rain', 'indoor']) ? 'wide' : 'standard'], array_keys(self::tiles())),
            'map' => ['location' => (string) config('weather.location_name'), 'zip' => '', 'latitude' => (float) config('weather.latitude'), 'longitude' => (float) config('weather.longitude'), 'zoom' => (int) config('weather.radar.zoom'), 'offset_lat' => 0, 'offset_lon' => 0, 'radar' => (bool) config('weather.radar.enabled')],
            'display' => ['edge_padding' => 22, 'bottom_padding' => 22, 'clock' => true, 'date' => true],
            'theme' => ['atmosphere' => Atmosphere::defaults(), 'selection' => 'automatic', 'schedules' => [['theme' => 'october-fall', ...self::themes()['october-fall']['default_range']]]],
            'thresholds' => ['hot' => 85, 'cold' => 45, 'humidity' => 80, 'wind' => 20, 'heavy_rain' => 0.3],
            'quips' => (new ConditionPhraseLibrary)->editablePhrases()];
    }

    public function read(): array
    {
        if ($this->draft !== null) {
            return ['revision' => -1, 'values' => $this->draft];
        }
        $row = Schema::hasTable('weather_settings') ? DB::table('weather_settings')->find(1) : null;

        $values = $row ? json_decode($row->configuration, true, 512, JSON_THROW_ON_ERROR) : $this->defaults();
        $values['theme']['atmosphere'] = array_replace_recursive(Atmosphere::defaults(), $values['theme']['atmosphere'] ?? []);
        $values['identity'] = array_replace($this->identityDefaults(), $values['identity'] ?? []);
        $map = Schema::hasTable('weather_map_settings') ? DB::table('weather_map_settings')->find(1) : null;
        $values['basemap'] = ['provider' => $map?->provider ?? 'environment', 'authentication' => $map?->authentication ?? 'domain'];
        $registry = self::tiles();
        $values['tiles'] = array_values(array_filter($values['tiles'], fn ($tile) => isset($registry[$tile['id']])));
        $known = array_column($values['tiles'], 'id');
        foreach (array_keys($registry) as $id) {
            if (! in_array($id, $known, true)) {
                $values['tiles'][] = ['id' => $id, 'enabled' => false, 'size' => 'standard'];
            }
        }

        return ['revision' => $row?->revision ?? 0, 'values' => $values];
    }

    public function save(array $values, int $revision, ?array $mapCredential = null): array
    {
        DB::transaction(function () use ($values, $revision, $mapCredential) {
            // Keep the legacy settings JSON readable/editable by the previous release.
            $configuration = $values;
            $defaults = $this->defaults();
            unset($configuration['basemap'], $defaults['basemap']);
            DB::table('weather_settings')->insertOrIgnore(['id' => 1, 'revision' => 0, 'configuration' => json_encode($defaults), 'created_at' => now(), 'updated_at' => now()]);
            $updated = DB::table('weather_settings')->where('id', 1)->where('revision', $revision)->update(['configuration' => json_encode($configuration, JSON_THROW_ON_ERROR), 'revision' => $revision + 1, 'updated_at' => now()]);
            if (! $updated) {
                throw ValidationException::withMessages(['revision' => 'Settings changed in another session. Reload before saving.']);
            }
            DB::table('weather_map_settings')->updateOrInsert(['id' => 1], [...($values['basemap'] ?? ['provider' => 'environment', 'authentication' => 'domain']), 'created_at' => now(), 'updated_at' => now()]);
            app(Basemap::class)->saveCredential($mapCredential);
        });
        foreach (['display.weather.payload', 'weather.condition-summary.current', 'weather.condition-summary.history'] as $key) {
            cache()->forget($key);
        }

        return $this->read();
    }

    public function apply(): void
    {
        $v = $this->read()['values'];
        config(['weather.location_name' => $v['map']['location'], 'weather.latitude' => $v['map']['latitude'], 'weather.longitude' => $v['map']['longitude'], 'weather.radar.zoom' => $v['map']['zoom'], 'weather.radar.enabled' => $v['map']['radar'], 'weather.thresholds' => $v['thresholds']]);
    }

    public function activeTheme(array $v): string
    {
        if ($v['theme']['selection'] !== 'automatic') {
            return $v['theme']['selection'];
        }
        $date = now(config('weather.timezone'))->format('m-d');
        foreach ($v['theme']['schedules'] as $s) {
            $in = $s['start'] <= $s['end'] ? $date >= $s['start'] && $date <= $s['end'] : $date >= $s['start'] || $date <= $s['end'];
            if ($in) {
                return $s['theme'];
            }
        }

        return 'default';
    }
}
