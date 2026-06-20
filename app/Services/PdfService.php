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

        // Ensure the PDF can be read by FPDI; try Ghostscript conversion if needed.
        // If incompatible and GS is unavailable, this deletes the stored file and throws.
        $this->ensureFpdiCompatible($storedPath, basename($file['name']));

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

    /**
     * @return array{path: string, skipped: string[]}
     */
    public function generate(array $persons, array $associations, array $pdfs): array
    {
        $outputPath = STORAGE_GENERATED . '/Cedulas_Ordenadas.pdf';

        // Guarantee correct order regardless of session array state
        usort($persons, fn($a, $b) => (int) $a['order'] - (int) $b['order']);

        $fpdi = new Fpdi('P', 'mm');
        $fpdi->SetMargins(0, 0, 0);
        $fpdi->SetAutoPageBreak(false, 0);

        $pagesAdded = 0;
        $usedPdfIds = [];
        $skipped    = [];

        foreach ($persons as $person) {
            $pdfId = $associations[(string) $person['documento']] ?? null;
            if ($pdfId === null) {
                continue;
            }

            if (isset($usedPdfIds[$pdfId])) {
                continue;
            }

            $pdfInfo    = $pdfs[$pdfId] ?? null;
            $personName = trim(($person['nombres'] ?? '') . ' ' . ($person['apellidos'] ?? ''));

            if ($pdfInfo === null || !file_exists($pdfInfo['path'])) {
                $skipped[] = "$personName: archivo PDF no encontrado en el servidor.";
                continue;
            }

            try {
                $pageCount = $fpdi->setSourceFile($pdfInfo['path']);
            } catch (\Exception $e) {
                $skipped[] = "$personName: no se pudo leer el PDF — " . $e->getMessage();
                continue;
            }

            $usedPdfIds[$pdfId] = true;

            for ($i = 1; $i <= $pageCount; $i++) {
                try {
                    // Use MEDIA_BOX explicitly: every valid PDF must have one,
                    // avoiding the CROP_BOX intermediate lookup that can fail on some scanned PDFs.
                    $templateId  = $fpdi->importPage($i, \setasign\Fpdi\PdfReader\PageBoundaries::MEDIA_BOX);
                    $size        = $fpdi->getTemplateSize($templateId);
                    $w           = (float) $size['width'];
                    $h           = (float) $size['height'];
                    $orientation = $w > $h ? 'L' : 'P';

                    $fpdi->AddPage($orientation, [$w, $h]);
                    $fpdi->useTemplate($templateId, 0, 0, $w, $h, true);
                    $pagesAdded++;
                } catch (\Exception $e) {
                    $skipped[] = "$personName (pág. $i): " . $e->getMessage();
                }
            }
        }

        if ($pagesAdded === 0) {
            throw new \Exception('No se pudo procesar ningún PDF. Verifique que los archivos no estén protegidos con contraseña.');
        }

        $fpdi->Output('F', $outputPath);

        return ['path' => $outputPath, 'skipped' => $skipped];
    }

    private function ensureFpdiCompatible(string $path, string $originalName): void
    {
        try {
            $fpdi = new Fpdi();
            $fpdi->setSourceFile($path);
            return;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            // Only handle FPDI's "compressed XRef / unsupported parser" error
            if (stripos($msg, 'compression technique') === false &&
                stripos($msg, 'free parser') === false) {
                return; // Different error — generate() will surface it with context
            }
        }

        // Try Ghostscript auto-conversion to PDF 1.4
        $gs = $this->findGhostscript();
        if ($gs !== null) {
            $this->runGhostscript($gs, $path);
            return;
        }

        // No GS: delete the stored file and reject with an actionable message
        @unlink($path);
        throw new \Exception(
            "'{$originalName}' usa compresión PDF moderna (PDF 1.5+) no compatible con el procesador. " .
            "Conviértalo a PDF 1.4 en ilovepdf.com → 'Optimizar PDF' (gratis) y vuelva a subirlo."
        );
    }

    private function runGhostscript(string $gs, string $inputPath): void
    {
        $tmpPath = $inputPath . '.gs_tmp.pdf';
        exec(sprintf(
            '%s -q -dBATCH -dNOPAUSE -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -sOutputFile=%s %s 2>&1',
            escapeshellarg($gs),
            escapeshellarg($tmpPath),
            escapeshellarg($inputPath)
        ), $out, $code);

        if ($code === 0 && file_exists($tmpPath) && filesize($tmpPath) > 0) {
            @unlink($inputPath);
            rename($tmpPath, $inputPath);
        } else {
            @unlink($tmpPath);
        }
    }

    private function findGhostscript(): ?string
    {
        $candidates = ['gswin64c', 'gswin32c', 'gs', '/usr/bin/gs', '/usr/local/bin/gs'];
        foreach ($candidates as $cmd) {
            $out  = [];
            $code = -1;
            exec(escapeshellarg($cmd) . ' --version 2>&1', $out, $code);
            if ($code === 0) {
                return $cmd;
            }
        }
        return null;
    }

    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
