/*
 * Midway Weather — kiosk client.
 *
 * Vanilla JS, no framework, no build step. Responsibilities:
 *   1. Hydrate from the JSON embedded in the page (#bootstrap-data) so we start
 *      from the exact server-rendered state — no flash, no re-layout.
 *   2. Poll the local endpoint supplied by Blade every meta.poll_seconds and
 *      patch the DOM in place. The browser NEVER calls Ambient or Open-Meteo
 *      directly; this local endpoint is the only weather source it touches.
 *   3. Keep the last good data on screen if a poll fails (never blank, never 0),
 *      surface staleness/connection state, and show one current radar frame.
 *
 * The `fmt` helpers below mirror app/Weather/Support/Format.php 1:1 so the live
 * updates format identically to the first paint. Keep the two in sync.
 */
(function () {
    'use strict';

    var DASH = '—'; // em dash — matches Format::DASH

    /* ── Formatters (mirror of App\Weather\Support\Format) ──────────────── */
    var fmt = {
        temp: function (v) { return v == null ? DASH : Math.round(v) + '°'; },
        tempPrecise: function (v) { return v == null ? DASH : v.toFixed(1) + '°'; },
        percent: function (v) { return v == null ? DASH : Math.round(v) + '%'; },
        speed: function (v) { return v == null ? DASH : String(Math.round(v)); },
        pressure: function (v) { return v == null ? DASH : Number(v).toFixed(2); },
        rain: function (v) { return v == null ? DASH : Number(v).toFixed(2) + '"'; },
        uv: function (v) { return v == null ? DASH : String(Math.round(v)); },
        solar: function (v) { return v == null ? DASH : String(Math.round(v)); },
        delta: function (v, decimals) {
            if (v == null) return '';
            decimals = decimals == null ? 1 : decimals;
            var rounded = Number(v.toFixed(decimals));
            if (rounded === 0) return (0).toFixed(decimals);
            var sign = rounded > 0 ? '+' : '';
            return sign + rounded.toFixed(decimals);
        },
        text: function (v) { return (v == null || v === '') ? DASH : String(v); }
    };

    /* ── Small DOM helpers ──────────────────────────────────────────────── */
    function $(id) { return document.getElementById(id); }
    function get(obj, path) {
        var parts = path.split('.');
        var cur = obj;
        for (var i = 0; i < parts.length; i++) {
            if (cur == null) return undefined;
            cur = cur[parts[i]];
        }
        return cur;
    }
    function setText(el, value) {
        if (el && el.textContent !== value) el.textContent = value;
    }

    /* ── State ──────────────────────────────────────────────────────────── */
    var state = readBootstrap();
    var pollSeconds = Math.max(15, (state && get(state, 'meta.poll_seconds')) || 60);
    var timezone = (state && get(state, 'meta.timezone')) || 'America/New_York';
    var consecutiveFailures = 0;
    var map = null;
    var mapMarker = null;
    var basemapLayer = null, basemapSignature = null, basemapRetryTimer = null, basemapWatchdog = null;
    var basemapGeneration = 0, basemapRetryDelay = 60000;

    var mapCenter = null;      // [lat, lon] of the home marker — re-centered after any resize
    var radarLayer = null;
    var pendingRadarLayer = null;
    var radarRefreshTimer = null;
    var radarRefreshInFlight = false;

    function displayUrl(name) {
        var display = $('display');
        return display ? display.getAttribute(name) : null;
    }

    function readBootstrap() {
        try {
            var el = $('bootstrap-data');
            return el ? JSON.parse(el.textContent) : null;
        } catch (e) {
            return null;
        }
    }

    /* ── data-bind patching ─────────────────────────────────────────────── */
    // Every element with data-bind="dotted.path" gets its text set from the
    // view model, formatted via data-fmt (defaulting to a plain string).
    function applyBindings(model) {
        var nodes = document.querySelectorAll('[data-bind]');
        nodes.forEach(function (el) {
            var path = el.getAttribute('data-bind');
            var fn = el.getAttribute('data-fmt') || 'text';
            var raw = get(model, path);
            var formatter = fmt[fn] || fmt.text;
            setText(el, formatter(raw));
        });
    }

    /* ── Trend chips ────────────────────────────────────────────────────── */
    var TREND_ARROWS = { rising: 'ui-arrow-up', falling: 'ui-arrow-down', steady: 'ui-arrow-right', unknown: 'ui-arrow-right' };
    function updateTrend(id, trend) {
        var root = $(id);
        if (!root) return;
        var dir = (trend && trend.direction) || 'unknown';
        root.setAttribute('data-dir', dir);
        var arrow = root.querySelector('.trend-arrow');
        if (arrow) {
            if (dir === 'unknown') { arrow.setAttribute('hidden', ''); }
            else { arrow.removeAttribute('hidden'); }
            var use = arrow.querySelector('use');
            if (use) use.setAttribute('href', '#' + (TREND_ARROWS[dir] || 'ui-arrow-right'));
        }
        setText(root.querySelector('.trend-label'), (trend && trend.label) || DASH);
        setText(root.querySelector('.trend-delta'), trend ? fmt.delta(trend.delta) : '');
        setText(root.querySelector('.trend-when'), (trend && trend.comparison) || '');
    }

    /* ── Sparklines ─────────────────────────────────────────────────────── */
    function drawSparkline(id, spark) {
        var root = $(id);
        if (!root || !spark) return;
        var line = root.querySelector('.spark-line');
        var area = root.querySelector('.spark-area');
        if (spark.view_box) root.setAttribute('viewBox', spark.view_box);
        setPath(line, spark.path);
        setPath(area, spark.area);
    }
    function setPath(el, d) {
        if (!el) return;
        if (d) { el.setAttribute('d', d); el.removeAttribute('hidden'); }
        else { el.setAttribute('hidden', ''); }
    }

    /* ── Wind + condition icon ──────────────────────────────────────────── */
    var HERO_ICONS = { 'clear-night': 'wi-clear-night', 'rain': 'wi-rain', 'overcast': 'wi-cloudy', 'hot': 'wi-clear-day', 'clear-day': 'wi-clear-day' };
    function heroIconId(themeKey) { return HERO_ICONS[themeKey] || 'wi-clear-day'; }

    var atmosphere = null;
    var windAngle = null;

    function updateHeroIcon(model, scene) {
        var key = get(model, 'theme.key') || 'clear-day';
        var icon = scene && scene.settings.intensity !== 'off' && window.WeatherAtmosphere && (window.WeatherAtmosphere.icon(scene) || 'cloudy');
        var symbol = icon ? 'wi-' + icon : heroIconId(key);
        [$('hero-icon'), document.querySelector('.brand-mark')].forEach(function (svg) {
            if (!svg) return;
            var use = svg.querySelector('use');
            if (use) use.setAttribute('href', '#' + (svg.classList.contains('brand-mark') ? get(model, 'identity.symbol') || symbol : symbol));
        });
        document.documentElement.setAttribute('data-theme', key);
        document.documentElement.setAttribute('data-atmosphere', get(model, 'theme.atmosphere') || 'clear');
        document.documentElement.setAttribute('data-time-of-day', get(model, 'theme.time_of_day') || 'afternoon');
    }

    function updateTemperatureMood(model) {
        var root = document.documentElement;
        var preview = root.getAttribute('data-temperature-preview');
        var mood = preview || get(model, 'theme.temperature_mood') || 'mild';
        if (root.getAttribute('data-temperature-mood') !== mood) {
            root.setAttribute('data-temperature-mood', mood);
        }
    }

    function updateWind(model) {
        var dir = get(model, 'observation.wind.direction');
        var arrow = $('wind-arrow');
        if (arrow && dir != null) {
            // Arrow points the way the wind blows TO (bearing + 180°).
            var target = (dir + 180) % 360;
            windAngle = windAngle === null ? target : windAngle + ((target - windAngle) % 360 + 540) % 360 - 180;
            arrow.style.transform = 'translate(-50%, -50%) rotate(' + windAngle + 'deg)';
        }
    }

    /* ── Forecast strip ─────────────────────────────────────────────────── */
    function updateForecast(model) {
        var days = get(model, 'forecast.days') || [];
        var list = $('forecast-days');
        var title = $('forecast-title');
        var source = $('forecast-source');
        var phoneDayCount = Math.min(4, days.length);
        if (title) {
            setText(title, days.length ? days.length + '-Day Forecast' : 'Forecast');
            title.setAttribute('data-phone-label', phoneDayCount ? phoneDayCount + '-Day Forecast' : 'Forecast');
        }
        if (source) {
            setText(source, get(model, 'forecast.source') || 'Open-Meteo forecast');
            source.setAttribute('data-stale', get(model, 'forecast.is_stale') ? 'true' : 'false');
        }
        if (!list) return;
        list.style.setProperty('--forecast-day-count', String(Math.max(1, days.length)));

        if (!days.length) {
            list.innerHTML = '<li class="forecast-empty">Forecast unavailable</li>';
            return;
        }

        // Rebuild the strip only when the set of dates changes; otherwise patch
        // the existing nodes so we don't thrash the DOM every minute.
        var existing = list.querySelectorAll('.forecast-day');
        var sameShape = existing.length === days.length;
        if (!sameShape) {
            list.innerHTML = days.map(forecastDayHtml).join('');
            return;
        }
        days.forEach(function (day, i) { patchForecastDay(existing[i], day); });
    }

    function forecastDayHtml(day) {
        return '<li class="forecast-day' + (day.is_today ? ' forecast-day--today' : '') + '" data-atmos-day="' + esc(day.icon || 'cloudy') + '">'
            + '<span class="forecast-dow">' + esc(day.day_label) + '</span>'
            + '<svg class="forecast-icon" aria-hidden="true"><use href="#wi-' + esc(day.icon || 'cloudy') + '"></use></svg>'
            + '<span class="forecast-cond">' + esc(day.condition || '') + '</span>'
            + '<span class="forecast-temps"><span class="forecast-hi">' + fmt.temp(day.high) + '</span>'
            + '<span class="forecast-lo">' + fmt.temp(day.low) + '</span></span>'
            + '<span class="forecast-pop"><svg class="icon icon--sm" aria-hidden="true"><use href="#ui-droplet"></use></svg> '
            + fmt.percent(day.precipitation_probability) + '</span>'
            + '</li>';
    }
    function patchForecastDay(li, day) {
        if (!li) return;
        li.classList.toggle('forecast-day--today', !!day.is_today);
        li.dataset.atmosDay = day.icon || 'cloudy';
        setText(li.querySelector('.forecast-dow'), day.day_label);
        var use = li.querySelector('.forecast-icon use');
        if (use) use.setAttribute('href', '#wi-' + (day.icon || 'cloudy'));
        setText(li.querySelector('.forecast-cond'), day.condition || '');
        setText(li.querySelector('.forecast-hi'), fmt.temp(day.high));
        setText(li.querySelector('.forecast-lo'), fmt.temp(day.low));
        // pop is the text node after the inline svg; rebuild that span's tail.
        var pop = li.querySelector('.forecast-pop');
        if (pop) {
            var svg = pop.querySelector('svg');
            pop.textContent = '';
            if (svg) pop.appendChild(svg);
            pop.appendChild(document.createTextNode(' ' + fmt.percent(day.precipitation_probability)));
        }
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    /* ── Toggle-state tiles (rain, battery) ─────────────────────────────── */
    function updateTiles(model) {
        var rain = $('rain-tile');
        if (rain) rain.setAttribute('data-raining', get(model, 'rain.is_raining') ? 'true' : 'false');

        var allOk = get(model, 'observation.battery.all_ok');
        var battery = $('battery-tile');
        if (battery) battery.setAttribute('data-ok', allOk === false ? 'false' : 'true');
        var bs = $('battery-status');
        if (bs) setText(bs, allOk === false ? 'Low battery' : (allOk === true ? 'All OK' : DASH));
        setBatterySub($('battery-outdoor'), get(model, 'observation.battery.outdoor_ok'));
        setBatterySub($('battery-indoor'), get(model, 'observation.battery.indoor_ok'));
    }
    function setBatterySub(el, ok) {
        if (!el) return;
        setText(el, ok == null ? DASH : (ok ? 'OK' : 'Low'));
    }

    /* ── Health / staleness / connection ────────────────────────────────── */
    function updateHealth(model) {
        var freshness = get(model, 'health.freshness') || 'no_data';
        var badge = $('status-badge');
        if (badge) badge.setAttribute('data-freshness', freshness);

        setText($('status-text'), describeFreshness(freshness));
        setText($('status-age'), describeObservationAge(model));

        var display = $('display');
        if (display) display.setAttribute('data-state', freshness === 'stale' || freshness === 'no_data' ? 'stale' : 'ok');
    }
    function describeFreshness(freshness) {
        if (freshness === 'no_data') return 'No station data';
        if (freshness === 'fresh') return 'Live';
        if (freshness === 'delayed') return 'Delayed';
        return 'Stale';
    }
    function describeObservationAge(model) {
        var observedAt = get(model, 'observation.observed_at');
        if (!observedAt) return 'Awaiting observation';
        var observed = new Date(observedAt);
        if (isNaN(observed.getTime())) return 'Update time unavailable';
        var seconds = Math.max(0, Math.floor((Date.now() - observed.getTime()) / 1000));
        if (seconds < 60) return 'Updated ' + seconds + ' sec ago';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return 'Updated ' + minutes + ' min ago';
        var hours = Math.floor(minutes / 60);
        return 'Updated ' + hours + ' hr ago';
    }

    /* ── Clock (local to the station timezone) ──────────────────────────── */
    var timeFmt, dateFmt;
    try {
        timeFmt = new Intl.DateTimeFormat('en-US', { timeZone: timezone, hour: 'numeric', minute: '2-digit' });
        dateFmt = new Intl.DateTimeFormat('en-US', { timeZone: timezone, weekday: 'long', month: 'long', day: 'numeric' });
    } catch (e) {
        timeFmt = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit' });
        dateFmt = new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
    }
    function updateClock() {
        var now = new Date();
        setText($('clock-time'), timeFmt.format(now));
        setText($('clock-date'), dateFmt.format(now));
        if (state) updateHealth(state);
    }

    /* ── Leaflet map ────────────────────────────────────────────────────── */
    function initMap() {
        var el = $('map');
        if (!el || typeof L === 'undefined' || map) return;

        var lat = parseFloat(el.getAttribute('data-lat'));
        var lon = parseFloat(el.getAttribute('data-lon'));
        var zoom = parseInt(el.getAttribute('data-zoom'), 10) || 8;
        if (isNaN(lat) || isNaN(lon)) return;
        mapCenter = [lat, lon];

        // Point the default marker/icon images at our self-hosted copies.
        if (L.Icon && L.Icon.Default) {
            L.Icon.Default.imagePath = displayUrl('data-leaflet-images-url');
        }

        map = L.map(el, {
            center: [lat, lon],
            zoom: zoom,
            zoomControl: false,
            attributionControl: false,
            dragging: false,
            scrollWheelZoom: false,
            doubleClickZoom: false,
            boxZoom: false,
            keyboard: false,
            touchZoom: false,
            tap: false,
            fadeAnimation: false,
            zoomAnimation: false,
            markerZoomAnimation: false
        });

        map.createPane('radarPane');
        map.getPane('radarPane').style.zIndex = 250;

        // Only the base pane is darkened; radar colors, marker and attribution stay intact.
        map.createPane('basemapPane');
        map.getPane('basemapPane').style.zIndex = 200;
        syncBasemap(get(state, 'meta.basemap'));

        var icon = L.divIcon({
            className: '',
            html: '<div class="map-marker"><svg aria-hidden="true"><use href="#ui-home"></use></svg></div>',
            iconSize: [34, 34],
            iconAnchor: [17, 17]
        });
        mapMarker = L.marker([lat, lon], { icon: icon, keyboard: false, interactive: false }).addTo(map);

        if(state && state.configuration)mapMarker.setLatLng([state.configuration.values.map.latitude,state.configuration.values.map.longitude]);
        if (el.getAttribute('data-radar-enabled') === 'true') {
            refreshRadar();
            startRadarRefresh(el);
        } else {
            setText($('radar-time'), 'Map');
        }

        // The tile container is sized by CSS after layout; nudge Leaflet to
        // recompute once everything has settled.
        setTimeout(function () { if (map) map.invalidateSize(); }, 200);
    }

    function basemapStatus(message, visible) {
        var status = $('basemap-status');
        if (status) { status.textContent = message; status.hidden = !visible; }
        if (window.parent !== window) window.parent.postMessage({type: 'weather:basemap-status', message: message}, '*');
    }

    function basemapCredits(definition) {
        var attribution = $('map-attribution');
        if (attribution) {
            attribution.replaceChildren();
            (definition.attribution || []).forEach(function (credit) {
                var link = document.createElement('a');
                link.href = credit.url; link.textContent = credit.label;
                link.target = '_blank'; link.rel = 'noopener noreferrer';
                attribution.appendChild(link); attribution.appendChild(document.createTextNode(' · '));
            });
            attribution.appendChild(document.createTextNode('Radar RainViewer'));
        }
        var logo = $('mapbox-logo');
        if (logo) logo.hidden = !definition.logo;
        if (attribution) attribution.classList.toggle('map-attrib--mapbox', !!definition.logo);
        map.getPane('basemapPane').classList.toggle('basemap-osm', definition.provider === 'openstreetmap');
        $('map').setAttribute('data-basemap-provider', definition.provider);
    }

    function syncBasemap(definition) {
        if (!map || !definition) return;
        var signature = JSON.stringify(definition);
        if (signature === basemapSignature) return;
        basemapSignature = signature;
        basemapRetryDelay = 60000;
        clearTimeout(basemapRetryTimer); basemapRetryTimer = null;
        loadBasemap(definition, definition, false);
    }

    function loadBasemap(definition, requested, fallback) {
        var generation = ++basemapGeneration, loaded = 0, errors = 0, finished = false;
        clearTimeout(basemapWatchdog);
        if (basemapLayer) { basemapLayer.off(); map.removeLayer(basemapLayer); basemapLayer = null; }
        basemapCredits(definition);
        function retry() {
            if (basemapRetryTimer) return;
            basemapRetryTimer = setTimeout(function () {
                basemapRetryTimer = null;
                loadBasemap(requested, requested, false);
            }, basemapRetryDelay);
            basemapRetryDelay = Math.min(300000, basemapRetryDelay * 2);
        }
        function failed() {
            if (finished || generation !== basemapGeneration) return;
            finished = true; clearTimeout(basemapWatchdog);
            if (!fallback && requested.provider !== 'openstreetmap' && requested.fallback) {
                retry();
                basemapStatus(requested.name + ' unavailable. Trying OpenStreetMap temporarily…', true);
                loadBasemap(requested.fallback, requested, true);
            } else {
                basemapStatus('Base map unavailable. Check map provider setup or connectivity. Radar has its own status.', true);
                retry();
            }
        }
        if (!definition.tile_url) { failed(); return; }
        basemapWatchdog = setTimeout(failed, 15000);
        basemapLayer = L.tileLayer(definition.tile_url, {
            pane: 'basemapPane', maxZoom: definition.max_zoom,
            tileSize: definition.tile_size || 256, zoomOffset: definition.zoom_offset || 0,
            referrerPolicy: 'strict-origin-when-cross-origin', crossOrigin: true,
            keepBuffer: 0
        }).on('loading', function () {
            if (generation !== basemapGeneration) return;
            loaded = 0; errors = 0; finished = false;
            clearTimeout(basemapWatchdog); basemapWatchdog = setTimeout(failed, 15000);
        }).on('tileload', function () { loaded++; })
          .on('tileerror', function () { errors++; })
          .on('load', function () {
            if (generation !== basemapGeneration || finished) return;
            if (!loaded || errors >= Math.max(2, loaded)) {
                // Leaflet still reads this layer's map after firing "load".
                // Swap layers on the next turn, after its tile callback completes.
                setTimeout(failed, 0); return;
            }
            finished = true; clearTimeout(basemapWatchdog);
            if (fallback) {
                basemapStatus('Using OpenStreetMap temporarily — ' + requested.name + ' unavailable. Retrying automatically.', true);
            } else {
                clearTimeout(basemapRetryTimer); basemapRetryTimer = null; basemapRetryDelay = 60000;
                basemapStatus(errors ? definition.name + ' loaded with some missing tiles. Check connectivity.' : definition.name + ' tiles loaded. Preview is ready.', errors > 0);
            }
        }).addTo(map);
    }

    function startRadarRefresh(el) {
        if (radarRefreshTimer) return;
        var refreshMinutes = parseInt(el.getAttribute('data-radar-refresh-minutes'), 10) || 10;
        var radarRefreshMs = Math.max(60000, refreshMinutes * 60 * 1000);
        radarRefreshTimer = setInterval(refreshRadar, radarRefreshMs);
    }

    // RainViewer returns historical frames oldest-first today, but selecting by
    // timestamp keeps latest-frame-only behavior correct if ordering changes.
    function latestRadarFrame(metadata) {
        var frames = metadata && metadata.radar && metadata.radar.past;
        if (!Array.isArray(frames) || !frames.length) return null;

        return frames.reduce(function (latest, frame) {
            if (!frame || !frame.path || !Number.isFinite(Number(frame.time))) return latest;
            return !latest || Number(frame.time) > Number(latest.time) ? frame : latest;
        }, null);
    }

    function refreshRadar() {
        var el = $('map');
        var metadataUrl = el && el.getAttribute('data-radar-metadata-url');
        if (!map || !metadataUrl || radarRefreshInFlight || el.getAttribute('data-radar-enabled') !== 'true') return;

        radarRefreshInFlight = true;
        var controller = new AbortController();
        var metadataTimeout = setTimeout(function () { controller.abort(); }, 20000);

        fetch(metadataUrl, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
            signal: controller.signal
        })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (metadata) {
                clearTimeout(metadataTimeout);
                var frame = latestRadarFrame(metadata);
                if (!frame || typeof metadata.host !== 'string' || !metadata.host) {
                    throw new Error('No radar frame');
                }
                installRadarLayer(el, metadata.host, frame);
            })
            .catch(function () {
                clearTimeout(metadataTimeout);
                radarRefreshInFlight = false;
                showRadarFallback();
            });
    }

    function installRadarLayer(el, host, frame) {
        var opacity = parseFloat(el.getAttribute('data-radar-opacity'));
        opacity = isNaN(opacity) ? 0.65 : Math.max(0, Math.min(1, opacity));

        // RainViewer's public API currently serves native tiles through zoom 7.
        // Leaflet scales those tiles for the configurable regional zoom 8 view.
        var radarTileUrl = host.replace(/\/$/, '') + frame.path + '/256/{z}/{x}/{y}/2/1_1.png';
        var nextLayer = L.tileLayer(radarTileUrl, {
            pane: 'radarPane',
            opacity: opacity,
            maxNativeZoom: 7,
            maxZoom: 19,
            crossOrigin: true,
            updateWhenIdle: true,
            updateWhenZooming: false,
            keepBuffer: 0
        });
        var settled = false;
        var tileTimeout = setTimeout(fail, 20000);

        function cleanup() {
            clearTimeout(tileTimeout);
            nextLayer.off('load', succeed);
            nextLayer.off('tileerror', fail);
            if (pendingRadarLayer === nextLayer) pendingRadarLayer = null;
        }
        function succeed() {
            if (el.getAttribute('data-radar-enabled') !== 'true') { fail(); return; }
            if (settled) return;
            settled = true;
            cleanup();
            var previousLayer = radarLayer;
            radarLayer = nextLayer;
            if (previousLayer && previousLayer !== nextLayer && map.hasLayer(previousLayer)) {
                map.removeLayer(previousLayer);
            }
            radarRefreshInFlight = false;
            showRadarTime(frame.time);
        }
        function fail() {
            if (settled) return;
            settled = true;
            cleanup();
            if (map.hasLayer(nextLayer)) map.removeLayer(nextLayer);
            radarRefreshInFlight = false;
            showRadarFallback();
        }

        if (pendingRadarLayer && map.hasLayer(pendingRadarLayer)) {
            map.removeLayer(pendingRadarLayer);
        }
        pendingRadarLayer = nextLayer;
        nextLayer.once('load', succeed);
        nextLayer.once('tileerror', fail);
        nextLayer.addTo(map);
    }

    function showRadarTime(unixTime) {
        var time;
        try {
            time = new Intl.DateTimeFormat('en-US', {
                timeZone: timezone,
                hour: 'numeric',
                minute: '2-digit'
            }).format(new Date(Number(unixTime) * 1000));
        } catch (e) {
            time = new Date(Number(unixTime) * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        }
        setText($('radar-time'), 'Radar · ' + time);
    }

    function showRadarFallback() {
        if (pendingRadarLayer && map && map.hasLayer(pendingRadarLayer)) {
            map.removeLayer(pendingRadarLayer);
        }
        pendingRadarLayer = null;
        if (radarLayer && map && map.hasLayer(radarLayer)) map.removeLayer(radarLayer);
        radarLayer = null;
        setText($('radar-time'), 'Radar unavailable');
    }

    function fitDashboardIdentity() {
        var text = document.querySelector('.brand-text');
        if (!text) return;
        var brand = text.closest('.brand');
        text.style.removeProperty('--identity-scale');
        brand.style.removeProperty('transform');
        if (window.innerWidth <= 1024) return;
        var height = document.querySelector('.topbar').clientHeight;
        for (var percent = 95; text.scrollHeight > height && percent >= 45; percent -= 5) {
            text.style.setProperty('--identity-scale', String(percent / 100));
        }
        var offset = document.querySelector('.topbar').getBoundingClientRect().bottom - brand.getBoundingClientRect().bottom;
        if (offset < 0) brand.style.transform = 'translateY(' + offset + 'px)';
    }

    function updateDashboardIdentity(model) {
        var title = get(model, 'identity.title');
        if (typeof title !== 'string') return;
        setText($('dashboard-title'), title);
        document.title = title;
        var subtitle = $('dashboard-subtitle'), value = get(model, 'identity.subtitle') || '';
        setText(subtitle, value);
        if (subtitle) subtitle.hidden = value === '';
        fitDashboardIdentity();
    }

    var configurationRevision = null;
    var activeSeasonalTheme = null;
    var forecastHome = null;
    function applyConfiguration(model) {
        if (map) syncBasemap(get(model, 'meta.basemap'));
        var bundle = model.configuration;
        if (!bundle || configurationRevision === bundle.revision && activeSeasonalTheme === model.seasonal_theme) return;
        configurationRevision = bundle.revision;
        activeSeasonalTheme = model.seasonal_theme;
        var v = bundle.values, display = $('display'), main = document.querySelector('.main');
        var defaultOrder = ['outdoor','wind','rain','humidity','pressure','uv','solar','indoor','battery','map','forecast','pool'];
        var defaultWide = ['wind','rain','indoor'];
        var custom = v.tiles.some(function(t,i) { return t.id !== defaultOrder[i] || t.enabled !== (t.id !== 'pool') || t.size !== (defaultWide.indexOf(t.id)>=0 ? 'wide':'standard'); });
        display.classList.toggle('configured-layout',custom);
        display.style.setProperty('--edge-padding',v.display.edge_padding+'px');
        display.style.setProperty('--bottom-padding',v.display.bottom_padding+'px');
        document.documentElement.setAttribute('data-seasonal-theme',model.seasonal_theme || 'default');
        var themeAsset=model.theme_catalog && model.theme_catalog[model.seasonal_theme];
        var themeLink=$('seasonal-theme-stylesheet');
        if (themeAsset && themeAsset.stylesheet) {
            if (!themeLink) { themeLink=document.createElement('link');themeLink.id='seasonal-theme-stylesheet';themeLink.rel='stylesheet';document.head.appendChild(themeLink); }
            if (themeLink.getAttribute('href') !== themeAsset.stylesheet) themeLink.href=themeAsset.stylesheet;
        } else if (themeLink) themeLink.remove();

        $('clock-time').hidden = !v.display.clock;
        $('clock-date').hidden = !v.display.date;
        var forecast=document.querySelector('[data-tile="forecast"]');
        if (!forecastHome) forecastHome=forecast.parentNode;
        if(custom) main.appendChild(forecast); else forecastHome.appendChild(forecast);
        var metrics=document.querySelector('.metrics'),rail=document.querySelector('.rail');
        var used=0,rows=1,rowTiles=[];
        function fillRow(){
            if(!rowTiles.length)return;
            var remaining=12;
            rowTiles.forEach(function(item,i){var span=i===rowTiles.length-1?remaining:Math.max(1,Math.round(item.span*12/used));item.el.style.setProperty('--tile-span',span);remaining-=span;});
            rowTiles=[];used=0;
        }
        v.tiles.forEach(function(t,i) {
            var el=document.querySelector('[data-tile="'+t.id+'"]');
            if(!el) return;
            el.hidden=!t.enabled;
            el.style.order=i;
            el.setAttribute('data-size',t.size);
            var span=t.id==='forecast'?12:(t.id==='outdoor'||t.id==='map'?(t.size==='wide'?6:4):(t.size==='wide'?4:2));
            el.style.setProperty('--tile-span',span);
            if(custom && t.enabled) { if(used+span>12){fillRow();rows++;} used+=span;rowTiles.push({el:el,span:span}); }
            if(el.parentNode===metrics) metrics.appendChild(el);
        });
        fillRow();
        main.style.setProperty('--layout-rows',rows);
        metrics.hidden=!v.tiles.some(function(t) {return t.enabled && ['outdoor','map','forecast'].indexOf(t.id)<0;});
        rail.hidden=!v.tiles.find(function(t) {return t.id==='map';}).enabled;
        var el=$('map');
        var center=[Number(v.map.latitude)+Number(v.map.offset_lat),Number(v.map.longitude)+Number(v.map.offset_lon)];
        el.setAttribute('data-lat',center[0]);el.setAttribute('data-lon',center[1]);el.setAttribute('data-zoom',v.map.zoom);
        el.setAttribute('data-radar-enabled',v.map.radar?'true':'false');
        if(map) {
            mapCenter=center;map.setView(center,v.map.zoom,{animate:false});mapMarker.setLatLng([v.map.latitude,v.map.longitude]);
            if(!v.map.radar) { if(radarRefreshTimer)clearInterval(radarRefreshTimer);radarRefreshTimer=null;if(radarLayer)map.removeLayer(radarLayer);radarLayer=null;if(pendingRadarLayer)map.removeLayer(pendingRadarLayer);pendingRadarLayer=null;setText($('radar-time'),'Map'); }
            else {refreshRadar();startRadarRefresh(el);}
            setTimeout(function(){map.invalidateSize();},50);
        }
    }

    function fitConfiguredLayout() {
        var display=$('display'), main=document.querySelector('.main');
        var rows=parseInt(main.style.getPropertyValue('--layout-rows'),10)||1;
        var height=(main.clientHeight-(rows-1)*16)/rows;
        display.classList.toggle('dense-layout',display.classList.contains('configured-layout') && window.innerWidth>1024 && height<195);
    }

    function syncSensorTiles(model) {
        var tiles=model.sensor_tiles||{}, changed=false;
        document.querySelectorAll('[data-sensor-tile]').forEach(function(el){if(!tiles[el.dataset.tile]){el.remove();changed=true;}});
        Object.keys(tiles).forEach(function(id){
            var t=tiles[id],el=document.querySelector('[data-tile="'+id+'"]');
            if(!el||!el.hasAttribute('data-sensor-tile')){
                if(el)el.remove();el=document.createElement('div');el.className='tile metric metric--sensor';el.dataset.tile=id;el.setAttribute('data-sensor-tile','');
                el.innerHTML='<div class="metric-head"><svg class="sensor-icon" aria-hidden="true"><use/></svg><span></span></div><div class="metric-body"><span class="metric-value"></span><span class="metric-unit"></span></div><p class="metric-foot sensor-state"></p><p class="metric-foot sensor-time"></p><p class="metric-foot sensor-history"></p>';
                document.querySelector('.metrics').appendChild(el);changed=true;
            }
            el.querySelector('.metric-head span').textContent=t.label;
            el.querySelector('use').setAttribute('href','#'+t.symbol);
            el.querySelector('.metric-value').textContent=t.value===null?'—':t.value;
            el.querySelector('.metric-unit').textContent=t.unit;
            el.querySelector('.sensor-state').textContent=t.state.charAt(0).toUpperCase()+t.state.slice(1)+(t.value===null&&t.last_known!==null?' · Last known '+t.last_known+' '+t.unit:'');
            el.querySelector('.sensor-time').textContent=t.observed_at?'Observed '+new Date(t.observed_at).toLocaleString(): 'No reading yet';
            el.querySelector('.sensor-history').textContent=t.history.min===null?'No available history in 24h':'24h available range: '+t.history.min+'–'+t.history.max+' '+t.unit;
        });
        if(changed)configurationRevision=null;
    }

    /* ── Master render ──────────────────────────────────────────────────── */
    function render(model) {
        if (!model) return;
        state = model;
        syncSensorTiles(model);
        applyConfiguration(model);
        updateDashboardIdentity(model);
        fitConfiguredLayout();

        applyBindings(model);
        var scene = get(model, 'theme.scene');
        updateHeroIcon(model, scene);
        if (scene && window.WeatherAtmosphere) {
            if (!atmosphere) atmosphere = window.WeatherAtmosphere.mount(document.documentElement);
            atmosphere.update(scene, model.seasonal_theme);
            if (scene.settings.intensity !== 'off' && scene.allow_quip) {
                var quip = window.WeatherAtmosphere.quip(scene);
                if (quip) setText($('summary'), quip);
            }
        }
        updateTemperatureMood(model);
        updateWind(model);

        updateTrend('trend-temperature', get(model, 'trends.temperature'));
        updateTrend('trend-temperature-midnight', get(model, 'trends.temperature_since_midnight'));
        updateTrend('trend-pressure', get(model, 'trends.pressure'));
        updateTrend('trend-humidity', get(model, 'trends.humidity'));
        updateTrend('trend-wind', get(model, 'trends.wind'));

        // Only the hero carries a sparkline; the metric tiles show a compact
        // trend chip instead (kept legible on the 1920x1080 wall display).
        drawSparkline('spark-temperature', get(model, 'sparklines.temperature'));

        updateForecast(model);
        updateTiles(model);
        updateHealth(model);
    }

    /* ── Polling ────────────────────────────────────────────────────────── */
    function poll() {
        var weatherUrl = displayUrl('data-weather-url');
        if (!weatherUrl) return;

        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 20000);

        fetch(weatherUrl, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
            signal: controller.signal
        })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (model) {
                clearTimeout(timeout);
                consecutiveFailures = 0;
                setConnection('ok');
                render(model);
            })
            .catch(function () {
                clearTimeout(timeout);
                // Keep the last good data on screen. Only flag the connection
                // after a few misses so a single blip is invisible.
                consecutiveFailures++;
                if (consecutiveFailures >= 3) setConnection('lost');
            });
    }
    function setConnection(status) {
        var display = $('display');
        if (display) display.setAttribute('data-connection', status);
        atmosphere?.setConnected(status === 'ok');
    }

    /* ── Boot ───────────────────────────────────────────────────────────── */
    function boot() {
        ensureOfflineHint();
        if (state) render(state); // paint from embedded model immediately
        initMap();
        updateClock();
        setInterval(updateClock, 15000);
        if ($('display').getAttribute('data-weather-url')) setInterval(poll, pollSeconds * 1000);
        // A first live poll shortly after load reconciles anything that changed
        // between server render and script start.
        if ($('display').getAttribute('data-weather-url')) setTimeout(poll, 2000);

        // Re-fit the single map instance whenever the viewport changes size —
        // kiosk rotation, a phone rotating between portrait/landscape, or the
        // mobile browser chrome collapsing. Debounced so a burst of resize
        // events triggers just one relayout (kinder to the Pi / low-power
        // phones). This never creates a new map or timer; it only nudges the
        // existing Leaflet instance and re-centers it on the home marker.
        var relayoutTimer = null;
        function relayoutMap() {
            fitDashboardIdentity();
            fitConfiguredLayout();
            if (!map) return;
            if (relayoutTimer) clearTimeout(relayoutTimer);
            relayoutTimer = setTimeout(function () {
                relayoutTimer = null;
                if (!map) return;
                map.invalidateSize();
                if (mapCenter) map.setView(mapCenter, map.getZoom(), { animate: false });
            }, 200);
        }
        window.addEventListener('resize', relayoutMap);
        window.addEventListener('orientationchange', relayoutMap);
    }

    function ensureOfflineHint() {
        if ($('offline-hint')) return;
        var hint = document.createElement('div');
        hint.className = 'offline-hint';
        hint.id = 'offline-hint';
        hint.textContent = 'Reconnecting to station…';
        var display = $('display');
        if (display) display.appendChild(hint);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
