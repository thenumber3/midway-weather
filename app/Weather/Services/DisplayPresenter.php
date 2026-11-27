<?php

declare(strict_types=1);

namespace App\Weather\Services;

use App\Models\DailySummary;
use App\Models\ForecastDay;
use App\Models\Observation;
use App\Weather\Configuration\Basemap;
use App\Weather\Configuration\WeatherSettings;
use App\Weather\Data\Trend;
use App\Weather\Presentation\Atmosphere;
use App\Weather\Presentation\SensorTiles;
use App\Weather\Providers\PrimaryWeatherStore;
use App\Weather\Support\AtmosphereModifier;
use App\Weather\Support\Compass;
use App\Weather\Support\ConditionPhraseLibrary;
use App\Weather\Support\Sparkline;
use App\Weather\Support\TemperatureMood;
use App\Weather\Support\WeatherCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Assembles the complete display view model in one pass.
 *
 * This is the single source of truth for everything the kiosk needs: current
 * conditions, trends, today's high/low, sparklines, the forecast, station
 * health, staleness, theming, and the natural-language summary. Producing it
 * here (rather than in the controller or Blade) keeps the JSON endpoint and the
 * server-rendered page perfectly in sync and keeps logic out of templates.
 */
class DisplayPresenter
{
    public function __construct(
        private readonly ObservationRepository $observations,
        private readonly TrendService $trends,
        private readonly StationHealthService $health,
        private readonly ConditionSummaryService $summaries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(?CarbonImmutable $now = null): array
    {
        app(WeatherSettings::class)->apply();
        $now ??= CarbonImmutable::now('UTC');
        $timezone = (string) config('weather.timezone', 'America/New_York');

        $latest = $this->observations->latest();

        $trends = $this->buildTrends($latest);
        $todaySummary = $this->todaySummary($timezone);
        $todayForecast = $this->todayForecast($timezone);
        $freshness = $this->health->observationFreshness($latest, $now);
        $isDaytime = $this->isDaytime($now, $timezone, $todayForecast);

        $configuration = app(WeatherSettings::class)->read();
        $identity = $configuration['values']['identity'];
        $forecast = $this->forecastPayload($now, $timezone);
        $phrase = $this->summaries->details($latest, $trends, $todayForecast, $isDaytime);
        $scene = Atmosphere::scene(
            $freshness === StationHealthService::STALE ? null : $latest,
            $forecast['is_stale'] ? null : $todayForecast,
            $now->setTimezone($timezone),
            $configuration['values']['theme']['atmosphere'],
        );
        $stockPhrases = (new ConditionPhraseLibrary)->phrasesFor($phrase['category'], $isDaytime);
        $scene['allow_quip'] = ($stockPhrases[$phrase['phrase_id']] ?? null) === $phrase['message'];

        return [
            'configuration' => $configuration,
            'sensor_tiles' => app(SensorTiles::class)->published($configuration['values']),
            'identity' => [...$identity, 'symbol' => WeatherSettings::identityIcons()[$identity['icon']]['symbol']],
            'theme_catalog' => array_map(fn ($theme) => ['stylesheet' => ($theme['stylesheet'] ?? null) ? asset($theme['stylesheet']) : null], WeatherSettings::themes()),
            'seasonal_theme' => app(WeatherSettings::class)->activeTheme(app(WeatherSettings::class)->read()['values']),
            'meta' => $this->meta($now, $timezone, $latest),
            'observation' => $this->observationPayload($latest, $todaySummary),
            'trends' => array_map(static fn (Trend $t) => $t->toArray(), $trends),
            'summary' => $phrase['message'],
            'sparklines' => $this->sparklines(),
            'rain' => $this->rainPayload($latest, $timezone),
            'forecast' => $forecast,
            'health' => $this->healthPayload($latest, $freshness, $now),
            'theme' => [...$this->theme($latest, $now, $timezone, $todayForecast), 'scene' => $scene],
        ];
    }

    /**
     * Compact, versionable weather meaning for other Midway applications.
     * It deliberately uses the same stored observation, forecast, phrase,
     * condition, mood, and freshness services as the full weather display.
     *
     * @return array<string, mixed>
     */
    public function currentSummary(?CarbonImmutable $now = null): array
    {
        app(WeatherSettings::class)->apply();
        $now ??= CarbonImmutable::now('UTC');
        $timezone = (string) config('weather.timezone', 'America/New_York');
        $latest = $this->observations->latest();
        $trends = $this->buildTrends($latest);
        $todaySummary = $this->todaySummary($timezone);
        $todayForecast = $this->todayForecast($timezone);
        $freshness = $this->health->observationFreshness($latest, $now);
        $isDaytime = $this->isDaytime($now, $timezone, $todayForecast);

        if ($latest === null || $latest->temperature === null) {
            return [
                'available' => false,
                'freshness' => $freshness,
                'observed_at' => null,
                'error' => ['code' => 'weather_data_unavailable', 'message' => 'No valid weather observation is available yet.'],
            ];
        }

        $condition = WeatherCode::describe($todayForecast?->weather_code, $isDaytime);
        $atmosphere = AtmosphereModifier::fromCurrent($todayForecast?->weather_code, $latest->rain_hourly);
        $summary = $this->summaries->details($latest, $trends, $todayForecast, $isDaytime);
        $high = $todaySummary?->temperature_high ?? $todayForecast?->temperature_high;
        $low = $todaySummary?->temperature_low ?? $todayForecast?->temperature_low;

        return [
            'available' => true,
            'freshness' => $freshness,
            'observed_at' => $latest->observed_at->setTimezone($timezone)->toIso8601String(),
            'temperature' => (float) $latest->temperature,
            'today_high' => $high !== null ? (float) $high : null,
            'today_low' => $low !== null ? (float) $low : null,
            'condition' => ['code' => $todayForecast?->weather_code, ...$condition, 'is_daytime' => $isDaytime],
            'atmosphere' => [
                ...$atmosphere,
                'rain_rate' => $latest->rain_hourly,
                'rain_24h' => $latest->rain_24h,
                'solar_radiation' => $latest->solar_radiation,
                'humidity' => $latest->humidity,
                'wind_speed' => $latest->wind_speed,
                'wind_gust' => $latest->wind_gust,
            ],
            'mood' => TemperatureMood::fromTemperature($latest->temperature),
            'summary' => $summary,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, Trend>  $keyed
     * @return array<string, Trend>
     */
    private function buildTrends(?Observation $latest): array
    {
        if ($latest === null) {
            return [];
        }

        return [
            'temperature' => $this->trends->temperature($latest),
            'temperature_since_midnight' => $this->trends->temperatureSinceMidnight($latest),
            'pressure' => $this->trends->pressure($latest),
            'humidity' => $this->trends->humidity($latest),
            'wind' => $this->trends->wind($latest),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(CarbonImmutable $now, string $timezone, ?Observation $latest): array
    {
        return [
            'station_name' => (string) config('weather.station_name'),
            'location_name' => (string) config('weather.location_name'),
            'timezone' => $timezone,
            'latitude' => (float) config('weather.latitude'),
            'longitude' => (float) config('weather.longitude'),
            'basemap' => $this->basemap(),
            'map_zoom' => (int) config('weather.radar.zoom'),
            'radar' => [
                'enabled' => (bool) config('weather.radar.enabled'),
                'metadata_url' => (string) config('weather.radar.metadata_url'),
                'refresh_minutes' => (int) config('weather.radar.refresh_minutes'),
                'opacity' => (float) config('weather.radar.opacity'),
            ],
            'poll_seconds' => (int) config('weather.collection.browser_poll_seconds'),
            'server_time' => $now->setTimezone($timezone)->toIso8601String(),
            'has_data' => $latest !== null,
            'demo_mode' => (bool) config('weather.demo_mode'),
        ];
    }

    private function basemap(): array
    {
        $maps = app(Basemap::class);
        $selection = app(WeatherSettings::class)->read()['values']['basemap'];

        return [...$maps->resolve($selection), 'fallback' => $maps->resolve(['provider' => 'openstreetmap', 'authentication' => 'domain'])];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function observationPayload(?Observation $latest, ?DailySummary $today): ?array
    {
        if ($latest === null) {
            return null;
        }

        return [
            'observed_at' => $latest->observed_at->toIso8601String(),
            'temperature' => $latest->temperature,
            'feels_like' => $latest->feels_like,
            'dew_point' => $latest->dew_point,
            'humidity' => $latest->humidity,
            'wind' => [
                'speed' => $latest->wind_speed,
                'gust' => $latest->wind_gust,
                'direction' => $latest->wind_direction,
                'direction_abbr' => Compass::abbreviation($latest->wind_direction),
                'direction_name' => Compass::fullName($latest->wind_direction),
                'avg_2m' => $latest->wind_speed_avg_2m,
                'avg_10m' => $latest->wind_speed_avg_10m,
                'max_daily_gust' => $latest->max_daily_gust,
            ],
            'pressure' => [
                'relative' => $latest->pressure_relative,
                'absolute' => $latest->pressure_absolute,
            ],
            'uv_index' => $latest->uv_index,
            'uv_category' => $this->uvCategory($latest->uv_index),
            'solar_radiation' => $latest->solar_radiation,
            'indoor' => [
                'temperature' => $latest->indoor_temperature,
                'humidity' => $latest->indoor_humidity,
            ],
            'battery' => [
                'outdoor_ok' => $latest->battery_outdoor_ok,
                'indoor_ok' => $latest->battery_indoor_ok,
                'all_ok' => $this->batteriesAllOk($latest),
            ],
            'today' => [
                'high' => $today?->temperature_high,
                'low' => $today?->temperature_low,
            ],
        ];
    }

    private function batteriesAllOk(Observation $latest): ?bool
    {
        $flags = array_filter(
            [$latest->battery_outdoor_ok, $latest->battery_indoor_ok],
            static fn ($v) => $v !== null,
        );

        if ($flags === []) {
            return null;
        }

        return ! in_array(false, $flags, true);
    }

    private function uvCategory(?float $uv): ?string
    {
        if ($uv === null) {
            return null;
        }

        return match (true) {
            $uv < 3 => 'Low',
            $uv < 6 => 'Moderate',
            $uv < 8 => 'High',
            $uv < 11 => 'Very High',
            default => 'Extreme',
        };
    }

    private function todaySummary(string $timezone): ?DailySummary
    {
        $today = CarbonImmutable::now($timezone)->toDateString();

        // `date` columns persist as midnight datetimes, so match on the date part.
        return app(PrimaryWeatherStore::class)->summaries()->whereDate('date', $today)->first();
    }

    private function todayForecast(string $timezone): ?ForecastDay
    {
        $today = CarbonImmutable::now($timezone)->toDateString();

        return ForecastDay::query()->whereDate('date', $today)->first();
    }

    /**
     * Build the small set of sparkline series the display uses.
     *
     * Each entry carries both the raw points and pre-computed SVG geometry
     * (via App\Weather\Support\Sparkline) so the server-rendered first paint
     * and the live JS updates draw from identical math — no charting library
     * and no duplicated client-side geometry.
     *
     * @return array<string, array{
     *     points: array<int, array{t: string, v: float}>,
     *     path: string|null,
     *     area: string|null,
     *     view_box: string,
     *     min: float|null,
     *     max: float|null,
     *     first: float|null,
     *     last: float|null,
     *     count: int
     * }>
     */
    private function sparklines(): array
    {
        $hours = (int) config('weather.display.sparkline_hours', 24);
        $points = (int) config('weather.display.sparkline_points', 48);

        $recent = $this->observations->recent($hours, $points);

        return [
            'temperature' => $this->sparkline($recent, 'temperature'),
            'pressure' => $this->sparkline($recent, 'pressure_relative'),
            'humidity' => $this->sparkline($recent, 'humidity'),
            'wind' => $this->sparkline($recent, 'wind_speed'),
        ];
    }

    /**
     * @param  Collection<int, Observation>  $observations
     * @return array<string, mixed>
     */
    private function sparkline(Collection $observations, string $attribute): array
    {
        $points = $this->series($observations, $attribute);

        return ['points' => $points] + Sparkline::build($points);
    }

    /**
     * @param  Collection<int, Observation>  $observations
     * @return array<int, array{t: string, v: float}>
     */
    private function series(Collection $observations, string $attribute): array
    {
        return $observations
            ->filter(fn (Observation $o) => $o->{$attribute} !== null)
            ->map(fn (Observation $o) => [
                't' => $o->observed_at->toIso8601String(),
                'v' => round((float) $o->{$attribute}, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Rain figures plus a comparison of today vs. the recent daily average.
     *
     * @return array<string, mixed>
     */
    private function rainPayload(?Observation $latest, string $timezone): array
    {
        $todayRain = $latest?->rain_daily;

        // Average of the previous days' totals (excluding today) for context.
        $today = CarbonImmutable::now($timezone)->toDateString();
        $recent = app(PrimaryWeatherStore::class)->summaries()
            ->whereDate('date', '<', $today)
            ->orderByDesc('date')
            ->limit(7)
            ->get();

        $priorTotals = $recent->pluck('total_rain')->filter(fn ($v) => $v !== null);
        $recentAverage = $priorTotals->isEmpty() ? null : round((float) $priorTotals->avg(), 2);

        return [
            'hourly' => $latest?->rain_hourly,
            'daily' => $todayRain,
            'rain_24h' => $latest?->rain_24h,
            'weekly' => $latest?->rain_weekly,
            'monthly' => $latest?->rain_monthly,
            'event' => $latest?->rain_event,
            'recent_daily_average' => $recentAverage,
            'is_raining' => ($latest?->rain_hourly ?? 0.0) > 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forecastPayload(CarbonImmutable $now, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->toDateString();

        $days = ForecastDay::query()->upcoming($today)->get();

        $fetchedAt = $days->max('fetched_at');
        $fetchedAtImmutable = $fetchedAt !== null ? CarbonImmutable::parse($fetchedAt) : null;

        $items = $days->map(function (ForecastDay $day) use ($today, $timezone) {
            // `date` is a pure calendar date (stored at midnight); compare and
            // format on the date itself without any timezone conversion, which
            // would otherwise shift the day backwards.
            $dateString = $day->date->toDateString();
            $isToday = $dateString === $today;

            return [
                'date' => $dateString,
                'day_label' => $isToday ? 'Today' : $day->date->format('D'),
                'day_full' => $day->date->format('l'),
                'is_today' => $isToday,
                'weather_code' => $day->weather_code,
                'condition' => WeatherCode::label($day->weather_code),
                'icon' => WeatherCode::iconKey($day->weather_code, true),
                'high' => $day->temperature_high,
                'low' => $day->temperature_low,
                'precipitation_probability' => $day->precipitation_probability,
                'precipitation_amount' => $day->precipitation_amount,
                'wind_speed_max' => $day->wind_speed_max,
                'wind_gust_max' => $day->wind_gust_max,
                'sunrise' => $day->sunrise?->setTimezone($timezone)->format('g:i A'),
                'sunset' => $day->sunset?->setTimezone($timezone)->format('g:i A'),
            ];
        })->values()->all();

        return [
            'source' => 'Open-Meteo forecast',
            'fetched_at' => $fetchedAtImmutable?->setTimezone($timezone)->toIso8601String(),
            'is_stale' => $this->health->forecastIsStale($fetchedAtImmutable, $now),
            'days' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthPayload(?Observation $latest, string $freshness, CarbonImmutable $now): array
    {
        return [
            'freshness' => $freshness,
            'observation_age_minutes' => $this->health->observationAgeMinutes($latest, $now),
            'is_stale' => $freshness === StationHealthService::STALE,
            'ambient' => $this->health->statusSummary('ambient'),
            'forecast' => $this->health->statusSummary('forecast'),
        ];
    }

    /**
     * Derive a lightweight theme key from conditions and time of day. The UI
     * uses this only to shift accent colors/background — never to hide data.
     *
     * @return array<string, mixed>
     */
    private function theme(
        ?Observation $latest,
        CarbonImmutable $now,
        string $timezone,
        ?ForecastDay $todayForecast,
    ): array {
        $isDay = $this->isDaytime($now, $timezone, $todayForecast);

        $key = 'clear-day';

        if ($latest === null) {
            $key = $isDay ? 'clear-day' : 'clear-night';
        } elseif (($latest->rain_hourly ?? 0.0) > 0.0) {
            $key = 'rain';
        } elseif (! $isDay) {
            $key = 'clear-night';
        } elseif ($latest->solar_radiation !== null && $latest->solar_radiation < 120 && $latest->humidity !== null && $latest->humidity > 85) {
            // Low light + high humidity during the day reads as overcast/stormy.
            $key = 'overcast';
        } elseif ($latest->temperature !== null && $latest->temperature >= 90) {
            $key = 'hot';
        } else {
            $key = 'clear-day';
        }

        // Additive presentation context uses the same atmosphere precedence as
        // the summary contract. The original Weather key and icons stay intact.
        $atmosphere = AtmosphereModifier::fromCurrent($todayForecast?->weather_code, $latest?->rain_hourly)['kind'];
        if (($latest?->rain_hourly ?? 0) >= 0.1 && ($latest?->wind_gust ?? 0) >= 25) {
            $atmosphere = 'thunderstorm';
        } elseif ($atmosphere === 'clear' && $key === 'overcast') {
            $atmosphere = 'overcast';
        }
        $hour = (int) $now->setTimezone($timezone)->format('G');
        $sunset = $todayForecast?->sunset ?? $now->setTimezone($timezone)->setTime(20, 0);
        $period = $isDay ? ($hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening'))
            : ($now->greaterThan($sunset) && $now->lessThan($sunset->copy()->addMinutes(90)) ? 'evening' : 'night');

        return [
            'atmosphere' => $atmosphere,
            'time_of_day' => $period,
            'key' => $key,
            'is_day' => $isDay,
            'temperature_mood' => TemperatureMood::fromTemperature($latest?->temperature),
        ];
    }

    private function isDaytime(CarbonImmutable $now, string $timezone, ?ForecastDay $forecast): bool
    {
        // Prefer the actual forecast sunrise/sunset for today when available.
        if ($forecast?->sunrise !== null && $forecast?->sunset !== null) {
            return $now->betweenIncluded($forecast->sunrise, $forecast->sunset);
        }

        // Fallback heuristic: 6am–8pm local.
        $localHour = (int) $now->setTimezone($timezone)->format('G');

        return $localHour >= 6 && $localHour < 20;
    }
}
