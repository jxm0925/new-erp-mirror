<?php

return [
    'supplier_recommendation' => [
        'weights' => [
            'price' => 40,
            'quality' => 25,
            'delivery' => 20,
            'return' => 10,
            'cooperation' => 5,
        ],
        'no_history_score' => 60,
        'price_abnormal_threshold' => 0.12,
    ],
];
