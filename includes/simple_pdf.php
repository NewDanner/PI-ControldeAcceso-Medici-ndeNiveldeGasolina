<?php
// ============================================================
// SimplePDF - Generador de PDF mínimo (texto + líneas + tablas)
// No requiere librerías externas (FPDF, TCPDF, etc.)
// Suficiente para reportes tabulares en una fuente Helvetica.
// ============================================================

class SimplePDF
{
    private array $objects = [];
    private array $pageObjIds = [];
    private array $pageContent = [];
    private int $currentPage = -1;
    private float $pageWidth = 595.28;  // A4 en puntos
    private float $pageHeight = 841.89;

    public function addPage(): void
    {
        $this->currentPage++;
        $this->pageContent[$this->currentPage] = '';
    }

    /** y se mide desde ARRIBA de la página (más intuitivo al llamar) */
    public function text(float $x, float $y, string $txt, float $size = 10, bool $bold = false): void
    {
        $font = $bold ? '/F2' : '/F1';
        $yPdf = $this->pageHeight - $y;
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->toLatin1($txt));
        $this->pageContent[$this->currentPage] .=
            "BT $font $size Tf $x $yPdf Td ($escaped) Tj ET\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $y1Pdf = $this->pageHeight - $y1;
        $y2Pdf = $this->pageHeight - $y2;
        $this->pageContent[$this->currentPage] .=
            "0.7 w $x1 $y1Pdf m $x2 $y2Pdf l S\n";
    }

    public function getWidth(): float { return $this->pageWidth; }
    public function getHeight(): float { return $this->pageHeight; }

    private function toLatin1(string $s): string
    {
        return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    }

    /** Genera el binario del PDF y lo devuelve como string */
    public function output(): string
    {
        $objects = [];

        $fontRegularId = 1;
        $fontBoldId    = 2;
        $objects[$fontRegularId] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[$fontBoldId]    = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        $pagesId = 3;
        $nextId = 4;
        $pageIds = [];
        $contentIds = [];

        foreach ($this->pageContent as $i => $content) {
            $contentId = $nextId++;
            $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
            $contentIds[$i] = $contentId;

            $pageId = $nextId++;
            $objects[$pageId] =
                "<< /Type /Page /Parent $pagesId 0 R "
                . "/MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}] "
                . "/Resources << /Font << /F1 $fontRegularId 0 R /F2 $fontBoldId 0 R >> >> "
                . "/Contents $contentId 0 R >>";
            $pageIds[] = $pageId;
        }

        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $pageIds));
        $objects[$pagesId] = "<< /Type /Pages /Kids [$kids] /Count " . count($pageIds) . " >>";

        $catalogId = $nextId++;
        $objects[$catalogId] = "<< /Type /Catalog /Pages $pagesId 0 R >>";

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }

        $xrefStart = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $offset = $offsets[$id] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root $catalogId 0 R >>\n";
        $pdf .= "startxref\n$xrefStart\n%%EOF";

        return $pdf;
    }
}
