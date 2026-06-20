<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ExcelService;
use App\Helpers\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class ExcelController
{
    private ExcelService $service;

    public function __construct()
    {
        $this->service = new ExcelService();
    }

    public function upload(): void
    {
        if (!isset($_FILES['excel'])) {
            Response::error('No se recibió ningún archivo Excel.');
            return;
        }

        $file = $_FILES['excel'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error($this->uploadErrorMessage($file['error']));
            return;
        }

        if ($file['size'] === 0) {
            Response::error('El archivo Excel está vacío.');
            return;
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'xls'], true)) {
            Response::error('Solo se permiten archivos Excel (.xlsx, .xls).');
            return;
        }

        $tempPath = UPLOADS_EXCEL . '/' . uniqid('excel_', true) . '.' . $extension;

        if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
            Response::error('No se pudo guardar el archivo Excel en el servidor.');
            return;
        }

        try {
            $persons = $this->service->process($tempPath);

            // Reset session state when a new Excel is uploaded
            $_SESSION['persons']       = $persons;
            $_SESSION['pdfs']          = $_SESSION['pdfs']         ?? [];
            $_SESSION['associations']  = [];
            $_SESSION['generated_pdf'] = null;

            // Reset PDF statuses if Excel changed
            foreach ($_SESSION['pdfs'] as &$pdf) {
                $pdf['status']     = 'pending';
                $pdf['person_doc'] = null;
            }
            unset($pdf);

            // Also reset person statuses
            foreach ($_SESSION['persons'] as &$person) {
                $person['status'] = 'pending';
                $person['pdf_id'] = null;
            }
            unset($person);

            Response::success([
                'persons' => $persons,
                'count'   => count($persons),
                'message' => count($persons) . ' registros cargados y ordenados correctamente.',
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage());
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    public function downloadSorted(): void
    {
        $persons = $_SESSION['persons'] ?? [];

        if (empty($persons)) {
            http_response_code(400);
            echo 'No hay datos cargados. Cargue el Excel primero.';
            return;
        }

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Personas Ordenadas');

        // ── Encabezados ───────────────────────────────────────
        $sheet->setCellValue('A1', 'Orden');
        $sheet->setCellValue('B1', 'Nombres');
        $sheet->setCellValue('C1', 'Apellidos');
        $sheet->setCellValue('D1', 'Documento');

        // ── Datos ─────────────────────────────────────────────
        foreach ($persons as $idx => $person) {
            $row = $idx + 2;
            $sheet->setCellValue("A{$row}", (int) $person['order']);
            $sheet->setCellValue("B{$row}", (string) $person['nombres']);
            $sheet->setCellValue("C{$row}", (string) $person['apellidos']);
            $sheet->setCellValue("D{$row}", (string) $person['documento']);
        }

        $lastRow = count($persons) + 1;

        // ── Estilo encabezado ─────────────────────────────────
        $sheet->getStyle('A1:D1')->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size'  => 11,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E3A8A'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // ── Filas alternas ────────────────────────────────────
        for ($r = 2; $r <= $lastRow; $r++) {
            $color = ($r % 2 === 0) ? 'EFF6FF' : 'FFFFFF';
            $sheet->getStyle("A{$r}:D{$r}")->applyFromArray([
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $color],
                ],
            ]);
        }

        // ── Bordes ────────────────────────────────────────────
        $sheet->getStyle("A1:D{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'BFDBFE'],
                ],
            ],
        ]);

        // ── Alinear columna Orden al centro ───────────────────
        $sheet->getStyle("A2:A{$lastRow}")->getAlignment()
              ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // ── Anchos de columna ─────────────────────────────────
        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(28);
        $sheet->getColumnDimension('D')->setWidth(18);

        // ── Altura encabezado y congelar fila ─────────────────
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->freezePane('A2');

        // ── Autofilter ────────────────────────────────────────
        $sheet->setAutoFilter("A1:D{$lastRow}");

        // ── Servir el archivo ─────────────────────────────────
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Personas_Ordenadas.xlsx"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    public function getData(): void
    {
        $persons      = $_SESSION['persons']      ?? [];
        $pdfs         = $_SESSION['pdfs']         ?? [];
        $associations = $_SESSION['associations'] ?? [];

        $stats = [
            'total'      => count($persons),
            'associated' => count($associations),
            'pending'    => count($persons) - count($associations),
            'percentage' => count($persons) > 0
                ? (int) round((count($associations) / count($persons)) * 100)
                : 0,
        ];

        Response::success([
            'persons'      => $persons,
            'pdfs'         => array_values($pdfs),
            'associations' => $associations,
            'stats'        => $stats,
            'generated'    => !empty($_SESSION['generated_pdf']),
        ]);
    }

    public function template(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Personas');

        // Headers
        $sheet->setCellValue('A1', 'Nombres');
        $sheet->setCellValue('B1', 'Apellidos');
        $sheet->setCellValue('C1', 'Documento');

        // Example data
        $rows = [
            ['Andrés Felipe',    'Quevedo Vargas',    '11111111'],
            ['Carlos Alberto',   'Pérez Gómez',       '22222222'],
            ['María Alejandra',  'Rodríguez López',   '33333333'],
            ['Pedro',            'Gómez Torres',      '44444444'],
        ];
        foreach ($rows as $i => $row) {
            $r = $i + 2;
            $sheet->setCellValue("A{$r}", $row[0]);
            $sheet->setCellValue("B{$r}", $row[1]);
            $sheet->setCellValue("C{$r}", $row[2]);
        }

        // Header style
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size'  => 11,
            ],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E3A8A'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // Data rows style
        $sheet->getStyle('A2:C5')->applyFromArray([
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EFF6FF'],
            ],
        ]);

        // Border around table
        $sheet->getStyle('A1:C5')->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color'       => ['rgb' => 'BFDBFE'],
                ],
            ],
        ]);

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(18);

        // Row height
        $sheet->getRowDimension(1)->setRowHeight(22);

        // Freeze header row
        $sheet->freezePane('A2');

        // Comment / note about the format
        $sheet->getComment('A1')->getText()->createTextRun(
            'Puede contener un nombre o dos nombres separados por espacio.'
        );
        $sheet->getComment('B1')->getText()->createTextRun(
            'Puede contener un apellido o dos apellidos separados por espacio.'
        );
        $sheet->getComment('C1')->getText()->createTextRun(
            'Número de documento sin puntos ni guiones. Debe ser único.'
        );

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Plantilla_Cedulas.xlsx"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $writer = new XlsxWriter($spreadsheet);
        $writer->save('php://output');
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'El archivo supera el límite de tamaño del servidor.',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el límite de tamaño del formulario.',
            UPLOAD_ERR_PARTIAL    => 'El archivo se cargó parcialmente. Intente de nuevo.',
            UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Error interno: no hay directorio temporal disponible.',
            UPLOAD_ERR_CANT_WRITE => 'Error interno: no se puede escribir el archivo.',
            default               => 'Error desconocido al cargar el archivo.',
        };
    }
}
