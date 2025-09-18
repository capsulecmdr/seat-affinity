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
    'affinity.alert_contact' => [
        'label' => 'affinity::notifications.contact_alert',
        'handlers' => [
            'discord' => \CapsuleCmdr\Affinity\Notifications\Discord\ContactAlert::class,
        ],
    ],
    'affinity.alert_contract' => [
        'label' => 'affinity::notifications.contract_alert',
        'handlers' => [
            'discord' => \CapsuleCmdr\Affinity\Notifications\Discord\ContractHistoryAlert::class,
        ],
    ],
    'affinity.alert_corporation' => [
        'label' => 'affinity::notifications.corporation_alert',
        'handlers' => [
            'discord' => \CapsuleCmdr\Affinity\Notifications\Discord\CorporationHistoryAlert::class,
        ],
    ],
    'affinity.alert_mail' => [
        'label' => 'affinity::notifications.mail_alert',
        'handlers' => [
            'discord' => \CapsuleCmdr\Affinity\Notifications\Discord\MailHistoryAlert::class,
        ],
    ],
    'affinity.notification_corp_changed' => [
        'label' => 'affinity::notifications.corp_changed',
        'handlers' => [
            'discord' => \CapsuleCmdr\Affinity\Notifications\Discord\CorporationChanged::class,
        ],
    ],
];