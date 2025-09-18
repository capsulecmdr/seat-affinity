<?php

use Illuminate\Support\Facades\App;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Seat\Notifications\Models\NotificationGroup;


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

/**
 * Send a notification to all integrations subscribed to an alert key.
 *
 * @param string $alertKey           e.g. 'affinity.corp_changed'
 * @param string $notificationClass  FQCN of a Laravel Notification
 * @param array  $notificationArgs   Constructor args for the Notification
 * @param bool   $sendNow            true => sendNow (sync), false => send (queued if ShouldQueue)
 * @return int                       Number of notifications attempted
 * 
 * 
 * 
 * // Example: send a Discord-ready notification to all subscribers of an alert
*    affinity_notify(
*        'affinity.corp_changed',
*        \CapsuleCmdr\Affinity\Notifications\CorpChangeDiscordNotification::class,
*        ['User X', 'Orsiki', 'character', now()],
*        false // set true to send synchronously
*    );
 * 
 */
function affinity_notify(string $alertKey, string $notificationClass, array $notificationArgs = [], bool $sendNow = false): int
{
    // Find groups subscribed to this alert
    $groups = NotificationGroup::whereHas('alerts', fn($q) => $q->where('alert', $alertKey))
        ->with('integrations')
        ->get();

    if ($groups->isEmpty()) {
        return -1;
    }

    $sent = 0;

    foreach ($groups as $group) {
        foreach ($group->integrations as $integration) {
            // Minimal route resolution: first value in settings array
            $settings = (array) ($integration->settings ?? []);
            $firstKey = array_key_first($settings);
            $route = $firstKey ? ($settings[$firstKey] ?? null) : null;
            if (! $route) {
                continue;
            }

            $anon = (new AnonymousNotifiable)->route($integration->type, $route);
            $notification = new $notificationClass(...$notificationArgs);

            $sendNow
                ? Notification::sendNow($anon, $notification)
                : Notification::send($anon, $notification);

            $sent++;
        }
    }

    return $sent;
}

