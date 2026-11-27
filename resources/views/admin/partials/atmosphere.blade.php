@php
    use App\Weather\Presentation\Atmosphere;
@endphp
<div class="panel atmosphere-panel" id="atmosphere-panel" data-weather-url="{{ route('api.display.weather') }}">
    <div class="atmosphere-panel-heading">
        <h2>Atmospheric engine</h2>
        <button type="button" id="atmosphere-reset">Reset to defaults</button>
    </div>

    <div class="atmosphere-preview" id="atmosphere-preview">
        @include('display.partials.atmosphere')
        <div class="atmosphere-preview-content">
            <div class="atmosphere-preview-heading">
                <strong>Midway Weather</strong>
                <span id="atmosphere-preview-phase">Live preview</span>
            </div>
            <div class="atmosphere-preview-grid">
                <div class="atmosphere-preview-card atmosphere-preview-current">
                    <svg class="atmosphere-preview-icon" aria-hidden="true"><use href="#wi-cloudy"></use></svg>
                    <div>
                        <strong id="atmosphere-preview-temperature">—</strong>
                        <span id="atmosphere-preview-condition">Loading weather…</span>
                    </div>
                    <svg class="atmosphere-preview-trend" viewBox="0 0 360 38" preserveAspectRatio="none" aria-hidden="true"><path /></svg>
                </div>
                <div class="atmosphere-preview-card atmosphere-preview-wind">
                    <div>
                        <span>WIND</span>
                        <strong id="atmosphere-preview-wind">—</strong>
                        <span>mph</span>
                    </div>
                    <svg class="atmosphere-preview-compass" viewBox="0 0 48 48" aria-hidden="true">
                        <circle cx="24" cy="24" r="22"/>
                        <g class="atmosphere-preview-needle"><use href="#ui-nav" x="12" y="12" width="24" height="24"/></g>
                    </svg>
                </div>
            </div>
            <ol id="atmosphere-preview-days" class="atmosphere-preview-days" aria-label="Forecast preview"></ol>
        </div>
    </div>
    <p class="atmosphere-preview-status muted" id="atmosphere-preview-status" role="status">Loading live weather…</p>

    <div class="atmosphere-controls">
        <label>Atmospheric intensity
            <select data-path="theme.atmosphere.intensity">
                @foreach (Atmosphere::INTENSITIES as $intensity)
                    <option value="{{ $intensity }}">{{ ucfirst($intensity) }}</option>
                @endforeach
            </select>
        </label>
        <label>Preview source
            <select id="atmosphere-preview-source">
                <option value="live">Live weather</option>
                <option value="simulation">Simulate conditions</option>
            </select>
        </label>
    </div>

    <fieldset id="atmosphere-simulation" class="atmosphere-simulation" hidden>
        <legend>Simulate conditions</legend>
        <p class="muted">Simulation controls affect only this preview.</p>
        <div class="atmosphere-controls">
            <label>Weather condition
                <select id="atmosphere-condition">
                    <option value="clear">Clear</option>
                    <option value="partly-cloudy">Partly cloudy</option>
                    <option value="overcast">Overcast</option>
                    <option value="rain" selected>Rain</option>
                    <option value="thunderstorm">Thunderstorm</option>
                    <option value="snow">Snow</option>
                    <option value="fog">Fog / mist</option>
                </select>
            </label>
            <label>Time of day
                <select id="atmosphere-phase">
                    <option value="dawn">Sunrise</option>
                    <option value="day">Day</option>
                    <option value="dusk" selected>Sunset</option>
                    <option value="night">Night</option>
                </select>
            </label>
            <label><span>Temperature <output for="atmosphere-temperature" id="atmosphere-temperature-value"></output></span>
                <input type="range" id="atmosphere-temperature" min="-20" max="120" value="39">
            </label>
            <label><span>Wind speed <output for="atmosphere-wind" id="atmosphere-wind-value"></output></span>
                <input type="range" id="atmosphere-wind" min="0" max="70" value="12">
            </label>
            <label><span>Wind direction <output for="atmosphere-direction" id="atmosphere-direction-value"></output></span>
                <input type="range" id="atmosphere-direction" min="0" max="359" value="225">
            </label>
            <label><span>Precipitation intensity <output for="atmosphere-precipitation" id="atmosphere-precipitation-value"></output></span>
                <input type="range" id="atmosphere-precipitation" min="0" max="100" value="55">
            </label>
        </div>
    </fieldset>

    <fieldset class="atmosphere-effects">
        <legend>Atmospheric effects</legend>
        <div class="atmosphere-effect-list">
            @foreach (Atmosphere::EFFECTS as $key => $label)
                <label><input type="checkbox" data-path="theme.atmosphere.effects.{{ $key }}"> {{ $label }}</label>
            @endforeach
        </div>
    </fieldset>
    <label class="atmosphere-motion-control"><input type="checkbox" data-path="theme.atmosphere.respect_reduced_motion"> Respect device reduced-motion preferences</label>
    <script type="application/json" id="atmosphere-defaults">@json(Atmosphere::defaults())</script>
</div>
