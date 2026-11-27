# Screenshot tour

## Atmosphere 2.0

These captures were rendered from the public repository in a local demo installation using synthetic weather data.

![Wind-driven rain on the desktop demo dashboard](dashboard-atmosphere-rain.webp)

The desktop demo shows wind-driven rain, weather-tinted cards, and individual forecast treatments. The map is centered on a public city location with the radar overlay disabled for this demo.

<img src="dashboard-atmosphere-mobile.webp" alt="The rainy demo dashboard on a phone, with stacked readings, a map, and four forecast days" width="390">

![Weather Admin Themes showing cold rain at sunset and the simulation controls](admin-atmosphere-cold-rain.webp)

In **Admin → Themes**, choose the intensity, preview source, and individual effects. This simulation combines 39°F, rain, southwest wind, and sunset.

![The Weather Admin preview showing nighttime snowfall and frosted card edges](admin-atmosphere-snow-night.webp)

Nighttime snow uses slower flakes, cool lighting, and frosted card edges. [Atmosphere and seasonal themes](../admin.md#atmosphere-and-seasonal-themes)

## October 2026 dashboard and admin captures

Captured October 22, 2026 from the production application at [weather.midway-ecosystem.com](https://weather.midway-ecosystem.com/). These are real observations and interfaces, not mockups. Readings, freshness, and release identifiers describe the capture time.

The primary dashboard uses a 1920×1080 viewport. Mobile uses a 390×844 browser viewport with a full-page capture. Admin uses a 1440×1800 viewport for the complete layout editor and 1440×1100 for other sections. Standard-theme and alternate-layout images crop the real dashboard draft iframe, which renders a 1920×1080 desktop layout scaled by the supported admin preview. Browser image dimensions can differ slightly due to scrollbars and device scaling.

Alternate views were produced through the authenticated theme selector, tile reordering, tile-disable controls, and Update preview. **No settings were saved.** Reloading discarded drafts; diagnostics retained configuration revision 20 and the October / Fall theme. Public coordinates are the configured town-center approximation. Captures omit browser chrome, credentials, raw provider payloads, and expanded diagnostic payloads.

### The household display

![Current October / Fall dashboard](dashboard-october.png)

The primary layout combines current conditions and temperature history, station metrics, the latest radar map, and seven forecast days. Clear weather in this capture means the radar has no precipitation to highlight.

### Themes and alternate layouts

![Standard Weather theme, unsaved preview](dashboard-standard-preview.png)

The standard Weather palette with the original tile selection, rendered from current observations in an unsaved preview.

![Alternate tile selection, unsaved preview](dashboard-alternate-preview.png)

Humidity moves ahead of rainfall; UV, solar, and battery tiles are disabled in this draft. The grid reflows, including a wider radar row. This view is a configuration example, not the saved production layout.

### On a phone

<img src="dashboard-mobile.png" alt="Full mobile dashboard with stacked current conditions, map, station tiles, and forecast" width="390">

### Layout and preview

![Dashboard layout editor and preview](admin-layout.png)

### Quips and messaging

![Weather-aware message editor](admin-quips.png)

### Themes and recurring date ranges

![Theme selection and annual schedule](admin-themes.png)

### Radar and location

![Location, zoom, overlay controls, and map preview](admin-radar-location.png)

### Data sources and connection health

![Ambient Weather and Open-Meteo source status, with unconnected pool extension](admin-data-sources.png)

### System diagnostics

![Release, source, freshness, scheduler heartbeat, and configuration diagnostics](admin-diagnostics.png)
