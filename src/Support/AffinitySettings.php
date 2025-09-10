<?php

namespace CapsuleCmdr\Affinity\Support;

use CapsuleCmdr\Affinity\Models\AffinitySetting;
use Illuminate\Support\Facades\Cache;

class AffinitySettings
{
    protected string $cachePrefix = 'affinity.settings.';
    protected int $ttl = 0; // 0 = forever

    protected function key(string $k): string { return $this->cachePrefix.$k; }

    /** Get a setting (returns string|null unless you pass a default) */
    public function get(string $key, ?string $default = null): ?string
    {
        $ck = $this->key($key);
        $loader = fn () => optional(
            AffinitySetting::where('key', $key)->first()
        )->value ?? $default;

        return $this->ttl > 0
            ? Cache::remember($ck, $this->ttl, $loader)
            : Cache::rememberForever($ck, $loader);
    }

    /** Create or update */
    public function set(string $key, ?string $value): void
    {
        AffinitySetting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget($this->key($key));
    }

    /** Update only if it exists */
    public function update(string $key, ?string $value): bool
    {
        $row = AffinitySetting::where('key', $key)->first();
        if (! $row) return false;
        $row->value = $value;
        $row->save();
        Cache::forget($this->key($key));
        return true;
    }

    public function delete(string $key): bool
    {
        $row = AffinitySetting::where('key', $key)->first();
        if (! $row) return false;
        $row->delete();
        Cache::forget($this->key($key));
        return true;
    }

    public function has(string $key): bool
    {
        return AffinitySetting::where('key', $key)->exists();
    }

    /** Get all as [key => value] */
    public function all(): array
    {
        return AffinitySetting::orderBy('key')
            ->get(['key','value'])
            ->mapWithKeys(fn($s)=>[$s->key => $s->value])
            ->toArray();
    }

    /** Convenience: cast JSON strings to array when reading */
    public function getJson(string $key, $default = null)
    {
        $raw = $this->get($key);
        if ($raw === null || $raw === '') return $default;
        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    /** Convenience: encode arrays/objects as JSON before saving */
    public function setJson(string $key, $value): void
    {
        $this->set($key, json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
}
