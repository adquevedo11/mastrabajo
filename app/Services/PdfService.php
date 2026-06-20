<?php

declare(strict_types=1);

namespace App\Services;

use setasign\Fpdi\Fpdi;

class PdfService
{
    public function store(array $file): array
    {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($extension !== 'pdf') {
            throw new \Exception("El archivo '{$file['name']}' no es un PDF.");
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if ($mimeType !== 'application/pdf') {
            throw new \Exception("El archivo '{$file['name']}' no es un PDF válido.");
        }

        $hash        = hash_file('sha256', $file['tmp_name']);
        $id          = $this->uuid();
        $storedName  = $id . '.pdf';
        $storedPath  = UPLOADS_PDFS . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
            throw new \Exception("No se pudo guardar el archivo '{$file['name']}'.");
        }

        $pageCount = $this->countPages($storedPath);

        return [
            'id'            => $id,
            'original_name' => basename($file['name']),
            'stored_name'   => $storedName,
            'path'          => $storedPath,
            'pages'         => $pageCount,
            'status'        => 'pending',
            'person_doc'    => null,
            'hash'          => $hash,
        ];
    }

    public function countPages(string $filePath): int
    {
        try {
            $pdf = new Fpdi();
            return $pdf->setSourceFile($filePath);
        } catch (\Exception) {
            return 1;
        }
    }

    public function generate(array $persons, array $associations, array $pdfs): string
    {
        $outputPath = STORAGE_GENERATED . '/Cedulas_Ordenadas.pdf';

        // Guarantee correct order regardless of session array state
        usort($persons, fn($a, $b) => (int) $a['order'] - (int) $b['order']);

        $fpdi = new Fpdi('P', 'mm');
        $fpdi->SetMargins(0, 0, 0);
        $fpdi->SetAutoPageBreak(false, 0);

        $pagesAdded  = 0;
        $usedPdfIds  = [];

        foreach ($persons as $person) {
            $pdfId = $associations[(string) $person['documento']] ?? null;
            if ($pdfId === null) {
                continue;
            }

            // Guard: skip if this exact PDF was already added (should not happen, but safety net)
            if (isset($usedPdfIds[$pdfId])) {
                continue;
            }

            $pdfInfo = $pdfs[$pdfId] ?? null;
            if ($pdfInfo === null || !file_exists($pdfInfo['path'])) {
                continue;
            }

            try {
                $pageCount = $fpdi->setSourceFile($pdfInfo['path']);
            } catch (\Exception) {
                continue;
            }

            $usedPdfIds[$pdfId] = true;

            for ($i = 1; $i <= $pageCount; $i++) {
                try {
                    $templateId  = $fpdi->importPage($i);
                    $size        = $fpdi->getTemplateSize($templateId);
                    $w           = (float) $size['width'];
                    $h           = (float) $size['height'];
                    $orientation = $w > $h ? 'L' : 'P';

                    $fpdi->AddPage($orientation, [$w, $h]);
                    $fpdi->useTemplate($templateId, 0, 0, $w, $h, true);
                    $pagesAdded++;
                } catch (\Exception) {
                    // Skip unreadable page, continue with next
                }
            }
        }

        if ($pagesAdded === 0) {
            throw new \Exception('No se pudo procesar ningún PDF. Verifique que los archivos no estén protegidos con contraseña.');
        }

        $fpdi->Output('F', $outputPath);

        return $outputPath;
    }

    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
