<?php

namespace App\Http\Controllers;

use App\Models\CollectionStatus;
use App\Support\ReleaseIdentity;
use App\Weather\Presentation\Atmosphere;
use App\Weather\Configuration\Basemap;
use App\Weather\Configuration\WeatherSettings;
use App\Weather\Configuration\WeatherSources;
use App\Weather\Services\DisplayPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WeatherAdminController
{
    public function index(WeatherSettings $settings)
    {
        return view('admin.index', ['settings' => $settings->read(), 'tiles' => WeatherSettings::tiles(), 'contexts' => WeatherSettings::contexts(), 'themes' => WeatherSettings::themes(), 'identityIcons' => WeatherSettings::identityIcons(), 'basemaps' => Basemap::catalog(), 'mapCredentials' => app(Basemap::class)->credentialStatus(), 'environmentBasemap' => app(Basemap::class)->environmentProvider()]);
    }

    public function validateValues(array $v): array
    {
        // Older cached admin clients must not erase a saved dashboard identity.
        if (! array_key_exists('identity', $v)) {
            $v['identity'] = app(WeatherSettings::class)->read()['values']['identity'];
        }
        // Old Admin tabs must preserve an explicit map selection on unrelated saves.
        $v['basemap'] ??= app(WeatherSettings::class)->read()['values']['basemap'];
        if (is_array($v['theme'] ?? null) && ! array_key_exists('atmosphere', $v['theme'])) {
            $v['theme']['atmosphere'] = app(WeatherSettings::class)->read()['values']['theme']['atmosphere'];
        }
        // A cached Admin client cannot silently remove newly published sensor tiles.
        $known = array_column($v['tiles'] ?? [], 'id');
        foreach (app(WeatherSettings::class)->read()['values']['tiles'] as $tile) {
            if (str_starts_with($tile['id'], 'sensor-') && ! in_array($tile['id'], $known, true)) {
                $v['tiles'][] = $tile;
            }
        }
        $rules = [
            'basemap' => 'required|array:provider,authentication',
            'basemap.provider' => ['required', Rule::in(['environment', ...array_keys(Basemap::catalog())])],
            'basemap.authentication' => ['required', Rule::in(['domain', 'key'])],
            'identity' => 'required|array:title,subtitle,icon',
            'identity.title' => ['required', 'string', 'max:60', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'identity.subtitle' => ['present', 'nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'identity.icon' => ['required', Rule::in(array_keys(WeatherSettings::identityIcons()))],
            'tiles' => 'required|array|size:'.count(WeatherSettings::tiles()), 'tiles.*' => 'required|array:id,enabled,size',
            'tiles.*.id' => ['required', Rule::in(array_keys(WeatherSettings::tiles())), 'distinct'], 'tiles.*.enabled' => 'required|boolean', 'tiles.*.size' => ['required', Rule::in(['standard', 'wide'])],
            'map' => 'required|array:location,zip,latitude,longitude,zoom,offset_lat,offset_lon,radar', 'map.location' => 'required|string|max:100', 'map.zip' => 'nullable|string|regex:/^\d{5}$/', 'map.latitude' => 'required|numeric|between:-85,85', 'map.longitude' => 'required|numeric|between:-180,180', 'map.zoom' => 'required|integer|between:3,14', 'map.offset_lat' => 'required|numeric|between:-10,10', 'map.offset_lon' => 'required|numeric|between:-10,10', 'map.radar' => 'required|boolean',
            'display' => 'required|array:edge_padding,bottom_padding,clock,date', 'display.edge_padding' => 'required|integer|between:0,80', 'display.bottom_padding' => 'required|integer|between:0,100', 'display.clock' => 'required|boolean', 'display.date' => 'required|boolean',
            'theme.atmosphere' => 'required|array:intensity,effects,respect_reduced_motion',
            'theme.atmosphere.intensity' => ['required', Rule::in(Atmosphere::INTENSITIES)],
            'theme.atmosphere.respect_reduced_motion' => 'required|boolean',
            'theme.atmosphere.effects' => 'required|array:'.implode(',', array_keys(Atmosphere::EFFECTS)),
            'theme' => 'required|array:selection,schedules,atmosphere', 'theme.selection' => ['required', Rule::in(['automatic', ...array_keys(WeatherSettings::themes())])], 'theme.schedules' => 'present|array|max:20', 'theme.schedules.*' => 'required|array:theme,start,end', 'theme.schedules.*.theme' => ['required', Rule::in(array_keys(WeatherSettings::themes()))], 'theme.schedules.*.start' => 'required|date_format:m-d', 'theme.schedules.*.end' => 'required|date_format:m-d',
            'thresholds' => 'required|array:hot,cold,humidity,wind,heavy_rain', 'thresholds.hot' => 'required|numeric|between:60,110', 'thresholds.cold' => 'required|numeric|between:-20,60|lt:thresholds.hot', 'thresholds.humidity' => 'required|numeric|between:20,100', 'thresholds.wind' => 'required|numeric|between:5,80', 'thresholds.heavy_rain' => 'required|numeric|between:0.01,3',
            'quips' => 'present|array|max:500', 'quips.*' => 'required|array:id,text,enabled,contexts,daylight_only', 'quips.*.id' => 'required|string|max:80|regex:/^[a-zA-Z0-9._-]+$/|distinct', 'quips.*.text' => 'required|string|max:240', 'quips.*.enabled' => 'required|boolean', 'quips.*.daylight_only' => 'required|boolean', 'quips.*.contexts' => 'required|array|min:1|max:20', 'quips.*.contexts.*' => ['required', Rule::in(WeatherSettings::contexts())],
        ];
        foreach (array_keys(Atmosphere::EFFECTS) as $effect) {
            $rules['theme.atmosphere.effects.'.$effect] = 'required|boolean';
        }
        $data = Validator::make($v, $rules)->validate();
        foreach ($data['tiles'] as $t) {
            if (! in_array($t['size'], WeatherSettings::tiles()[$t['id']]['sizes'], true)) {
                throw ValidationException::withMessages(['tiles' => 'That tile does not support the chosen size.']);
            }
        }

        if (abs($data['map']['latitude'] + $data['map']['offset_lat']) > 85 || abs($data['map']['longitude'] + $data['map']['offset_lon']) > 180) {
            throw ValidationException::withMessages(['map' => 'The map center plus offset must remain within valid map coordinates.']);
        }

        $data['identity']['subtitle'] = $data['identity']['subtitle'] ?? '';
        foreach ($data['tiles'] as &$tile) {
            $tile['enabled'] = (bool) $tile['enabled'];
        }
        unset($tile);
        foreach (['latitude', 'longitude', 'offset_lat', 'offset_lon'] as $key) {
            $data['map'][$key] = (float) $data['map'][$key];
        }
        $data['map']['zoom'] = (int) $data['map']['zoom'];
        $data['map']['radar'] = (bool) $data['map']['radar'];
        foreach (['clock', 'date'] as $key) {
            $data['display'][$key] = (bool) $data['display'][$key];
        }
        foreach (['edge_padding', 'bottom_padding'] as $key) {
            $data['display'][$key] = (int) $data['display'][$key];
        }
        $data['theme']['atmosphere']['effects'] = array_map('boolval', $data['theme']['atmosphere']['effects']);
        $data['theme']['atmosphere']['respect_reduced_motion'] = (bool) $data['theme']['atmosphere']['respect_reduced_motion'];
        foreach ($data['thresholds'] as &$threshold) {
            $threshold = (float) $threshold;
        }
        unset($threshold);
        foreach ($data['quips'] as &$quip) {
            $quip['enabled'] = (bool) $quip['enabled'];
            $quip['daylight_only'] = (bool) $quip['daylight_only'];
        }
        unset($quip);

        return $data;
    }

    private function mapCredential(Request $request, array $values): ?array
    {
        $input = $request->validate([
            'map_credential' => 'sometimes|array:provider,key,clear',
            'map_credential.provider' => ['required_with:map_credential', Rule::in(['stadia', 'maptiler', 'mapbox'])],
            'map_credential.key' => ['nullable', 'string', 'max:2048', 'regex:/^[a-zA-Z0-9._~+\/-]*$/'],
            'map_credential.clear' => 'sometimes|boolean',
        ])['map_credential'] ?? null;
        if ($input && $input['provider'] !== $values['basemap']['provider']) {
            throw ValidationException::withMessages(['map_credential' => 'The tile key must belong to the selected provider.']);
        }
        if (($input['clear'] ?? false) && ($input['key'] ?? '') !== '') {
            throw ValidationException::withMessages(['map_credential' => 'Choose either a replacement key or removal of the saved key.']);
        }
        if (($input['provider'] ?? null) === 'mapbox' && ($input['key'] ?? '') !== '' && ! str_starts_with($input['key'], 'pk.')) {
            throw ValidationException::withMessages(['map_credential.key' => 'Use a Mapbox public token beginning with pk. Secret tokens are not supported.']);
        }
        $maps = app(Basemap::class);
        $maps->useDraftCredential($input);
        $provider = $values['basemap']['provider'];
        $needsKey = in_array($provider, ['maptiler', 'mapbox'], true) || ($provider === 'stadia' && $values['basemap']['authentication'] === 'key');
        if ($needsKey && $maps->key($provider) === '') {
            $maps->useDraftCredential(null);
            throw ValidationException::withMessages(['map_credential.key' => 'Add a browser tile key for this provider before previewing or saving.']);
        }

        return $input;
    }

    public function save(Request $r, WeatherSettings $s)
    {
        $r->validate(['revision' => 'required|integer|min:0', 'values' => 'required|array:identity,tiles,map,display,theme,thresholds,quips,basemap']);

        $values = $this->validateValues($r->input('values'));
        $credential = $this->mapCredential($r, $values);
        try {
            return response()->json([...$s->save($values, (int) $r->input('revision'), $credential), 'map_credentials' => app(Basemap::class)->credentialStatus()]);
        } finally {
            app(Basemap::class)->useDraftCredential(null);
        }
    }

    public function preview(Request $r, WeatherSettings $s, DisplayPresenter $presenter)
    {
        $r->validate(['values' => 'required|array:identity,tiles,map,display,theme,thresholds,quips,basemap']);
        $v = $this->validateValues($r->input('values'));
        $this->mapCredential($r, $v);
        $s->useDraft($v);
        try {
            $display = $presenter->present();

            return response()->view('display.weather', ['display' => $display, 'temperatureMoodPreview' => null, 'adminPreview' => true])->header('Cache-Control', 'no-store, private');
        } finally {
            app(Basemap::class)->useDraftCredential(null);
            $s->useDraft(null);
            $s->apply();
        }
    }

    public function locate(Request $r)
    {
        $d = $r->validate(['zip' => 'required|string|regex:/^\d{5}$/']);
        try {
            $result = Http::timeout(8)->get('https://api.zippopotam.us/us/'.$d['zip'])->throw()->json();
            $p = $result['places'][0];
        } catch (\Throwable $e) {
            return response()->json(['message' => 'ZIP lookup unavailable. Enter a location and coordinates directly.'], 422);
        }

        return response()->json(['location' => $p['place name'].', '.$p['state'], 'latitude' => (float) $p['latitude'], 'longitude' => (float) $p['longitude']]);
    }

    public function diagnostics(DisplayPresenter $presenter, WeatherSettings $s)
    {
        $model = $presenter->present();
        $statuses = CollectionStatus::query()->get()->keyBy('source');
        $sources = [];
        foreach (WeatherSources::definitions($model) as $id => $source) {
            $status = $statuses->get($id);
            $age = $status?->last_success_at?->diffInMinutes(now());
            $limit = $source['stale_minutes'];
            $sources[] = ['id' => $id, ...$source, 'status' => $status?->consecutive_failures ? 'degraded' : ($status?->last_success_at ? ($age > $limit ? 'stale' : 'ok') : 'not_configured'), 'last_success' => $status?->last_success_at?->toIso8601String(), 'last_failure' => $status?->last_failure_at?->toIso8601String(), 'failures' => $status?->consecutive_failures ?? 0];
        }
        $tick = Cache::get('weather.scheduler.last_tick');

        return response()->json(['release' => app(ReleaseIdentity::class)->data(), 'health' => $model['health'], 'last_observation' => $model['observation']['observed_at'] ?? null, 'sources' => $sources, 'scheduler' => ['last_tick' => $tick, 'status' => $tick ? (now()->timestamp - strtotime($tick) < 180 ? 'ok' : 'stale') : 'awaiting_heartbeat'], 'configuration_revision' => $s->read()['revision'], 'active_theme' => $s->activeTheme($s->read()['values']), 'refreshing' => Cache::has('weather.manual-refresh')]);
    }

    public function refresh()
    {
        $lock = Cache::lock('weather.manual-refresh', 180);
        if (! $lock->get()) {
            return response()->json(['message' => 'A refresh is already running.'], 409);
        }
        try {
            $station = Artisan::call('weather:collect');
            $forecast = Artisan::call('weather:forecast');
            cache()->forget('display.weather.payload');

            return response()->json(['message' => $station === 0 && $forecast === 0 ? 'Weather refreshed.' : 'A source could not refresh. Last good data is preserved.']);
        } finally {
            $lock->release();
        }
    }
}
