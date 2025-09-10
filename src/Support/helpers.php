<?php

use Illuminate\Support\Facades\App;

if (! function_exists('affinity_setting')) {
    function affinity_setting(string $key, ?string $default = null): ?string {
        return App::make('affinity.settings')->get($key, $default);
    }
}

if (! function_exists('affinity_setting_set')) {
    function affinity_setting_set(string $key, ?string $value): void {
        App::make('affinity.settings')->set($key, $value);
    }
}

if (! function_exists('affinity_setting_delete')) {
    function affinity_setting_delete(string $key): bool {
        return App::make('affinity.settings')->delete($key);
    }
}
