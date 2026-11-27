(() => {
    'use strict';

    const byId = name => document.getElementById('atmosphere-' + name);
    const panel = byId('panel');
    const surface = byId('preview');
    const source = byId('preview-source');
    const simulation = byId('simulation');
    const renderer = window.WeatherAtmosphere.mount(surface);
    const setting = key => panel.querySelector(`[data-path="theme.atmosphere.${key}"]`);
    const controls = panel.querySelectorAll('[data-path^="theme.atmosphere."]');
    const dayFormatter = new Intl.DateTimeFormat('en-US', { weekday: 'short', timeZone: 'UTC' });
    const samples = [
        ['partly-cloudy-day', 63], ['rain', 49], ['cloudy', 53], ['fog', 54],
        ['clear-day', 66], ['snow', 31], ['thunderstorm', 58],
    ];
    const labels = {
        clear: 'Clear', 'partly-cloudy': 'Partly cloudy', overcast: 'Overcast', fog: 'Fog / mist',
        'light-rain': 'Light rain', rain: 'Rain', 'heavy-rain': 'Heavy rain',
        thunderstorm: 'Thunderstorm', 'severe-storm': 'Severe storm', snow: 'Snow',
    };
    let live = null;
    let timer = null;
    let request = null;
    let failed = false;
    let daysKey = '';

    function simulatedScene() {
        const kind = byId('condition').value;
        const intensity = Number(byId('precipitation').value) / 100;
        const temperature = Number(byId('temperature').value);
        const wind = Number(byId('wind').value);
        const direction = Number(byId('direction').value);
        const precipitation = ['rain', 'snow', 'thunderstorm'].includes(kind);
        byId('precipitation').disabled = !precipitation;
        byId('precipitation-value').value = precipitation ? `${Math.round(intensity * 100)}%` : '—';
        byId('temperature-value').value = `${temperature} °F`;
        byId('wind-value').value = `${wind} mph`;
        byId('direction-value').value = `${direction}°`;

        // An isolated clock on a synthetic day, in seconds.
        const minutes = { dawn: 370, day: 815, dusk: 1150, night: 1310 };
        return {
            kind: kind === 'rain' && intensity === 0 ? 'overcast' : kind,
            available: true,
            temperature, wind, direction,
            precipitation: intensity,
            severity: intensity,
            rain: kind === 'rain' || kind === 'thunderstorm' ? intensity ** 2 : null,
            solar: { now: minutes[byId('phase').value] * 60, sunrise: 360 * 60, sunset: 1140 * 60 },
        };
    }

    function forecast(days) {
        const key = JSON.stringify(days.map(({ day_label, icon, high }) => [day_label, icon, high]));
        if (daysKey === key) return;
        daysKey = key;
        byId('preview-days').replaceChildren(...days.map(day => {
            const row = document.createElement('li');
            row.dataset.atmosDay = day.icon;
            row.innerHTML = '<span></span><svg class="atmosphere-preview-day-icon" aria-hidden="true"><use></use></svg><strong></strong>';
            row.querySelector('span').textContent = day.day_label;
            row.querySelector('use').setAttribute('href', '#wi-' + day.icon);
            row.querySelector('strong').textContent = day.high == null ? '—' : `${Math.round(day.high)}°`;
            return row;
        }));
    }

    function render() {
        if (panel.closest('section').hidden) return;
        const simulating = source.value === 'simulation';
        simulation.hidden = !simulating;
        const scene = simulating ? simulatedScene() : live?.theme.scene;
        if (!scene) {
            byId('preview-status').textContent = failed ? 'Live weather is unavailable. Simulation is still available.' : 'Loading live weather…';
            return;
        }
        const settings = {
            intensity: setting('intensity').value,
            respect_reduced_motion: setting('respect_reduced_motion').checked,
            effects: Object.fromEntries([...controls].filter(el => el.dataset.path.includes('.effects.'))
                .map(el => [el.dataset.path.split('.').pop(), el.checked])),
        };
        const selection = document.querySelector('[data-path="theme.selection"]').value;
        const seasonal = selection === 'automatic' ? live?.seasonal_theme : selection;
        renderer.update({ ...scene, settings }, seasonal);
        const symbol = window.WeatherAtmosphere.icon(scene) || 'cloudy';
        surface.querySelector('.atmosphere-preview-icon use').setAttribute('href', '#wi-' + symbol);
        const phase = window.WeatherAtmosphere.lighting(scene.solar).phase;
        const phaseLabel = { dawn: 'Sunrise', day: 'Day', dusk: 'Sunset', night: 'Night' }[phase];
        byId('preview-phase').textContent = phaseLabel + (simulating ? ' · Simulation' : ' · Live');
        byId('preview-condition').textContent = scene.available
            ? (scene.kind === 'rain' && scene.temperature != null && scene.temperature < 45 ? 'Cold rain' : labels[scene.kind])
            : 'Conditions unavailable';
        byId('preview-temperature').textContent = scene.temperature == null ? '—' : `${Math.round(scene.temperature)}°`;
        byId('preview-wind').textContent = scene.wind == null ? '—' : Math.round(scene.wind);
        const needle = surface.querySelector('.atmosphere-preview-needle');
        needle.style.visibility = scene.direction == null ? 'hidden' : '';
        needle.setAttribute('transform', `rotate(${(scene.direction + 180) % 360} 24 24)`);
        surface.querySelector('.atmosphere-preview-trend').setAttribute('viewBox', simulating ? '0 0 360 38' : live.sparklines?.temperature?.view_box || '0 0 100 32');
        const trend = simulating ? 'M0 29 L44 24 91 28 138 17 184 20 225 12 268 17 318 8 360 12' : live.sparklines?.temperature?.path;
        surface.querySelector('.atmosphere-preview-trend path').setAttribute('d', trend || '');
        const today = new Date((live?.meta.server_time || new Date().toISOString()).slice(0, 10) + 'T00:00:00Z');
        forecast(simulating ? samples.map(([icon, high], index) => ({
            icon: index === 0 ? symbol : icon, high: index === 0 ? scene.temperature : high,
            day_label: index === 0 ? 'Today' : dayFormatter.format(today.getTime() + index * 86400000),
        })) : live.forecast.days);
        byId('preview-status').textContent = simulating
            ? window.WeatherAtmosphere.quip(scene) || 'Showing simulated conditions.'
            : failed ? 'Showing the last available preview. Reconnecting…'
                : scene.estimated ? 'Atmosphere uses today’s forecast where live conditions are unavailable.'
                    : 'Showing the latest station conditions with your draft theme settings.';
    }

    async function refresh() {
        if (source.value !== 'live' || panel.closest('section').hidden || document.hidden || request) return;
        const controller = new AbortController();
        const deadline = setTimeout(() => controller.abort('timeout'), 12000);
        request = controller;
        try {
            const response = await fetch(panel.dataset.weatherUrl, { cache: 'no-store', signal: controller.signal });
            if (!response.ok) throw Error('Weather unavailable');
            const model = await response.json();
            if (!model.theme?.scene) throw Error('Atmosphere unavailable');
            live = model;
            failed = false;
        } catch (error) {
            if (error.name !== 'AbortError' || controller.signal.reason === 'timeout') failed = true;
        } finally {
            clearTimeout(deadline);
            request = null;
            render();
        }
    }

    function lifecycle() {
        clearInterval(timer);
        render();
        if (!panel.closest('section').hidden && !document.hidden && source.value === 'live') {
            refresh();
            timer = setInterval(refresh, 60000);
        } else {
            request?.abort();
        }
    }

    panel.addEventListener('input', event => {
        if (event.target === source) lifecycle();
        else render();
    });
    panel.addEventListener('atmosphere:settings-changed', render);
    document.querySelector('[data-path="theme.selection"]').addEventListener('input', render);
    window.addEventListener('weather:settings-saved', render);
    document.addEventListener('visibilitychange', lifecycle);
    new MutationObserver(lifecycle).observe(panel.closest('section'), { attributes: true, attributeFilter: ['hidden'] });
})();
