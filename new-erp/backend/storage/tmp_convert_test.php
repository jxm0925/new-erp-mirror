<?php
use NcJoes\OfficeConverter\OfficeConverter;
$src = base_path('vendor/ncjoes/office-converter/tests/sources/test1.docx');
$out = storage_path('app/tmp/converter-test-com');
Illuminate\Support\Facades\File::ensureDirectoryExists($out);
$bin = 'C:\\Program Files\\LibreOffice\\program\\soffice.com';
$_SERVER['HOME'] = str_replace('\\', '/', storage_path('app/tmp/libreoffice-home'));
$converter = new OfficeConverter($src, $out, $bin);
$result = $converter->convertTo('result.pdf');
dump(['result' => $result, 'exists' => is_file($result)]);
