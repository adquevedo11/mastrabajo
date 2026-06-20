<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Cell;

class ExcelService
{
    private const REQUIRED_COLUMNS = ['nombres', 'apellidos', 'documento'];
    private const ACCENT_MAP = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u',
        'Ñ' => 'n', 'À' => 'a', 'È' => 'e', 'Ì' => 'i', 'Ò' => 'o', 'Ù' => 'u',
    ];

    public function process(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Exception $e) {
            throw new \Exception('El archivo Excel está corrupto o no es válido.');
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows  = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            throw new \Exception('El archivo Excel está vacío.');
        }

        [$colMap, $headerIdx] = $this->findHeaders($rows);
        $persons = $this->readData($rows, $colMap, $headerIdx);

        if (empty($persons)) {
            throw new \Exception('No se encontraron registros con datos completos en el Excel.');
        }

        $persons = $this->sort($persons);

        foreach ($persons as $i => &$person) {
            $person['order'] = $i + 1;
        }
        unset($person);

        return $persons;
    }

    private function findHeaders(array $rows): array
    {
        foreach ($rows as $idx => $row) {
            $normalized = array_map(fn($v) => strtolower(trim((string) $v)), $row);
            $found = array_intersect(self::REQUIRED_COLUMNS, $normalized);

            if (count($found) === count(self::REQUIRED_COLUMNS)) {
                $colMap = [];
                foreach ($row as $col => $value) {
                    $key = strtolower(trim((string) $value));
                    if (in_array($key, self::REQUIRED_COLUMNS, true)) {
                        $colMap[$key] = $col;
                    }
                }
                return [$colMap, $idx];
            }
        }

        throw new \Exception(
            'No se encontraron las columnas requeridas: Nombres, Apellidos, Documento. ' .
            'Verifique que el encabezado esté en la primera fila.'
        );
    }

    private function readData(array $rows, array $colMap, int $headerIdx): array
    {
        $persons   = [];
        $documents = [];

        foreach ($rows as $idx => $row) {
            if ($idx <= $headerIdx) {
                continue;
            }

            $nombres   = trim((string) ($row[$colMap['nombres']]   ?? ''));
            $apellidos = trim((string) ($row[$colMap['apellidos']]  ?? ''));
            $documento = trim((string) ($row[$colMap['documento']]  ?? ''));

            if ($nombres === '' && $apellidos === '' && $documento === '') {
                continue;
            }

            if ($nombres === '' || $apellidos === '' || $documento === '') {
                continue;
            }

            if (in_array($documento, $documents, true)) {
                throw new \Exception("Documento duplicado encontrado: {$documento}");
            }
            $documents[] = $documento;

            $nombreParts   = $this->splitWords($nombres);
            $apellidoParts = $this->splitWords($apellidos);

            $persons[] = [
                'order'           => 0,
                'nombres'         => $nombres,
                'apellidos'       => $apellidos,
                'documento'       => $documento,
                'primer_nombre'   => $nombreParts[0]   ?? '',
                'segundo_nombre'  => $nombreParts[1]   ?? '',
                'primer_apellido' => $apellidoParts[0] ?? '',
                'segundo_apellido'=> $apellidoParts[1] ?? '',
                'status'          => 'pending',
                'pdf_id'          => null,
            ];
        }

        return $persons;
    }

    private function splitWords(string $text): array
    {
        return preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function sort(array $persons): array
    {
        usort($persons, function (array $a, array $b): int {
            $fields = ['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'documento'];
            foreach ($fields as $field) {
                $cmp = $this->compareNormalized((string) $a[$field], (string) $b[$field]);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return 0;
        });

        return $persons;
    }

    private function compareNormalized(string $a, string $b): int
    {
        return strcmp($this->normalize($a), $this->normalize($b));
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        return strtr($s, self::ACCENT_MAP);
    }
}
