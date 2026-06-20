<?php

declare(strict_types=1);

namespace App\Services;

class AssociationService
{
    public function associate(
        array &$persons,
        array &$pdfs,
        array &$associations,
        string $documento,
        string $pdfId
    ): void {
        $personKey = $this->findPersonKey($persons, $documento);

        if ($personKey === null) {
            throw new \Exception('Persona no encontrada.');
        }

        if (!isset($pdfs[$pdfId])) {
            throw new \Exception('PDF no encontrado.');
        }

        if ($pdfs[$pdfId]['status'] === 'associated' && $pdfs[$pdfId]['person_doc'] !== $documento) {
            throw new \Exception('Este PDF ya está asociado a otra persona.');
        }

        // Remove previous association for this person if any
        if ($persons[$personKey]['status'] === 'associated') {
            $oldPdfId = $associations[$documento] ?? null;
            if ($oldPdfId && isset($pdfs[$oldPdfId])) {
                $pdfs[$oldPdfId]['status']     = 'pending';
                $pdfs[$oldPdfId]['person_doc'] = null;
            }
        }

        $persons[$personKey]['status'] = 'associated';
        $persons[$personKey]['pdf_id'] = $pdfId;

        $pdfs[$pdfId]['status']     = 'associated';
        $pdfs[$pdfId]['person_doc'] = $documento;

        $associations[$documento] = $pdfId;
    }

    public function remove(
        array &$persons,
        array &$pdfs,
        array &$associations,
        string $documento
    ): void {
        $pdfId = $associations[$documento] ?? null;

        $personKey = $this->findPersonKey($persons, $documento);
        if ($personKey !== null) {
            $persons[$personKey]['status'] = 'pending';
            $persons[$personKey]['pdf_id'] = null;
        }

        if ($pdfId !== null && isset($pdfs[$pdfId])) {
            $pdfs[$pdfId]['status']     = 'pending';
            $pdfs[$pdfId]['person_doc'] = null;
        }

        unset($associations[$documento]);
    }

    public function getStats(array $persons, array $associations): array
    {
        $total      = count($persons);
        $associated = count($associations);
        $pending    = $total - $associated;
        $percentage = $total > 0 ? (int) round(($associated / $total) * 100) : 0;

        return compact('total', 'associated', 'pending', 'percentage');
    }

    private function findPersonKey(array $persons, string $documento): ?int
    {
        foreach ($persons as $key => $person) {
            if ($person['documento'] === $documento) {
                return $key;
            }
        }
        return null;
    }
}
