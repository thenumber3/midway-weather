(() => {
    'use strict';

    const intensity = { off: 0, subtle: .36, balanced: .68, immersive: 1 };
    const wet = kind => kind.includes('rain') || kind.includes('storm');
    const storm = kind => kind.includes('storm');
    const clamp = (n, min = 0, max = 1) => Math.min(max, Math.max(min, n));
    const mix = (a, b, amount) => a.map((channel, i) => channel + (b[i] - channel) * amount);
    const colors = {
        day: [[32, 94, 136], [20, 54, 82], [161, 224, 248]],
        dawn: [[49, 66, 106], [110, 70, 75], [255, 175, 132]],
        dusk: [[39, 49, 83], [96, 54, 73], [247, 139, 123]],
        night: [[9, 17, 40], [19, 29, 57], [163, 192, 251]],
        rain: [[47, 76, 89], [23, 49, 63], [126, 212, 248]],
        storm: [[14, 23, 40], [33, 43, 62], [164, 190, 233]],
        snow: [[60, 86, 104], [30, 53, 77], [217, 245, 255]],
        fog: [[64, 83, 93], [46, 64, 78], [197, 220, 220]],
        overcast: [[52, 68, 79], [36, 52, 68], [182, 205, 221]],
        heat: [[99, 54, 41], [81, 41, 37], [255, 193, 117]],
    };

    function lighting(solar) {
        if (!solar || solar.sunrise == null || solar.sunset == null) {
            return { phase: solar?.polar_day ? 'day' : 'night', light: solar?.polar_day ? 1 : 0, twilight: 0, progress: .5 };
        }
        const minutesFromRise = (solar.now - solar.sunrise) / 60;
        const minutesToSet = (solar.sunset - solar.now) / 60;
        const nearRise = minutesFromRise < minutesToSet;
        const nearest = Math.min(minutesFromRise, minutesToSet);
        return {
            phase: nearest < -45 ? 'night' : nearest < 50 ? (nearRise ? 'dawn' : 'dusk') : 'day',
            light: clamp((nearest + 45) / 95),
            twilight: clamp(1 - Math.abs(nearest) / 50),
            progress: clamp((solar.now - solar.sunrise) / (solar.sunset - solar.sunrise)),
        };
    }

    function appearance(scene, seasonal) {
        const power = intensity[scene.settings.intensity] || 0;
        const effects = scene.settings.effects;
        const { kind, temperature } = scene;
        const weather = storm(kind) ? 'storm' : wet(kind) ? 'rain' : kind;
        const { phase, light, twilight, progress } = lighting(scene.solar);
        const sky = effects.light
            ? colors.day.map((value, i) => mix(mix(colors.night[i], value, light), colors[phase][i], twilight * .86))
            : colors.day;
        const cold = effects.temperature && temperature != null ? clamp((55 - temperature) / 36) : 0;
        const heat = effects.temperature && temperature != null ? clamp((temperature - 87) / 14) : 0;
        const cover = { clear: .12, 'partly-cloudy': .37, overcast: .84, fog: .78, rain: .79, storm: 1, snow: .84 }[weather] ?? .12;
        const wind = effects.wind ? clamp(Math.max(scene.wind || 0, (scene.gust || 0) * .65), 0, 65) : 0;
        const direction = scene.direction == null ? .35 : -Math.sin(scene.direction * Math.PI / 180);
        const palette = sky.map((value, i) => {
            const tint = colors[weather]?.[i] || value;
            let result = mix(value, tint, colors[weather] ? cover * (.64 + light * .19) : 0);
            result = mix(result, colors.snow[i], cold * .32);
            if (weather === 'rain') {
                const warmth = temperature == null ? 0 : clamp((temperature - 54) / 27);
                result = mix(result, [[35, 104, 105], [16, 72, 79], [154, 231, 207]][i], warmth * .44);
            }
            result = mix(result, colors.heat[i], heat * (.17 + light * .55) * (1 - cover * .65));
            if (seasonal === 'october-fall') {
                result = mix(result, [[54, 39, 44], [93, 51, 42], [245, 169, 99]][i], .26 - cold * .14);
            }
            return result.map(Math.round).join(' ');
        });
        return { power, effects, phase, light, twilight, progress, palette, cover, wind, direction, heat, cold, weather };
    }

    function icon(scene) {
        if (!scene.available) return null;
        const icons = { 'light-rain': 'drizzle', rain: 'rain', 'heavy-rain': 'showers', snow: 'snow', fog: 'fog', overcast: 'cloudy' };
        const night = lighting(scene.solar).light < .45;
        if (storm(scene.kind)) return 'thunderstorm';
        if (scene.kind === 'partly-cloudy') return night ? 'partly-cloudy-night' : 'partly-cloudy-day';
        return icons[scene.kind] || (night ? 'clear-night' : 'clear-day');
    }

    function quip(scene) {
        if (!scene.available || scene.estimated || scene.temperature == null) return '';
        const phase = lighting(scene.solar).phase;
        const wind = (scene.wind || 0) >= 10 || (scene.gust || 0) >= 20;
        const temp = scene.temperature;
        if (storm(scene.kind) && wind) return 'Wind and rain are giving the porch a percussion section.';
        if (wet(scene.kind) && temp < 45) {
            if (phase === 'dusk' && wind) return 'Cold rain at dusk. The porch can wait.';
            if (wind) return 'Cold rain is coming in sideways.';
            if (phase === 'night') return 'Cold rain is keeping the house company tonight.';
            return 'Cold rain. A good day to leave the boots by the door.';
        }
        if (wet(scene.kind) && temp >= 65 && phase === 'day') {
            return wind ? 'Warm rain is finding its way across the porch.' : 'Warm rain on the roof. Georgia has its own sprinkler.';
        }
        if (scene.kind === 'snow' && wind) return 'The wind is rearranging the snow before it lands.';
        if (scene.kind === 'fog' && (phase === 'dawn' || phase === 'dusk')) return 'The trees are disappearing into the mist.';
        if (scene.kind === 'clear' && phase === 'night' && temp <= 32) return 'Clear skies and a cold night for the windshield.';
        if (scene.kind === 'clear' && phase === 'day' && temp >= 95 && !wind) return 'Even the breeze has given up on the day.';
        return '';
    }

    // A single bounded canvas handles both precipitation layers. The rest of
    // the scene is CSS, using slow compositor-friendly transforms.
    function mount(surface) {
        const backdrop = surface.querySelector('.atmosphere-scene');
        const canvas = backdrop.querySelector('canvas');
        const ctx = canvas.getContext('2d');
        const motionPreference = matchMedia('(prefers-reduced-motion: reduce)');
        const mobile = matchMedia('(max-width: 650px)');
        const particles = Array.from({ length: 144 }, () => ({
            x: Math.random(), y: Math.random(), depth: Math.random(), phase: Math.random() * Math.PI * 2,
        }));
        let scene, look, connected = true, frame = 0, lastFrame = 0, lightningTimer, lightningAnimation, visible = true, suspended = false;
        let width = 0, height = 0, sceneKey = '';

        function staticFrame() {
            if (!hasParticles()) {
                if (canvas.width !== 1 || canvas.height !== 1) {
                    canvas.width = 1;
                    canvas.height = 1;
                }
                width = height = 0;
                return;
            }
            const bounds = canvas.getBoundingClientRect();
            width = bounds.width;
            height = bounds.height;
            const resolution = Math.min(devicePixelRatio || 1, mobile.matches ? 1 : 1.25, Math.sqrt(1500000 / (width * height)));
            canvas.width = Math.max(1, Math.round(width * resolution));
            canvas.height = Math.max(1, Math.round(height * resolution));
            ctx?.setTransform(resolution, 0, 0, resolution, 0, 0);
            paint(0);
        }

        function hasParticles() {
            return ctx && look?.power && look.effects.precipitation && scene.precipitation !== 0 && (wet(scene.kind) || scene.kind === 'snow');
        }

        function paint(seconds) {
            if (!hasParticles() || !width || !height) return;
            ctx.clearRect(0, 0, width, height);
            const snow = scene.kind === 'snow';
            const amount = scene.precipitation ?? (scene.rain > 0 && !snow ? clamp(.36 + Math.sqrt(scene.rain) * 1.05) : scene.severity || .55);
            const density = clamp(Math.sqrt(width * height / 700000), .35, 1);
            const count = Math.round((24 + 120 * amount) * look.power * density * (scene.estimated ? .66 : 1));
            const drift = look.direction * look.wind;
            for (let depth = 0; depth < 3; depth++) {
                ctx.beginPath();
                for (let i = depth; i < count; i += 3) {
                    const p = particles[i];
                    const fall = snow ? 34 + depth * 20 + p.depth * 30 : 380 + amount * 420 + depth * 200 + p.depth * 100;
                    const sway = snow ? Math.sin(p.y * 12 + p.phase) * 14 : 0;
                    const lateral = (snow ? drift * 3.8 : clamp(drift * 24, -500, 500)) + sway;
                    const x = p.x * (width + 90) - 45;
                    const y = p.y * (height + 90) - 45;
                    p.x = (p.x + lateral * seconds / (width + 90) + 1) % 1;
                    p.y = (p.y + fall * seconds / (height + 90)) % 1;
                    if (snow) {
                        const radius = .85 + depth * .62 + p.depth;
                        ctx.moveTo(x + radius, y);
                        ctx.arc(x, y, radius, 0, Math.PI * 2);
                    } else {
                        const length = (12 + depth * 12 + p.depth * 19) * (.65 + amount * .55);
                        ctx.moveTo(x, y);
                        ctx.lineTo(x + lateral * length / fall, y + length);
                    }
                }
                const opacity = (.23 + depth * .12) * (.56 + look.power * .44);
                if (snow) {
                    ctx.fillStyle = `rgba(235,248,255,${opacity + .15})`;
                    ctx.fill();
                } else {
                    ctx.lineWidth = .8 + depth * .55;
                    ctx.strokeStyle = `rgba(186,224,239,${opacity})`;
                    ctx.stroke();
                }
            }
        }

        function tick(now) {
            if (now - lastFrame >= 39) {
                paint(lastFrame ? Math.min(.08, (now - lastFrame) / 1000) : 0);
                lastFrame = now;
            }
            frame = requestAnimationFrame(tick);
        }

        function scheduleLightning(delay) {
            lightningTimer = setTimeout(() => {
                lightningAnimation = backdrop.querySelector('.atmosphere-lightning').animate(
                    [{ opacity: 0 }, { opacity: .24 * look.power, offset: .34 }, { opacity: 0 }],
                    { duration: 1500, easing: 'ease-in-out' },
                );
                scheduleLightning(65000 + Math.random() * 45000);
            }, delay);
        }

        function lifecycle() {
            cancelAnimationFrame(frame);
            clearTimeout(lightningTimer);
            lightningAnimation?.cancel();
            frame = 0;
            lastFrame = 0;
            if (!look) return;
            const moving = look.power && connected && visible && !suspended && !document.hidden
                && !(scene.settings.respect_reduced_motion && motionPreference.matches);
            surface.dataset.atmosMotion = moving ? 'on' : 'off';
            if (moving && hasParticles()) frame = requestAnimationFrame(tick);
            if (moving && storm(scene.kind) && look.effects.lightning && !scene.estimated) {
                scheduleLightning(surface.classList.contains('atmosphere-preview') ? 4200 : 45000 + Math.random() * 35000);
            }
        }

        const resizeObserver = new ResizeObserver(staticFrame);
        resizeObserver.observe(backdrop);
        const intersection = new IntersectionObserver(([entry]) => {
            visible = entry.isIntersecting;
            lifecycle();
        });
        intersection.observe(backdrop.parentElement);
        document.addEventListener('visibilitychange', lifecycle);
        motionPreference.addEventListener('change', lifecycle);
        window.addEventListener('pagehide', () => { suspended = true; lifecycle(); });
        window.addEventListener('pageshow', () => { suspended = false; lifecycle(); });

        return {
            setConnected(value) {
                if (connected === value) return;
                connected = value;
                lifecycle();
            },
            update(next, seasonal) {
                const hadParticles = Boolean(hasParticles());
                scene = next;
                look = appearance(scene, seasonal);
                const { power, effects, phase, light, twilight, progress, palette, cover, wind, direction, heat, cold, weather } = look;
                surface.dataset.atmosLevel = power ? scene.settings.intensity : 'off';
                surface.dataset.atmosWeather = weather;
                surface.dataset.atmosPhase = phase;
                surface.dataset.atmosForecast = effects.forecast ? 'on' : 'off';
                surface.dataset.atmosIcons = effects.icons ? 'on' : 'off';
                surface.dataset.atmosClouds = effects.clouds ? 'on' : 'off';
                surface.dataset.atmosHeat = heat * light > .12 ? 'on' : 'off';
                surface.dataset.atmosWind = wind >= 3 ? 'on' : 'off';
                surface.dataset.atmosFreeze = effects.temperature && scene.temperature != null && scene.temperature <= 32 ? 'on' : 'off';
                surface.dataset.atmosWet = effects.precipitation && scene.precipitation !== 0 && wet(scene.kind) ? 'on' : 'off';
                const variables = {
                    power, 'sky-top': palette[0], 'sky-low': palette[1], glow: palette[2],
                    clouds: effects.clouds ? cover * power : 0,
                    mist: effects.clouds && weather === 'fog' ? .9 * power : 0,
                    stars: effects.light ? (1 - light) * (1 - cover) ** 3 * power : 0,
                    sun: effects.light ? (light + twilight * .28) * (1 - cover) ** 2 * power : 0,
                    heat: heat * light * power * (1 - cover) ** 2,
                    cold: cold * power,
                    'light-spot': `${Math.round(16 + 70 * progress)}%`,
                    'light-height': `${Math.round(61 - Math.sin(progress * Math.PI) * 48)}%`,
                    'drift-time': `${Math.round(130 - wind * 1.5)}s`,
                    'drift-x': `${direction * (wind ? (13 + wind * .24) : 6)}%`,
                    'wind-sway': `${Math.min(7, wind * .18)}deg`,
                };
                Object.entries(variables).forEach(([name, value]) => surface.style.setProperty('--at-' + name, value));
                if (hadParticles !== Boolean(hasParticles())) staticFrame();
                else if (!frame) paint(0);
                const key = [power, scene.kind, scene.estimated, effects.lightning, effects.precipitation, scene.precipitation === 0, scene.settings.respect_reduced_motion].join('/');
                if (sceneKey !== key) {
                    sceneKey = key;
                    lifecycle();
                }
            },
        };
    }

    window.WeatherAtmosphere = { mount, lighting, icon, quip };
})();
