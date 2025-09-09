<?php
// return [
//     'osmm.maintenance_toggled' => [
//         'label' => 'osmm::notifications.maintenance_toggled',
//         'handlers' => [
//             'mail'    => \CapsuleCmdr\SeatOsmm\Notifications\Mail\MaintenanceToggled::class,
//             'slack'   => \CapsuleCmdr\SeatOsmm\Notifications\Slack\MaintenanceToggled::class,
//             'discord' => \CapsuleCmdr\SeatOsmm\Notifications\Discord\MaintenanceToggled::class,
//         ],
//     ],
// ];

return [
    'affinity.example_alert' => [
        'label' => 'affinity::notifications.example_alert',
        'handlers' => [
            'discord' => \CapsuleCmdr\Affinity\Notifications\Discord\ExampleAlert::class,
        ],
    ],
];