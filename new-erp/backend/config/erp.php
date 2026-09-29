<?php

return [
    'work_order_technical_attachment_disk' => env('ERP_TECHNICAL_ATTACHMENT_DISK', 'local'),
    'sales_order_attachment_disk' => env('ERP_ORDER_ATTACHMENT_DISK', 'oss'),
    'sales_order_attachment_prefix' => env('ERP_ORDER_ATTACHMENT_PREFIX', 'erp/sales-order'),
];
