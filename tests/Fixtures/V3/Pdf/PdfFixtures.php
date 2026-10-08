<?php

namespace Tests\Fixtures\V3\Pdf;

class PdfFixtures
{
    public const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    /**
     * Build a strictly valid standard PDF 1.4 with N pages.
     */
    public static function minimalValidPdf(int $pageCount = 1, string $text = 'Educational Concept Material'): string
    {
        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        $kidRefs = [];
        for ($i = 1; $i <= $pageCount; $i++) {
            $kidRefs[] = (2 + $i * 2 - 1) . " 0 R";
        }
        $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kidRefs) . "] /Count {$pageCount} >>";

        $nextObj = 3;
        for ($i = 1; $i <= $pageCount; $i++) {
            $pageObjId = $nextObj++;
            $contentObjId = $nextObj++;

            $streamContent = "BT /F1 12 Tf 72 712 Td ({$text} Page {$i}) Tj ET";
            $len = strlen($streamContent);

            $objects[$pageObjId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Contents {$contentObjId} 0 R >>";
            $objects[$contentObjId] = "<< /Length {$len} >>\nstream\n{$streamContent}\nendstream";
        }

        return static::assemblePdf($objects, 1);
    }

    /**
     * Build an image-only PDF (no text font or text stream, only image).
     */
    public static function imageOnlyPdf(): string
    {
        $imgData = str_repeat("\xFF\x00\xAA", 100);
        $len = strlen($imgData);

        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>",
            4 => "<< /Type /XObject /Subtype /Image /Width 10 /Height 10 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length {$len} >>\nstream\n{$imgData}\nendstream",
            5 => "<< /Length 28 >>\nstream\nq 100 0 0 100 72 712 cm /Im1 Do Q\nendstream",
        ];

        return static::assemblePdf($objects, 1);
    }

    /**
     * Build a PDF with forbidden active content (/JavaScript, /Launch, /EmbeddedFiles, etc.)
     */
    public static function activeContentPdf(string $type = 'javascript'): string
    {
        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R /OpenAction 4 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources <<>> >>",
        ];

        if ($type === 'launch') {
            $objects[4] = "<< /Type /Action /S /Launch /F (calc.exe) >>";
        } elseif ($type === 'embedded_files') {
            $objects[1] = "<< /Type /Catalog /Pages 2 0 R /Names << /EmbeddedFiles << /Names [(payload.bin) 4 0 R] >> >> >>";
            $objects[4] = "<< /Type /Filespec /F (payload.bin) /EF << /F 5 0 R >> >>";
            $objects[5] = "<< /Length 12 >>\nstream\nembeddeddata\nendstream";
        } elseif ($type === 'acroform') {
            $objects[1] = "<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [] >> >>";
        } else {
            // default javascript
            $objects[4] = "<< /Type /Action /S /JavaScript /JS (app.alert('XSS');) >>";
        }

        return static::assemblePdf($objects, 1);
    }

    /**
     * Build an encrypted PDF.
     */
    public static function encryptedPdf(): string
    {
        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>",
            4 => "<< /Filter /Standard /V 1 /R 2 /O (encrypted) /U (encrypted) /P -44 >>",
        ];

        return static::assemblePdf($objects, 1, 4);
    }

    /**
     * Build a malformed PDF with corrupted structure.
     */
    public static function malformedPdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 99 0 R >>\nendobj\nxref\n0 2\n0000000000 65535 f \n0000000009 00000 n \ntrailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n9999\n%%EOF";
    }

    /**
     * Build a non-PDF file.
     */
    public static function notPdf(): string
    {
        return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00This is a fake PE executable file pretending to be PDF";
    }

    /**
     * Assemble PDF objects into a valid formatted string with precise xref table.
     */
    protected static function assemblePdf(array $objects, int $rootObjId, ?int $encryptObjId = null): string
    {
        $out = "%PDF-1.4\n";
        $offsets = [];

        ksort($objects);
        foreach ($objects as $id => $content) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$content}\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $maxObj = max(array_keys($objects));
        $totalObjs = $maxObj + 1;

        $out .= "xref\n0 {$totalObjs}\n";
        $out .= "0000000000 65535 f \n";

        for ($i = 1; $i <= $maxObj; $i++) {
            if (isset($offsets[$i])) {
                $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
            } else {
                $out .= "0000000000 65535 f \n";
            }
        }

        $encryptStr = $encryptObjId ? " /Encrypt {$encryptObjId} 0 R" : "";
        $out .= "trailer\n<< /Size {$totalObjs} /Root {$rootObjId} 0 R{$encryptStr} >>\n";
        $out .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $out;
    }
}
