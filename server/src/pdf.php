<?php
/**
 * Minimal PDF 1.4 writer — enough for payslips, with no third-party library
 * (same dependency-free approach as xlsx_rows() in helpers.php).
 *
 * Supports: the base-14 Helvetica / Helvetica-Bold fonts (so nothing has to be
 * embedded), text with real width metrics for right/centre alignment and
 * wrapping, lines, filled and stroked rectangles, JPEG images, and multi-page
 * output with automatic page breaks.
 *
 * Coordinates are given in points from the TOP-LEFT of the page, which is far
 * easier to lay out with than PDF's native bottom-left origin; the conversion
 * happens in y().
 */
class DpPdf
{
    public const A4 = [595.28, 841.89];

    private float $w;
    private float $h;
    private array $pages = [];      // finished content streams
    private string $buf = '';       // current page content
    private array $images = [];     // [name => ['data'=>..,'w'=>..,'h'=>..]]
    private string $font = 'F1';
    private float $size = 10.0;
    private array $color = [0, 0, 0];

    public float $margin = 42.0;
    public float $cursor = 42.0;    // running "top" for flow layout

    public function __construct(array $size = self::A4)
    {
        [$this->w, $this->h] = $size;
        $this->cursor = $this->margin;
    }

    public function width(): float  { return $this->w; }
    public function height(): float { return $this->h; }
    public function innerWidth(): float { return $this->w - 2 * $this->margin; }

    /** Top-left y → PDF y. */
    private function y(float $top): float { return $this->h - $top; }

    // ── Text ──────────────────────────────────────────────────────────────

