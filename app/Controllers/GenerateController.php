<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PdfService;
use App\Services\AssociationService;
use App\Helpers\Response;

class GenerateController
{
    private PdfService        $pdfService;
    private AssociationService $assocService;

    public function __construct()
    {
        $this->pdfService   = new PdfService();
        $this->assocService = new AssociationService();
    }

    public function generate(): void
    {
        $persons      = $_SESSION['persons']      ?? [];
        $pdfs         = $_SESSION['pdfs']         ?? [];
        $associations = $_SESSION['associations'] ?? [];

        if (empty($persons)) {
            Response::error('No hay registros cargados. Cargue un Excel primero.');
            return;
        }

        $stats = $this->assocService->getStats($persons, $associations);

        if ($stats['pending'] > 0) {
            Response::error(
                "Faltan {$stats['pending']} registro(s) sin PDF asociado. " .
                'Complete todas las asociaciones antes de generar.'
            );
            return;
        }

        // Check for unused PDFs
        $unusedPdfs = 0;
        foreach ($pdfs as $pdf) {
            if ($pdf['status'] === 'pending') {
                $unusedPdfs++;
            }
        }

        // Remove old generated file
        if (!empty($_SESSION['generated_pdf']) && file_exists($_SESSION['generated_pdf'])) {
            @unlink($_SESSION['generated_pdf']);
        }

        try {
            $result = $this->pdfService->generate($persons, $associations, $pdfs);
            $_SESSION['generated_pdf'] = $result['path'];

            Response::success([
                'message'     => 'PDF consolidado generado exitosamente.',
                'ready'       => true,
                'unused_pdfs' => $unusedPdfs,
                'warnings'    => $result['skipped'],
            ]);
        } catch (\Exception $e) {
            Response::error('Error al generar el PDF: ' . $e->getMessage());
        }
    }

    public function download(): void
    {
        $path = $_SESSION['generated_pdf'] ?? null;

        if (!$path || !file_exists($path)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'PDF no disponible. Genérelo primero.']);
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Cedulas_Ordenadas.pdf"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-cache');
        header('Pragma: private');

        readfile($path);
    }
}
