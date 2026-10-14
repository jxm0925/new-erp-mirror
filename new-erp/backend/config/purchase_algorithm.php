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
    // 采购价格预警：本次单价比历史采购价高出该百分比时，在提交、详情和审批中标红提示。
    'price_alert_percent' => (float) env('PURCHASE_PRICE_ALERT_PERCENT', 5),
];
