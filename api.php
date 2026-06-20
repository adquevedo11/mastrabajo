<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/bootstrap.php';

use App\Helpers\Response;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

$path   = trim($_GET['path'] ?? '', '/');
$method = $_SERVER['REQUEST_METHOD'];

try {
    // PDF file serving (no JSON header)
    if ($method === 'GET' && preg_match('/^pdf\/file\/([0-9a-f-]{36})$/i', $path, $m)) {
        $controller = new App\Controllers\PdfController();
        $controller->file($m[1]);
        exit;
    }

    // Download generated PDF (no JSON header)
    if ($method === 'GET' && $path === 'download') {
        $controller = new App\Controllers\GenerateController();
        $controller->download();
        exit;
    }

    // Download Excel template (no JSON header)
    if ($method === 'GET' && $path === 'excel/template') {
        $controller = new App\Controllers\ExcelController();
        $controller->template();
        exit;
    }

    // Download sorted Excel (no JSON header)
    if ($method === 'GET' && $path === 'excel/sorted') {
        $controller = new App\Controllers\ExcelController();
        $controller->downloadSorted();
        exit;
    }

    // All remaining endpoints respond with JSON
    header('Content-Type: application/json; charset=utf-8');

    match (true) {
        $method === 'POST' && $path === 'excel/upload' => (function () {
            (new App\Controllers\ExcelController())->upload();
        })(),

        $method === 'GET' && $path === 'excel/data' => (function () {
            (new App\Controllers\ExcelController())->getData();
        })(),

        $method === 'POST' && $path === 'pdf/upload' => (function () {
            (new App\Controllers\PdfController())->upload();
        })(),

        $method === 'POST' && $path === 'pdf/remove' => (function () {
            (new App\Controllers\PdfController())->remove();
        })(),

        $method === 'POST' && $path === 'association/create' => (function () {
            (new App\Controllers\AssociationController())->create();
        })(),

        $method === 'POST' && $path === 'association/remove' => (function () {
            (new App\Controllers\AssociationController())->remove();
        })(),

        $method === 'POST' && $path === 'generate' => (function () {
            (new App\Controllers\GenerateController())->generate();
        })(),

        $method === 'POST' && $path === 'reset' => (function () {
            if (!empty($_SESSION['pdfs'])) {
                foreach ($_SESSION['pdfs'] as $pdf) {
                    if (file_exists($pdf['path'])) {
                        @unlink($pdf['path']);
                    }
                }
            }
            if (!empty($_SESSION['generated_pdf']) && file_exists($_SESSION['generated_pdf'])) {
                @unlink($_SESSION['generated_pdf']);
            }
            $_SESSION = [];
            session_destroy();
            echo json_encode(['success' => true, 'message' => 'Sesión reiniciada.']);
        })(),

        default => (function () use ($path, $method) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Ruta no encontrada: {$method} /{$path}"]);
        })(),
    };
} catch (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'error'   => 'Error interno del servidor: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
