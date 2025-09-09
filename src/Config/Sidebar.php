<?php

return [
    "affinity"=>[
        "name"=>"Affinity",
        "icon"=>"fas fa-connectdevelop",
        "route_segment"=>"affinity",
        "permission"=>"affinity.admin",
        "entries"=>[
            [
                "name"=>"Intel Console",
                "icon"=>"fas fa-satellite-dish",
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
                "icon"=>"fas fa-id-card",
                "route"=>"affinity.test",
                "permission"=>"affinity.admin",
            ],
            [
                "name"=>"Settings",
                "icon"=>"fas fa-tools",
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