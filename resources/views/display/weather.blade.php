@php
    use App\Weather\Support\Format;

    /**
     * Standalone kiosk display (1920x1080, no scroll, readable from 10-15 ft).
     *
     * Server-rendered from the DisplayPresenter view model so real data is on
     * screen at first paint. public/display/app.js then polls the local JSON
     * endpoint and updates the same DOM in place. Every dynamic value carries a
     * `data-bind="dotted.path"` (+ optional `data-fmt`) hook the JS reuses, so
     * the markup here and the live updates stay in lockstep. No build step: the
     * CSS/JS are plain static files under public/display.
     */
    $get = fn (string $path, $default = null) => data_get($display, $path, $default);

    $themeKey = $get('theme.key', 'clear-day');
    $temperatureMood = $temperatureMoodPreview ?? $get('theme.temperature_mood', 'mild');
    $forecastDays = $get('forecast.days', []);
    $forecastDayCount = is_countable($forecastDays) ? count($forecastDays) : 0;
    $forecastTitle = $forecastDayCount > 0 ? "{$forecastDayCount}-Day Forecast" : 'Forecast';
    $phoneForecastDayCount = min(4, $forecastDayCount);
    $phoneForecastTitle = $phoneForecastDayCount > 0 ? "{$phoneForecastDayCount}-Day Forecast" : 'Forecast';
    $pwaPreview = app()->environment('local')
        && in_array(request()->query('pwa_preview'), ['chromium', 'ios', 'standalone'], true)
            ? request()->query('pwa_preview')
            : '';

    // Hero condition icon derived from the coarse theme key (Ambient sends raw
    // sensor data, not a coded condition). Mirrored in app.js (heroIcon()).
    $heroIcon = match ($themeKey) {
        'clear-night' => 'wi-clear-night',
        'rain' => 'wi-rain',
        'overcast' => 'wi-cloudy',
        'hot' => 'wi-clear-day',
        default => 'wi-clear-day',
    };
@endphp
<!DOCTYPE html>
<html lang="en"
      data-theme="{{ $themeKey }}"
      data-seasonal-theme="{{ $get('seasonal_theme', 'default') }}"
      data-atmosphere="{{ $get('theme.atmosphere', 'clear') }}"
      data-time-of-day="{{ $get('theme.time_of_day', 'afternoon') }}"
      data-temperature-mood="{{ $temperatureMood }}"
      @if ($temperatureMoodPreview) data-temperature-preview="{{ $temperatureMoodPreview }}" @endif>
