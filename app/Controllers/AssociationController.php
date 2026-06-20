<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AssociationService;
use App\Helpers\Response;

class AssociationController
{
    private AssociationService $service;

    public function __construct()
    {
        $this->service = new AssociationService();
    }

    public function create(): void
    {
        $data = $this->jsonBody();

        $documento = trim((string) ($data['documento'] ?? ''));
        $pdfId     = trim((string) ($data['pdf_id']    ?? ''));

        if ($documento === '' || $pdfId === '') {
            Response::error('Datos incompletos: se requiere documento y pdf_id.');
            return;
        }

        if (empty($_SESSION['persons'])) {
            Response::error('No hay registros cargados. Cargue un Excel primero.');
            return;
        }

        $persons      = &$_SESSION['persons'];
        $pdfs         = &$_SESSION['pdfs'];
        $associations = &$_SESSION['associations'];

        try {
            $this->service->associate($persons, $pdfs, $associations, $documento, $pdfId);
            $stats = $this->service->getStats($persons, $associations);

            Response::success([
                'persons'      => $persons,
                'pdfs'         => array_values($pdfs),
                'associations' => $associations,
                'stats'        => $stats,
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage());
        }
    }

    public function remove(): void
    {
        $data      = $this->jsonBody();
        $documento = trim((string) ($data['documento'] ?? ''));

        if ($documento === '') {
            Response::error('Se requiere el documento.');
            return;
        }

        if (empty($_SESSION['persons'])) {
            Response::error('No hay registros cargados.');
            return;
        }

        $persons      = &$_SESSION['persons'];
        $pdfs         = &$_SESSION['pdfs'];
        $associations = &$_SESSION['associations'];

        try {
            $this->service->remove($persons, $pdfs, $associations, $documento);
            $stats = $this->service->getStats($persons, $associations);

            Response::success([
                'persons'      => $persons,
                'pdfs'         => array_values($pdfs),
                'associations' => $associations,
                'stats'        => $stats,
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage());
        }
    }

    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
