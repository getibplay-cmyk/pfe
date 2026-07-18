<?php

namespace App\Support\Insurance;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class DemoInsurancePolicyProof
{
    public function make(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rentfleet-insurance-demo-');
        if ($path === false || file_put_contents($path, $this->pdf()) === false) {
            throw new RuntimeException('La preuve documentaire de démonstration n’a pas pu être générée.');
        }

        return new UploadedFile(
            $path,
            'attestation-assurance-demonstration.pdf',
            'application/pdf',
            null,
            true,
        );
    }

    public function cleanup(UploadedFile $file): void
    {
        $path = $file->getPathname();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function pdf(): string
    {
        $lines = [
            'DOCUMENT DE DÉMONSTRATION — NON CONTRACTUEL',
            'Attestation fictive d’assurance automobile RentFleet',
            'Cette preuve est générée uniquement pour le scénario de démonstration.',
            'Elle ne contient aucune donnée personnelle réelle et ne produit aucun effet juridique.',
        ];
        $commands = ['BT', '/F1 15 Tf', '72 760 Td'];
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $commands[] = '0 -28 Td';
            }
            $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $line);
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded === false ? $line : $encoded);
            $commands[] = "({$escaped}) Tj";
        }
        $commands[] = 'ET';
        $stream = implode("\n", $commands)."\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($number + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