<head>
    <meta charset="utf-8">
    {{-- device-width lets the mobile media queries match on phones/tablets while
         rendering identically on the 1920-wide kiosk (its device width IS 1920).
         viewport-fit=cover enables env(safe-area-inset-*) on notched phones.
         No user-scalable/maximum-scale — pinch-zoom must stay enabled (WCAG 1.4.4). --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark light">
    <meta name="theme-color" content="#04385c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Midway">
    <title>{{ $get('identity.title', 'Midway Weather') }}</title>

    <link rel="manifest" href="{{ route('pwa.manifest') }}">
    <link rel="icon" href="{{ asset('icons/midway-weather.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('icons/favicon-32.png') }}" type="image/png" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}" sizes="180x180">
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('display/app.css') }}?v=21">

    @if ($themeStylesheet = $get('theme_catalog.'.$get('seasonal_theme', 'default').'.stylesheet'))
    <link id="seasonal-theme-stylesheet" rel="stylesheet" href="{{ $themeStylesheet }}">
    @endif

    <link rel="stylesheet" href="{{ asset('display/atmosphere.css') }}?v=1">
    <script defer src="{{ asset('display/atmosphere.js') }}?v=1"></script>

    {{-- The whole view model, embedded for the JS to hydrate its state without
         an extra round trip on first load. This is the SAME data the /api route
         serves; the browser never contacts Ambient or Open-Meteo directly. --}}
    <script id="bootstrap-data" type="application/json">{!! json_encode($display, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>

    <script defer src="{{ asset('vendor/leaflet/leaflet.js') }}?v=2"></script>
    <script defer src="{{ asset('display/app.js') }}?v=16"></script>
    @unless ($adminPreview ?? false)
    <script id="pwa-client"
            defer
            src="{{ asset('display/pwa.js') }}?v=6"
            data-service-worker-url="{{ asset('service-worker.js') }}?v=20"
            data-preview-mode="{{ $pwaPreview }}"></script>
    @endunless
</head>
<body>
    @include('display.partials.icons')
    @include('display.partials.atmosphere')

    <div class="display" id="display"
         data-weather-url="{{ ($adminPreview ?? false) ? '' : route('api.display.weather') }}"
         data-leaflet-images-url="{{ asset('vendor/leaflet/images') }}/">

        {{-- ── Top bar: identity, live condition summary, clock, health ──── --}}
        <header class="topbar">
            <div class="brand">
                <svg class="brand-mark" aria-hidden="true"><use href="#{{ $get('identity.symbol') ?: $heroIcon }}"></use></svg>
                <div class="brand-text">
                    <h1 class="station-name" id="dashboard-title">{{ $get('identity.title', 'Midway Weather') }}</h1>
                    <p class="location-name" id="dashboard-subtitle" @if($get('identity.subtitle', '') === '') hidden @endif>{{ $get('identity.subtitle', '') }}</p>
                </div>
            </div>

            <p class="summary" id="summary" data-bind="summary" data-fmt="text">{{ $get('summary', 'Awaiting station data') }}</p>

            <div class="topbar-right">
                <div class="clock">
                    <span class="clock-time" id="clock-time">--:--</span>
                    <span class="clock-date" id="clock-date">&nbsp;</span>
                </div>
                <div class="status" id="status-badge" data-freshness="{{ $get('health.freshness', 'no_data') }}">
                    <span class="status-dot"></span>
                    <span class="status-copy">
                        <span class="status-text" id="status-text">Updating…</span>
                        <span class="status-age" id="status-age">Checking station…</span>
                    </span>
                </div>
            </div>

            <button class="install-button"
                    id="install-button"
                    type="button"
                    hidden
                    aria-haspopup="dialog"
                    aria-controls="ios-install-dialog">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 3v11m0 0 4-4m-4 4-4-4M5 14v5h14v-5"></path>
                </svg>
                Install
            </button>
        </header>

        {{-- ── Main content: hero | metrics | forecast+map ──────────────── --}}
        <main class="main">

            {{-- Hero / Outdoor --}}
            <section class="hero tile" data-tile="outdoor" aria-label="Current outdoor conditions">
                <div class="hero-top">
                    <svg class="hero-icon" id="hero-icon" aria-hidden="true"><use href="#{{ $heroIcon }}"></use></svg>
                    <div class="hero-reading">
                        <div class="hero-temp">
                            <span id="hero-temp" data-bind="observation.temperature" data-fmt="temp">{{ Format::temp($get('observation.temperature')) }}</span>
                        </div>
                        <div class="hero-feels">
                            Feels like <strong id="hero-feels" data-bind="observation.feels_like" data-fmt="temp">{{ Format::temp($get('observation.feels_like')) }}</strong>
                        </div>
                    </div>
                </div>

                <div class="hero-trends">
                    @include('display.partials.trend', ['id' => 'trend-temperature', 't' => $get('trends.temperature')])
                    @include('display.partials.trend', ['id' => 'trend-temperature-midnight', 't' => $get('trends.temperature_since_midnight')])
                </div>

                <div class="hero-spark">
                    @include('display.partials.sparkline', ['id' => 'spark-temperature', 'spark' => $get('sparklines.temperature')])
                </div>

                <dl class="hero-stats">
                    <div class="hero-stat">
                        <dt>High</dt>
                        <dd><span id="today-high" data-bind="observation.today.high" data-fmt="temp">{{ Format::temp($get('observation.today.high')) }}</span></dd>
                    </div>
                    <div class="hero-stat">
                        <dt>Low</dt>
                        <dd><span id="today-low" data-bind="observation.today.low" data-fmt="temp">{{ Format::temp($get('observation.today.low')) }}</span></dd>
                    </div>
                    <div class="hero-stat">
                        <dt>Dew Point</dt>
                        <dd><span id="hero-dew" data-bind="observation.dew_point" data-fmt="tempPrecise">{{ Format::tempPrecise($get('observation.dew_point')) }}</span></dd>
                    </div>
                </dl>
            </section>

            {{-- Metrics grid --}}
            <section class="metrics" aria-label="Weather metrics">

                @foreach (\App\Weather\Configuration\WeatherSettings::tiles() as $tileId => $tileDefinition)
                    @if ($tileDefinition['region'] === 'metrics')
                        @include($tileDefinition['view'])
                    @endif
                @endforeach
            </section>

            {{-- Right rail: current radar over the configured base map --}}
            <section class="rail" aria-label="Current radar map">
                <div class="tile map-tile" data-tile="map">
                    <div class="map" id="map"
                         data-lat="{{ $get('meta.latitude') }}"
                         data-lon="{{ $get('meta.longitude') }}"
                         data-basemap-provider="{{ $get('meta.basemap.provider') }}"
                         data-basemap-url="{{ $get('meta.basemap.tile_url') }}"
                         data-basemap-max-zoom="{{ $get('meta.basemap.max_zoom') }}"
                         data-zoom="{{ $get('meta.map_zoom', 8) }}"
                         data-radar-enabled="{{ $get('meta.radar.enabled', true) ? 'true' : 'false' }}"
                         data-radar-metadata-url="{{ $get('meta.radar.metadata_url', 'https://api.rainviewer.com/public/weather-maps.json') }}"
                         data-radar-refresh-minutes="{{ $get('meta.radar.refresh_minutes', 10) }}"
                         data-radar-refresh-ms="{{ max(1, (int) $get('meta.radar.refresh_minutes', 10)) * 60 * 1000 }}"
                         data-radar-opacity="{{ $get('meta.radar.opacity', 0.65) }}"
                         role="img"
                         aria-label="Current radar near {{ $get('meta.location_name') }}"></div>
                    <div class="map-radar-time" id="radar-time">{{ $get('meta.radar.enabled', true) ? 'Radar · Updating…' : 'Map' }}</div>
                    <div class="map-basemap-status" id="basemap-status" role="status" hidden>Base map unavailable. Check map provider setup.</div>
                    <a id="mapbox-logo" class="weather-mapbox-logo" href="https://www.mapbox.com/" target="_blank" rel="noopener noreferrer" @if(!$get('meta.basemap.logo')) hidden @endif><img src="{{ asset('icons/mapbox-logo.svg') }}" width="88" height="23" alt="Mapbox"></a>
                    <div class="map-attrib" id="map-attribution">@foreach($get('meta.basemap.attribution', []) as $credit)<a href="{{ $credit['url'] }}" target="_blank" rel="noopener noreferrer">{{ $credit['label'] }}</a> · @endforeach<span>Radar RainViewer</span></div>
                </div>
            </section>
        </main>

        {{-- ── Forecast strip (all currently available days) ────────────── --}}
        <footer class="forecast" data-tile="forecast" aria-label="Weather forecast">
            <div class="forecast-head">
                <span class="forecast-title" id="forecast-title" data-phone-label="{{ $phoneForecastTitle }}">{{ $forecastTitle }}</span>
                <span class="forecast-source" id="forecast-source" data-stale="{{ $get('forecast.is_stale') ? 'true' : 'false' }}">{{ $get('forecast.source', 'Open-Meteo forecast') }}</span>
            </div>
            <ol class="forecast-days" id="forecast-days" style="--forecast-day-count: {{ max(1, $forecastDayCount) }}">
                @forelse ($forecastDays as $day)
                    <li class="forecast-day @if (data_get($day, 'is_today')) forecast-day--today @endif" data-atmos-day="{{ data_get($day, 'icon', 'cloudy') }}">
                        <span class="forecast-dow">{{ data_get($day, 'day_label') }}</span>
                        <svg class="forecast-icon" aria-hidden="true"><use href="#wi-{{ data_get($day, 'icon', 'cloudy') }}"></use></svg>
                        <span class="forecast-cond">{{ data_get($day, 'condition') }}</span>
                        <span class="forecast-temps">
                            <span class="forecast-hi">{{ Format::temp(data_get($day, 'high')) }}</span>
                            <span class="forecast-lo">{{ Format::temp(data_get($day, 'low')) }}</span>
                        </span>
                        <span class="forecast-pop">
                            <svg class="icon icon--sm" aria-hidden="true"><use href="#ui-droplet"></use></svg>
                            {{ Format::percent(data_get($day, 'precipitation_probability')) }}
                        </span>
                    </li>
                @empty
                    <li class="forecast-empty">Forecast unavailable</li>
                @endforelse
            </ol>
        </footer>

        {{-- Demo-mode watermark (only when running on generated data). --}}
        @if ($get('meta.demo_mode'))
            <div class="demo-badge" id="demo-badge" title="Showing generated demo data">DEMO DATA</div>
        @endif
    </div>

    <dialog class="pwa-dialog" id="ios-install-dialog" aria-labelledby="ios-install-title">
        <div class="pwa-dialog-head">
            <div class="pwa-dialog-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                    <path d="M12 15V3m0 0L8 7m4-4 4 4M7 10H5v10h14V10h-2"></path>
                </svg>
            </div>
            <div>
                <p class="pwa-dialog-eyebrow">iPhone &amp; iPad</p>
                <h2 id="ios-install-title">Add Midway to your Home Screen</h2>
            </div>
            <button class="pwa-dialog-close" id="ios-install-close" type="button" aria-label="Close install instructions">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </div>
        <ol class="pwa-install-steps">
            <li>Tap the <strong>Share</strong> button.</li>
            <li>Choose <strong>Add to Home Screen</strong>.</li>
            <li>Tap <strong>Add</strong>.</li>
        </ol>
        <p class="pwa-dialog-note">Apple requires these steps in the browser; Midway cannot open the install screen automatically.</p>
    </dialog>
</body>
</html>
