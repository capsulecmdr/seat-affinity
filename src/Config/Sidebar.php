<?php

return [
    "affinity"=>[
        "name"=>"Affinity",
        "icon"=>"fas fa-hexagon-nodes",
        "route_segment"=>"affinity",
        "permission"=>"affinity.admin",
        "entries"=>[
            [
                "name"=>"Intel Console",
                "icon"=>"fas fa-light-emergency-on",
                "route"=>"affinity.test",
                "permission"=>"affinity.admin",
            ],
            [
                "name"=>"Trust Center",
                "icon"=>"fas fa-fingerprint",
                "route"=>"affinity.test",
                "permission"=>"affinity.admin",
            ],
            [
                "name"=>"Entity Manager",
                "icon"=>"fas fa-user-shield",
                "route"=>"affinity.test",
                "permission"=>"affinity.admin",
            ],
            [
                "name"=>"Settings",
                "icon"=>"fas fa-screwdriver-wrench",
                "route"=>"affinity.test",
                "permission"=>"affinity.admin",
            ],
            [
                "name"=>"About",
                "icon"=>"fas fa-info",
                "route"=>"affinity.test",
                "permission"=>"affinity.admin",
            ],
        ],
    ]
];