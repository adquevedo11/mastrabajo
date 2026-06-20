<?php

declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('STORAGE_PATH', APP_ROOT . '/storage');
define('UPLOADS_EXCEL', STORAGE_PATH . '/uploads/excel');
define('UPLOADS_PDFS', STORAGE_PATH . '/uploads/pdfs');
define('STORAGE_TEMP', STORAGE_PATH . '/temp');
define('STORAGE_GENERATED', STORAGE_PATH . '/generated');

$dirs = [UPLOADS_EXCEL, UPLOADS_PDFS, STORAGE_TEMP, STORAGE_GENERATED];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

require_once APP_ROOT . '/vendor/autoload.php';
