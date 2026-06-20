<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PdfService;
use App\Helpers\Response;

class PdfController
{
    private PdfService $service;

    public function __construct()
    {
        $this->service = new PdfService();
    }

    public function upload(): void
    {
        if (empty($_FILES['pdfs'])) {
            Response::error('No se recibieron archivos PDF.');
            return;
        }

        $files    = $this->normalizeFiles($_FILES['pdfs']);
        $uploaded = [];
        $errors   = [];

        // Build a hash map of already-stored PDFs to detect duplicates
        $existingHashes = [];
        foreach ($_SESSION['pdfs'] ?? [] as $pdf) {
            if (!empty($pdf['hash'])) {
                $existingHashes[$pdf['hash']] = $pdf['original_name'];
            }
        }

        foreach ($files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "Error al cargar '{$file['name']}'.";
                continue;
            }

            try {
                $pdfInfo = $this->service->store($file);

                // Reject exact duplicate files (same content already loaded)
                if (!empty($pdfInfo['hash']) && isset($existingHashes[$pdfInfo['hash']])) {
                    @unlink($pdfInfo['path']);
                    $errors[] = "'{$file['name']}' es idéntico a '{$existingHashes[$pdfInfo['hash']]}' que ya está cargado. No se duplicó.";
                    continue;
                }

                $_SESSION['pdfs'][$pdfInfo['id']] = $pdfInfo;
                $existingHashes[$pdfInfo['hash']] = $pdfInfo['original_name'];
                $uploaded[] = $pdfInfo;
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (empty($uploaded) && !empty($errors)) {
            Response::error(implode(' | ', $errors));
            return;
        }

        Response::success([
            'pdfs'    => $uploaded,
            'errors'  => $errors,
            'message' => count($uploaded) . ' archivo(s) PDF cargado(s) correctamente.',
        ]);
    }

    public function remove(): void
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true) ?: [];
        $id   = trim((string) ($data['pdf_id'] ?? ''));

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            Response::error('ID de PDF inválido.');
            return;
        }

        if (!isset($_SESSION['pdfs'][$id])) {
            Response::error('PDF no encontrado en la sesión.');
            return;
        }

        $pdfInfo   = $_SESSION['pdfs'][$id];
        $documento = $pdfInfo['person_doc'];

        // If associated, clear the association and restore person to pending
        if ($documento !== null) {
            $persons      = &$_SESSION['persons'];
            $pdfs         = &$_SESSION['pdfs'];
            $associations = &$_SESSION['associations'];
            (new \App\Services\AssociationService())->remove($persons, $pdfs, $associations, (string) $documento);
        }

        // Delete physical file from disk
        if (file_exists($pdfInfo['path'])) {
            @unlink($pdfInfo['path']);
        }

        // Remove entry from session
        unset($_SESSION['pdfs'][$id]);

        $persons      = $_SESSION['persons']      ?? [];
        $associations = $_SESSION['associations'] ?? [];
        $allPdfs      = $_SESSION['pdfs']         ?? [];

        $stats = (new \App\Services\AssociationService())->getStats($persons, $associations);

        Response::success([
            'persons'      => $persons,
            'pdfs'         => array_values($allPdfs),
            'associations' => $associations,
            'stats'        => $stats,
        ]);
    }

    public function file(string $id): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            http_response_code(400);
            echo 'ID inválido.';
            return;
        }

        $pdfs = $_SESSION['pdfs'] ?? [];

        if (!isset($pdfs[$id])) {
            http_response_code(404);
            echo 'PDF no encontrado en sesión.';
            return;
        }

        $pdfInfo = $pdfs[$id];

        if (!file_exists($pdfInfo['path'])) {
            http_response_code(404);
            echo 'Archivo PDF no encontrado en servidor.';
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . addslashes($pdfInfo['original_name']) . '"');
        header('Content-Length: ' . filesize($pdfInfo['path']));
        header('Cache-Control: private, max-age=3600');
        header('Accept-Ranges: bytes');

        readfile($pdfInfo['path']);
    }

    private function normalizeFiles(array $files): array
    {
        $result = [];

        if (is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                $result[] = [
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ];
            }
        } else {
            $result[] = $files;
        }

        return $result;
    }
}
