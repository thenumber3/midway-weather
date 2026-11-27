# Architecture

Midway Weather is a standalone Laravel application for one Ambient Weather station per installation. Its original use is a 1920×1080 kitchen display; the same Blade page adapts to desktop browsers and scrolling mobile layouts. It does not require a Raspberry Pi: the server and display browser can run on separate machines.

## Collection and presentation

1. Laravel's scheduler runs `weather:collect` every minute. The Ambient client requests `/v1/devices`, selects the configured device (or the first account device), normalizes `lastData`, and upserts a minute-rounded observation.
2. `weather:forecast` requests daily Open-Meteo data hourly and upserts forecasts by local date.
3. Each collector records source health. Failures preserve last-good weather data.
4. `weather:cleanup` rebuilds recent daily aggregates before pruning old detailed observations.
5. `/` renders from local SQLite data through `DisplayPresenter`; the browser polls `/api/display/weather`, normally every 60 seconds. Browser polling does not collect from Ambient.
6. The browser loads keyless OpenStreetMap base tiles (or configured Stadia Maps) and optional latest-frame RainViewer radar independently. Public map interaction is disabled for a stable display; admin controls set framing.

```text
Provider clients → collection services → SQLite
                                           ↓
Saved settings → DisplayPresenter → Blade HTML / display JSON → browser
                                           ↓
                                versioned summary projections
```

### Atmospheric presentation

The display presenter supplies a scene through the existing dashboard payload. It uses recent station observations, daily forecast context, and local solar times. Measured rain takes precedence over a conflicting daily rain prediction, and stale inputs age out of the visual scene. The daily forecast still drives its own cards. The versioned weather summary and forecast APIs retain their existing schemas.

The dashboard and Themes preview share one browser renderer. CSS handles lighting and card treatments, while a bounded canvas draws precipitation. Animation work pauses when the scene is out of view or the tab is hidden, and reduced motion keeps a static scene. Admin simulation changes the preview inputs in memory. Leaflet and the radar overlay render independently of the atmospheric layers.

## Persistence and configuration

| Table | Purpose |
| --- | --- |
| `observations` | Minute-deduplicated normalized readings plus raw provider payload |
| `daily_summaries` | Local-day aggregates retained beyond detailed observations |
| `forecast_days` | Daily forecasts, retained across failed refreshes |
| `collection_statuses` | Provider success/failure state |
| `weather_settings` | Central presentation configuration with revision checking |

SQLite uses WAL mode, NORMAL synchronous mode, and a five-second busy timeout by default. Keep the database and its directory writable for SQLite sidecars. File cache and sessions, plus the synchronous queue driver, avoid additional daemons.

Environment/configuration provides initial defaults and credentials. Saved settings override the supported presentation defaults. `WeatherSettings` applies the same settings to server rendering and JSON polling. Unsaved authenticated previews use the real presenter without persisting settings, polling saved configuration, registering a service worker, or modifying live quip rotation.

## HTTP boundaries

| GET route | Purpose | Access |
| --- | --- | --- |
| `/` | Main dashboard | Public |
| `/display/weather` | Compatibility entry point | Public |
| `/api/display/weather` | Display-specific view model | Public |
| `/api/v1/summary/current` | Existing compact ecosystem summary | Public |
| `/api/v2/summary/current` | Canonical weather summary | Public |
| `/api/v1/forecast/daily` | Daily forecast projection | Public |
| `/api/v1/health`, `/api/v1/version` | Operational contracts | Public |
| `/manifest.webmanifest` | PWA metadata | Public |
| `/up` | Laravel boot/liveness | Public |
| `/admin`, `/admin/diagnostics` | Configuration and diagnostics | Administrator session |

Admin writes, previews, lookup, refresh, and logout use protected POST routes with CSRF validation. See [administrator setup](configuration.md#administrator-setup). Display JSON is an unversioned presentation model and includes configured location/map coordinates. Canonical APIs intentionally separate consumer contracts from display-specific information; see [Weather v2](contracts/weather-v2.md).

## Code map and extension points

- `app/Weather/Clients/`: provider HTTP integrations.
- `app/Weather/Services/`: collection, retention, trends, summaries, presentation.
- `app/Weather/Configuration/WeatherSettings.php`: registered tiles and saved settings.
- `app/Weather/Configuration/WeatherSources.php`: normalized source diagnostics.
- `app/Weather/Support/`: formatting, conditions, phrases, temperature moods, sparklines.
- `resources/views/display/tiles/`: tile partials.
- `config/weather_themes.php`: seasonal theme registry.
- `public/display/`, `public/admin-assets/`: browser assets; no frontend compilation.
- `routes/console.php`: schedules and heartbeat.

New tiles can register supported sizes and a render partial. Newly registered tiles remain inactive in existing saved layouts. The Pool Temperature slot can be explicitly bound to a verified Ambient temperature channel; unbound tiles never fabricate a reading. A privately validated Govee pool integration demonstrates a further extension direction, but its connector and generic sensor-sample storage are not part of this public repository. See [environmental sensor integration](environmental-sensors.md).