    /** Helvetica / Helvetica-Bold advance widths (1/1000 em) for WinAnsi 32..126. */
    private static function widths(bool $bold): array
    {
        static $reg = null, $bld = null;
        if ($reg === null) {
            $r = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
                  556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
                  1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
                  667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
                  333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
                  556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
            $b = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
                  556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
                  975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,
                  667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
                  333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,
                  611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];
            $reg = $r; $bld = $b;
        }
        return $bold ? $bld : $reg;
    }

    /** Rendered width of a string in points at the current (or given) size. */
    public function textWidth(string $s, ?float $size = null, ?bool $bold = null): float
    {
        $size = $size ?? $this->size;
        $bold = $bold ?? ($this->font === 'F2');
        $w = self::widths($bold);
        $total = 0;
        $s = self::toWinAnsi($s);
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($s[$i]);
            $total += ($c >= 32 && $c <= 126) ? $w[$c - 32] : 556;
        }
        return $total / 1000 * $size;
    }

    public function setFont(bool $bold = false, float $size = 10.0): self
    {
        $this->font = $bold ? 'F2' : 'F1';
        $this->size = $size;
        return $this;
    }

    public function setColor(int $r, int $g, int $b): self
    {
        $this->color = [$r / 255, $g / 255, $b / 255];
        return $this;
    }

    public function text(float $x, float $top, string $s): self
    {
        $this->buf .= sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $this->font, $this->size, $this->color[0], $this->color[1], $this->color[2],
            $x, $this->y($top) - $this->size, self::esc($s));
        return $this;
    }

    public function textRight(float $xRight, float $top, string $s): self
    {
        return $this->text($xRight - $this->textWidth($s), $top, $s);
    }

    public function textCenter(float $xCenter, float $top, string $s): self
    {
        return $this->text($xCenter - $this->textWidth($s) / 2, $top, $s);
    }

    /** Break a string to fit $maxW, returning the lines. */
    public function wrap(string $s, float $maxW): array
    {
        $words = preg_split('/\s+/', trim($s)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $wd) {
            $try = $line === '' ? $wd : $line . ' ' . $wd;
            if ($this->textWidth($try) <= $maxW || $line === '') {
                $line = $try;
            } else {
                $lines[] = $line;
                $line = $wd;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines ?: [''];
    }

    // ── Graphics ──────────────────────────────────────────────────────────

    public function line(float $x1, float $t1, float $x2, float $t2, float $w = 0.6, array $rgb = [220, 226, 236]): self
    {
        $this->buf .= sprintf("%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255, $w, $x1, $this->y($t1), $x2, $this->y($t2));
        return $this;
    }

    public function fillRect(float $x, float $top, float $w, float $h, array $rgb): self
    {
        $this->buf .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
            $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255, $x, $this->y($top + $h), $w, $h);
        return $this;
    }

    /**
     * Place a JPEG. $jpeg is raw bytes; $w/$h are the drawn box in points.
     * Only JPEG is supported because DCTDecode lets the bytes pass through
     * untouched — no decoder needed.
     */
    public function image(string $jpeg, float $x, float $top, float $w, float $h): self
    {
        $info = @getimagesizefromstring($jpeg);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) {
            return $this;
        }
        $name = 'I' . (count($this->images) + 1);
        $this->images[$name] = ['data' => $jpeg, 'w' => $info[0], 'h' => $info[1]];
        $this->buf .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
            $w, $h, $x, $this->y($top + $h), $name);
        return $this;
    }

    // ── Pages ─────────────────────────────────────────────────────────────

    public function newPage(): self
    {
        $this->pages[] = $this->buf;
        $this->buf = '';
        $this->cursor = $this->margin;
        return $this;
    }

    /** Start a new page if $needed points of vertical space are not available. */
    public function ensure(float $needed): self
    {
        if ($this->cursor + $needed > $this->h - $this->margin) {
            $this->newPage();
        }
        return $this;
    }

    // ── Output ────────────────────────────────────────────────────────────

    public function output(): string
    {
        $pages = $this->pages;
        $pages[] = $this->buf;                       // flush the page in progress

        $objects = [];                                // 1-based; index 0 = object 1
        $nPages  = count($pages);
        $firstPage = 5;                               // 1 catalog, 2 pages, 3-4 fonts
        $imgIds = [];
        $id = $firstPage + $nPages * 2;
        foreach ($this->images as $name => $img) {
            $imgIds[$name] = $id++;
        }

        $kids = [];
        for ($i = 0; $i < $nPages; $i++) {
            $kids[] = ($firstPage + $i * 2) . ' 0 R';
        }

        $xobj = '';
        foreach ($imgIds as $name => $oid) {
            $xobj .= "/$name $oid 0 R ";
        }
        $resources = '/Font <</F1 3 0 R /F2 4 0 R>>' . ($xobj ? " /XObject <<$xobj>>" : '');

        $objects[1] = "<</Type /Catalog /Pages 2 0 R>>";
        $objects[2] = "<</Type /Pages /Kids [" . implode(' ', $kids) . "] /Count $nPages>>";
        $objects[3] = "<</Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding>>";
        $objects[4] = "<</Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding>>";

        for ($i = 0; $i < $nPages; $i++) {
            $pageId    = $firstPage + $i * 2;
            $contentId = $pageId + 1;
            $objects[$pageId] = sprintf(
                "<</Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources <<%s>> /Contents %d 0 R>>",
                $this->w, $this->h, $resources, $contentId);
            $stream = $pages[$i];
            $objects[$contentId] = "<</Length " . strlen($stream) . ">>\nstream\n" . $stream . "endstream";
        }
        foreach ($this->images as $name => $img) {
            $oid = $imgIds[$name];
            $objects[$oid] = "<</Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']}"
                . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length "
                . strlen($img['data']) . ">>\nstream\n" . $img['data'] . "\nendstream";
        }

        ksort($objects);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "$num 0 obj\n$body\nendobj\n";
        }
        $max = max(array_keys($objects));
        $xrefPos = strlen($out);
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($n = 1; $n <= $max; $n++) {
            $out .= isset($offsets[$n])
                ? sprintf("%010d 00000 n \n", $offsets[$n])
                : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<</Size " . ($max + 1) . " /Root 1 0 R>>\nstartxref\n$xrefPos\n%%EOF\n";
        return $out;
    }

    // ── Encoding helpers ──────────────────────────────────────────────────

    /** UTF-8 → WinAnsi (CP1252), with a few typographic substitutions. */
    public static function toWinAnsi(string $s): string
    {
        $s = strtr($s, ["\u{00A0}" => ' ', '—' => '-', '–' => '-', '·' => '-', '→' => '->', '…' => '...',
                        '“' => '"', '”' => '"', '‘' => "'", '’' => "'", '₱' => 'PHP ',
                        '€' => chr(128), '£' => chr(163)]);
        $conv = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        return $conv === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $conv;
    }

    /** Escape a PDF literal string. */
    private static function esc(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::toWinAnsi($s));
    }
}

/**
 * Convert an org logo (stored as WebP by store_logo_webp()) to JPEG bytes so it
 * can be embedded in a PDF — PDF has no WebP support. Returns null if GD can't
 * read it. Height is capped so the header stays tidy.
 */
function pdf_logo_jpeg(?string $logoPath, int $maxH = 42): ?array
{
    if (!$logoPath || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $abs = upload_path($logoPath);
    if (!is_file($abs)) {
        return null;
    }
    $img = @imagecreatefromstring((string) file_get_contents($abs));
    if (!$img) {
        return null;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1.0, $maxH / max(1, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    // Flatten onto white — JPEG has no alpha, and payslips print on white.
    $flat = imagecreatetruecolor($nw, $nh);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopyresampled($flat, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    imagejpeg($flat, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);
    imagedestroy($flat);
    return ['data' => $bytes, 'w' => $nw, 'h' => $nh];
}
