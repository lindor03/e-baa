<?php

return [

    [
        'name' => 'Cars',
        'children' => [

            [
                'name' => 'Tires',
                'children' => [
                    [
                        'name' => 'Winter Tires',
                        'children' => [
                            ['name' => 'Studded Tires'],
                            ['name' => 'Non-Studded Tires'],
                        ],
                    ],
                    [
                        'name' => 'Summer Tires',
                        'children' => [
                            ['name' => 'Performance Tires'],
                            ['name' => 'Eco Tires'],
                        ],
                    ],
                    [
                        'name' => 'All Season Tires',
                    ],
                ],
            ],

            [
                'name' => 'Rims',
                'children' => [
                    ['name' => 'Alloy Rims'],
                    ['name' => 'Steel Rims'],
                    ['name' => 'Chrome Rims'],
                ],
            ],

            [
                'name' => 'Car Electronics',
                'children' => [
                    [
                        'name' => 'Audio Systems',
                        'children' => [
                            ['name' => 'Speakers'],
                            ['name' => 'Subwoofers'],
                            ['name' => 'Amplifiers'],
                        ],
                    ],
                    [
                        'name' => 'Car GPS',
                    ],
                    [
                        'name' => 'Dash Cameras',
                    ],
                ],
            ],

            [
                'name' => 'Interior Accessories',
                'children' => [
                    ['name' => 'Seat Covers'],
                    ['name' => 'Floor Mats'],
                    ['name' => 'Steering Wheel Covers'],
                ],
            ],

            [
                'name' => 'Exterior Accessories',
                'children' => [
                    ['name' => 'Car Covers'],
                    ['name' => 'Roof Racks'],
                    ['name' => 'Body Kits'],
                ],
            ],
        ],
    ],

    [
        'name' => 'Motorcycles',
        'children' => [
            [
                'name' => 'Helmets',
                'children' => [
                    ['name' => 'Full Face Helmets'],
                    ['name' => 'Open Face Helmets'],
                    ['name' => 'Modular Helmets'],
                ],
            ],
            [
                'name' => 'Motorcycle Tires',
                'children' => [
                    ['name' => 'Street Tires'],
                    ['name' => 'Off-Road Tires'],
                ],
            ],
            [
                'name' => 'Riding Gear',
                'children' => [
                    ['name' => 'Jackets'],
                    ['name' => 'Gloves'],
                    ['name' => 'Boots'],
                ],
            ],
        ],
    ],

    [
        'name' => 'Trucks & SUVs',
        'children' => [
            [
                'name' => 'Truck Tires',
                'children' => [
                    ['name' => 'All Terrain Tires'],
                    ['name' => 'Mud Terrain Tires'],
                ],
            ],
            [
                'name' => 'Lift Kits',
            ],
            [
                'name' => 'Tow Accessories',
                'children' => [
                    ['name' => 'Tow Bars'],
                    ['name' => 'Trailer Hitches'],
                ],
            ],
        ],
    ],

    [
        'name' => 'Car Care',
        'children' => [
            [
                'name' => 'Cleaning Products',
                'children' => [
                    ['name' => 'Car Shampoo'],
                    ['name' => 'Wax & Polish'],
                    ['name' => 'Interior Cleaners'],
                ],
            ],
            [
                'name' => 'Tools & Equipment',
                'children' => [
                    ['name' => 'Car Jacks'],
                    ['name' => 'Air Compressors'],
                    ['name' => 'Battery Chargers'],
                ],
            ],
        ],
    ],

    [
        'name' => 'Spare Parts',
        'children' => [
            [
                'name' => 'Engine Parts',
                'children' => [
                    ['name' => 'Spark Plugs'],
                    ['name' => 'Timing Belts'],
                    ['name' => 'Oil Filters'],
                ],
            ],
            [
                'name' => 'Brake System',
                'children' => [
                    ['name' => 'Brake Pads'],
                    ['name' => 'Brake Discs'],
                ],
            ],
            [
                'name' => 'Suspension',
                'children' => [
                    ['name' => 'Shock Absorbers'],
                    ['name' => 'Control Arms'],
                ],
            ],
        ],
    ],

];
