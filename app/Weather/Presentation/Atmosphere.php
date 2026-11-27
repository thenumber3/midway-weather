<?php

declare(strict_types=1);

namespace App\Weather\Presentation;

use App\Models\ForecastDay;
use App\Models\Observation;
use App\Weather\Support\AtmosphereModifier;
use Carbon\CarbonImmutable;

/** Inputs for the dashboard scene, independent of the public weather contracts. */
final class Atmosphere
{
    public const INTENSITIES = ['off', 'subtle', 'balanced', 'immersive'];

    public const EFFECTS = [
        'precipitation' => 'Rain, snow & droplets',
        'clouds' => 'Clouds & fog',
        'light' => 'Sunlight, twilight & stars',
        'lightning' => 'Storm illumination',
        'temperature' => 'Frost & heat shimmer',
        'wind' => 'Wind-driven movement',
        'icons' => 'Condition icon motion',
        'forecast' => 'Forecast card scenes',
    ];

    public static function defaults(): array
    {
        return [
            'intensity' => 'immersive',
            'effects' => array_fill_keys(array_keys(self::EFFECTS), true),
            'respect_reduced_motion' => true,
        ];
    }

    public static function scene(
        ?Observation $live,
        ?ForecastDay $forecast,
        CarbonImmutable $local,
        array $settings,
    ): array {
        $code = $forecast?->weather_code;
        $rain = $live?->rain_hourly;
        // Daily codes describe the whole day. A dry gauge takes precedence
        // over forecast liquid precipitation. Use station temperature to rule out implausible snow, and wind alone cannot prove thunder.
        $snow = in_array($code, [71, 73, 75, 77, 85, 86], true);
        if ($snow && ($live?->temperature ?? 0) > ($rain > 0 ? 32 : 36)) {
            $code = null;
        } elseif (($rain !== null && $rain <= 0 && $code >= 51 && ! $snow)
            || ($rain > 0 && in_array($code, [45, 48], true))) {
            $code = null;
        }

        $solar = self::solar($local, $forecast);
        $sunIsHigh = $solar['sunrise'] !== null && $solar['sunset'] !== null
            && $local->timestamp > $solar['sunrise'] + 3600 && $local->timestamp < $solar['sunset'] - 3600;
        if ($sunIsHigh && ($code === null || $code < 4)) {
            if (($live?->solar_radiation ?? 0) >= 425 && $code === 3) {
                $code = 2;
            } elseif ($live?->solar_radiation !== null && $live->solar_radiation < 140 && $live->humidity >= 75) {
                $code = 3;
            }
        }

        return [
            ...AtmosphereModifier::fromCurrent($code, $rain),
            'available' => $live !== null || $code !== null,
            'estimated' => $code >= 45 && ($rain ?? 0) <= 0,
            'temperature' => $live?->temperature,
            'wind' => $live?->wind_speed_avg_10m ?? $live?->wind_speed,
            'gust' => $live?->wind_gust,
            'direction' => $live?->wind_direction,
            'rain' => $rain,
            'solar' => $solar,
            'settings' => $settings,
        ];
    }

    private static function solar(CarbonImmutable $local, ?ForecastDay $forecast): array
    {
        $day = date_sun_info($local->setTime(12, 0)->timestamp, (float) config('weather.latitude'), (float) config('weather.longitude'));
        $sunrise = $forecast?->sunrise?->timestamp ?? $day['sunrise'];
        $sunset = $forecast?->sunset?->timestamp ?? $day['sunset'];

        return [
            'now' => $local->timestamp,
            'sunrise' => is_int($sunrise) ? $sunrise : null,
            'sunset' => is_int($sunset) ? $sunset : null,
            'polar_day' => $sunrise === true,
        ];
    }
}
