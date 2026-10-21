<?php

return [
    'work_order_technical_attachment_disk' => env('ERP_TECHNICAL_ATTACHMENT_DISK', 'local'),
    'sales_order_attachment_disk' => env('ERP_ORDER_ATTACHMENT_DISK', 'oss'),
    // 总装完工、订单马上发货时自动记入的“成品待发区”库位编码；不配则找名称含“待发区”的库位。
    'ship_staging_location_code' => env('ERP_SHIP_STAGING_LOCATION', ''),
    'sales_order_attachment_prefix' => env('ERP_ORDER_ATTACHMENT_PREFIX', 'erp/sales-order'),
];
