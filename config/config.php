<?php
return [
    'app_env' => 'local',
    'session_name' => 'cronoapp_session',
    'cors' => [
        'allowed_origins' => [
            'http://localhost:3001',
            'https://www.cronofr.it',
            'https://cronofr.it'
        ],
    ],
    'db_' => [
        'host' => '31.11.39.157',
        'port' => 3306,
        'dbname' => 'Sql1920973_2',
        'username' => 'Sql1920973',
        'password' => '!Kronos2026',
        'charset' => 'utf8mb4',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'cronofr',
        'username' => 'root',
        'password' => 'Avis2025@',
        'charset' => 'utf8mb4',
    ],
    'new_admin_vincenzo' => [
        "id" => 1,
        "ID" => 1,
        "email" => "vincenzo.nicolella.1970@gmail.com",
        "username" => "Vincenzo1970",
        "first_name" => "Vincenzo",
        "last_name" => "Nicolella",
        "nickname" => "Vincenzo1970",
        "display_name" => "Vincenzo1970",
        "roles" => [
            "administrator"
        ]
    ],
    'new_admin_lorenzo' => [
        "id" => 5,
        "ID" => 5,
        "email" => "lorenzo@info.it",
        "username" => "LorenzoRecina",
        "first_name" => "Lorenzo",
        "last_name" => "Recina",
        "nickname" => "LorenzoRecina",
        "display_name" => "Lorenzo Recina",
        "roles" => [
            "administrator"
        ]
    ],
    'new_socio_mariorossi' => [
        "id" => 3,
        "ID" => 3,
        "email" => "sociomariorossi@parrocchiasgibattisita.it",
        "username" => "SocioMarioRossi",
        "first_name" => "Mario",
        "last_name" => "Rossi",
        "nickname" => "SocioMarioRossi",
        "display_name" => "Mario Rossi",
        "roles" => ["socio"]
    ]
];
