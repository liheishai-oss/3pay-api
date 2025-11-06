<?php
return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root'   => public_path() .('app'),
        ],
        'public' => [
            'driver' => 'local',
            'root'   => public_path(),
        ],
    ],
];
