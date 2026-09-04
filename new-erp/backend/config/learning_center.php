<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Learning Center Training Count
    |--------------------------------------------------------------------------
    |
    | The Flutter app currently shows a small set of training cards on the
    | learning center landing page. Until the training module gets its own
    | backend source, this value keeps the top summary card consistent with
    | what users can see.
    |
    */
    'training_count' => env('LEARNING_CENTER_TRAINING_COUNT', 2),

    /*
    |--------------------------------------------------------------------------
    | Office Document Converter
    |--------------------------------------------------------------------------
    |
    | Course documents are normalized to PDF before being uploaded, so the app
    | can render them consistently and calculate reading progress by page.
    |
    */
    'office_converter' => [
        'windows_bin' => env('OFFICE_CONVERTER_WINDOWS_BIN', 'C:\\Program Files\\LibreOffice\\program\\soffice.exe'),
        'linux_bin' => env('OFFICE_CONVERTER_LINUX_BIN', '/opt/libreoffice7.6/program/soffice'),
    ],
];
