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
 *  affinity_notify(
 *      'affinity.alert_contact',
 *      ['TesterUser','Orsiki','character', now()],  // args your Notification expects
 *      true                                          // send synchronously for testing
 *  );
 *
 * @param string      $alertKey         e.g. 'affinity.alert_contact'
 * @param array       $notificationArgs Constructor args for the Notification (optional)
 * @param bool        $sendNow          true => sendNow (sync), false => send (queued if ShouldQueue)
 * @param string|null $onlyHandlerType  e.g. 'discord' to restrict to that integration type (optional)
 * @return int                          Number of notifications attempted
 */
function affinity_notify(string $alertKey, array $notificationArgs = [], bool $sendNow = false, ?string $onlyHandlerType = null): int
{
    $map = config('notifications.alerts');
    if (! isset($map[$alertKey]['handlers']) || ! is_array($map[$alertKey]['handlers'])) {
        return 0;
    }

    // Find groups subscribed to this alert
    $groups = NotificationGroup::whereHas('alerts', fn ($q) => $q->where('alert', $alertKey))
        ->with('integrations')
        ->get();

    if ($groups->isEmpty()) {
        return 0;
    }

    $sent = 0;

    foreach ($groups as $group) {
        foreach ($group->integrations as $integration) {
            $type = (string) ($integration->type ?? '');

            // If caller wants to limit to a specific handler/integration type
            if ($onlyHandlerType && $type !== $onlyHandlerType) {
                continue;
            }

            // Resolve the Notification class from config by integration type
            $handlers = $map[$alertKey]['handlers'];
            if (! isset($handlers[$type]) || ! class_exists($handlers[$type])) {
                continue;
            }
            $notificationClass = $handlers[$type];

            // Minimal route resolution: take the first value from settings array
            $settings = (array) ($integration->settings ?? []);
            $firstKey = array_key_first($settings);
            $route = $firstKey ? ($settings[$firstKey] ?? null) : null;
            if (! $route) {
                continue;
            }

            $anon = (new AnonymousNotifiable)->route($type, $route);
            $notification = new $notificationClass(...$notificationArgs);

            $sendNow
                ? Notification::sendNow($anon, $notification)
                : Notification::send($anon, $notification);

            $sent++;
        }
    }

    return $sent;
}


