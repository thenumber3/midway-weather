# Weather Admin

Open `/admin` and sign in through the Weather-branded Midway login. The control surface manages the existing dashboard and PWA through one SQLite configuration row. Save applies a complete, validated configuration atomically. Revision checks prevent one browser overwriting another browser's save. Draft previews render the real dashboard using current observations, without saving settings, polling the saved configuration, registering a service worker, or changing the live quip rotation cache.

Dashboard layout has active and available tiles, drag reordering, accessible earlier/later buttons, supported sizes, and reset. Priority flows across the desktop grid and the mobile column. The original layout remains the default. Mobile sizes intentionally adapt to its available width. Safe padding stays inside the viewport, with device safe-area insets preserved.

Quips begin with the existing library. Search/filter, add, edit, disable, remove, and weather/time contexts all feed the original stable rotation and recent-history avoidance. Contexts match any selected context; general sayings are fallback options. Daylight-only copy is excluded at night. Advanced thresholds tune hot/cold/humidity/wind/heavy-rain matching; the canonical consumer contract's temperature bands and weather-code meanings remain unchanged.

Map configuration includes a name or US ZIP lookup, forecast coordinates, zoom, radar visibility, and independent map-center offsets. A coordinate edit affects forecast collection on its next refresh; use the diagnostics refresh control when an immediate forecast refresh is needed. The live display and both admin previews share the [base-map provider configuration](configuration.md#maps-and-radar). Choose OpenStreetMap, Stadia Alidade Smooth Dark, MapTiler Streets v4, or Mapbox Dark v11 under Radar & location. Existing installations inherit their environment configuration; new installations default to keyless OpenStreetMap. Provider-specific guidance, write-only encrypted key controls, and Test & preview map are included. Sustained tile failures temporarily fall back to OSM with corrected attribution and bounded recovery retries; saved selection is preserved. Base tiles send an origin-only referrer even within private admin previews. Attribution links remain visible at desktop and mobile sizes. RainViewer remains a separate overlay. Coordinates are public display configuration. Ambient credentials never reach the browser. An optional Stadia tile key is intentionally browser-visible; never reuse station credentials. Manual refresh preserves last-good data on provider failure.

Tile definitions belong to `WeatherSettings::tiles()`. Metrics declare a Blade render partial under `resources/views/display/tiles`; the editor discovers labels/sizes/availability from the registry. Newly registered tiles appear inactive in previously saved configurations. Pool Temperature is the first extension slot, inactive by default and explicitly awaiting a sensor integration; it never displays an invented reading. Add source implementations alongside the existing Ambient and forecast collection services and register normalized admin diagnostics through `WeatherSources`.

Diagnostics show release/source identity, observation freshness, source success/failure state and normalized payloads, configuration revision, active theme, and a once-per-minute scheduler heartbeat. A missing heartbeat is shown as awaiting the first tick; it is not inferred from weather freshness. Saved configuration lives in SQLite and should be included in your backup policy; rolling back application code does not restore saved settings.

Validation: `bash scripts/test.sh`, `./vendor/bin/pint --test --dirty`, JavaScript syntax checks, and desktop/mobile browser checks against a disposable local instance. The existing time-sensitive sparkline baseline remains separately reported by the established test script. Installed PWAs receive the new versioned shell on an online relaunch; no reinstall is needed.


## Atmosphere and seasonal themes

Open **Themes → Atmospheric engine** for the live preview and controls. **Immersive** is the default for fresh and upgraded installations. Choose **Balanced** or **Subtle** to soften the scene, or **Off** to use the underlying theme palette by itself. You can also toggle precipitation and droplets, clouds and fog, sunlight and stars, storm illumination, frost and heat shimmer, wind-driven movement, icon motion, and forecast-card styling individually.

**Respect device reduced-motion preferences** is on by default. When the system preference is set to reduce motion, the scene keeps its colors and static details while motion and storm illumination pause. **Reset to defaults** restores atmospheric preferences in the draft. Click **Save changes** to apply settings to live dashboards on their next successful weather poll.

### Simulate conditions

Choose **Simulate conditions** under **Preview source**. Change weather, time of day, temperature, wind speed and direction, or precipitation intensity for rain, snow, and thunderstorms. The preview uses the same renderer as the dashboard. Simulated readings and sample forecast cards stay in the browser tab and never enter saved settings, weather history, APIs, or integrations. Choose **Live weather** to return to station data. [Screenshot tour](screenshots/README.md#atmosphere-20)

### Schedule a seasonal theme

The **Seasonal layer** section offers Weather and October / Fall. Atmospheric effects work with either palette. **Automatic** uses recurring `MM-DD` ranges in station time, supports ranges crossing New Year, and resolves overlaps by list priority. The default autumn range is October 1–November 30, with Weather used outside any scheduled range. Existing manual choices and custom ranges carry forward on upgrade.

Theme authors can register a local stylesheet in `config/weather_themes.php`. Scope seasonal rules to `data-seasonal-theme` and preserve the weather and temperature attributes used by existing styles.

## Dashboard identity

Open **Display settings → Dashboard identity** to personalize the public heading:

- **Dashboard title:** required, up to 60 characters. A fresh installation starts with `Midway Weather`; examples include `Smith Family Weather`, `Alaska Weather Station`, and `Jeff's Weather`.
- **Dashboard subtitle:** up to 120 characters. Fresh installations begin with the configured location as ordinary editable text. Enter any subtitle independently of the station/map settings, or leave it empty to hide the entire line. Once saved, subsequent location changes do not replace this text.
- **Dashboard icon:** dynamic weather/time of day, sun, moon, partly cloudy, cloudy, rain, thunderstorm, snow, wind, or thermometer. Existing local SVG symbols are reused; no icon uploads or external images are accepted. A fixed heading icon does not change the outdoor reading's condition icon or theme behavior.

The selected icon is shown beside the control. The existing desktop/mobile live-data preview is also available directly in Display settings; edits remain drafts until **Save changes**. Blank subtitles, apostrophes, Unicode and literal markup are handled as text. Titles cannot be empty, text length is enforced on the server, and line breaks/control characters and unknown icon values are rejected. Long labels wrap on phones and fit within the desktop header.

Saving uses the same complete configuration and revision checks as other admin settings. A conflicting save leaves the draft available and asks you to reload; it does not overwrite another session. Open public dashboards receive the saved title, subtitle and icon on the next successful weather poll, normally within a minute. The dashboard browser-tab title follows the personalized heading.

**Upgrade preservation:** run the normal database migrations when adopting this code. The compatibility migration only initializes identity when an existing saved configuration lacks it, or weather data exists without any saved configuration. In the latter case it creates a settings row from the existing environment-based defaults. It snapshots the previous station-based heading, location subtitle and former CSS household suffix, leaving other configuration values intact and advancing the revision. It does not replace existing identity choices, and rollback does not delete personalized settings. Fresh installations receive no household suffix. An upgraded installation can edit or clear the captured subtitle like any other text.

This changes dashboard presentation only. Midway Weather remains the software name: administration/login branding, package name, PWA install identity, provider settings, station metadata, map coordinates and versioned API contracts are unchanged. Identity values are exposed on the public dashboard/display API; do not enter private information you do not want viewers to see.

## Data Sources & Providers

See [provider configuration and station discovery](providers.md) for environment-managed and encrypted Admin connections, primary station compatibility, and diagnostics.
