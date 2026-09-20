<?php

namespace App\Support;

use DOMDocument;
use ZipArchive;

/**
 * Spreadsheet reading, with no third-party library.
 *
 * Ports `xlsx_sheet_names()`, `xlsx_rows()`, `csv_rows()`, `sheet_rows()`,
 * `sheet_norm_label()`, `sheet_find_header()`, `xlsx_serial_to_date()` and
 * `col_letter()`.
 *
 * ## Why this is not PhpSpreadsheet (decision D7)
 *
 * The migration plan §22 suggests it. Three things stop that being a drop-in:
 *
 * 1. **The row/cell shape is the interface.** Rows are keyed by their 1-BASED
 *    SPREADSHEET ROW NUMBER and cells by COLUMN LETTER, so a blank or missing
 *    row never shifts the numbering and an importer can say "column F of row
 *    31". Every importer is written against that shape.
 * 2. **CSV returns the same shape**, so one code path accepts either format.
 *    Swapping the workbook reader alone would split them.
 * 3. **The 60-row header scan and loose label matching** are what make real
 *    payroll exports import at all — they arrive with title banners, blank
 *    leading rows and merged cells above the table, and headers spelled
 *    however that month's export felt like spelling them.
 *
 * Reproducing all of that on top of PhpSpreadsheet is more work than keeping
 * this, and getting it subtly wrong means real payroll files stop importing.
 * It stays optional later work behind this same interface.
 *
 * @see docs/migration/payroll.md §5
 */
class Sheet
{
    /**
     * Worksheet names in a workbook, in order.
     *
     * @return list<string>
     */
    public static function sheetNames(string $path): array
    {
        if (! class_exists('ZipArchive')) {
            return [];
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return [];
        }

        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        if ($workbook === false) {
            return [];
        }

        $document = new DOMDocument();

        if (! @$document->loadXML($workbook)) {
            return [];
        }

        $names = [];

        foreach ($document->getElementsByTagName('sheet') as $sheet) {
            $names[] = $sheet->getAttribute('name');
        }

        return $names;
    }

    /**
     * Any supported upload into the common row shape.
     *
     * @return array<int, array<string, string>>
     */
    public static function rows(string $path, ?string $sheetName = null): array
    {
        return preg_match('/\.(csv|tsv|txt)$/i', $path)
            ? self::csvRows($path)
            : self::xlsxRows($path, $sheetName);
    }

    /**
     * One worksheet, as [rowNumber => [columnLetter => value]].
     *
     * Every value comes back a string; the caller casts. Shared strings,
     * inline strings, formula results and numeric cells all resolve.
     *
     * @return array<int, array<string, string>>
     */
    public static function xlsxRows(string $path, ?string $sheetName = null): array
    {
        if (! class_exists('ZipArchive')) {
            return [];
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return [];
        }

        $shared = self::sharedStrings($zip);
        $target = self::worksheetPart($zip, $sheetName);

        if ($target === null) {
            $zip->close();

            return [];
        }

        $xml = $zip->getFromName($target);
        $zip->close();

        if ($xml === false) {
            return [];
        }

        $document = new DOMDocument();

        if (! @$document->loadXML($xml)) {
            return [];
        }

        $rows = [];

        foreach ($document->getElementsByTagName('row') as $rowElement) {
            $number = (int) $rowElement->getAttribute('r');

            if ($number <= 0) {
                continue;
            }

            $row = [];

            foreach ($rowElement->getElementsByTagName('c') as $cell) {
                // "A31" -> "A"
                $column = preg_replace('/[0-9]+/', '', $cell->getAttribute('r'));

                if ($column === '') {
                    continue;
                }

                $row[$column] = self::cellValue($cell, $shared);
            }

            $rows[$number] = $row;
        }

        return $rows;
    }

    /**
     * A .csv/.tsv in the SAME shape, keyed by 1-based line number.
     *
     * The delimiter is sniffed from the first non-empty line and a UTF-8 BOM
     * is stripped, because a spreadsheet's "Save as CSV" produces all three
     * delimiters depending on the machine's locale.
     *
     * @return array<int, array<string, string>>
     */
    public static function csvRows(string $path): array
    {
        $handle = @fopen($path, 'r');

        if (! $handle) {
            return [];
        }

        $delimiter = self::sniffDelimiter($handle);

        $rows = [];
        $line = 0;
        $first = true;

        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;

            if ($cells === [null]) {
                continue;                       // a blank line
            }

            if ($first) {
                $first = false;

                if (isset($cells[0])) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
                }
            }

            $row = [];

            foreach (array_values($cells) as $index => $value) {
                $row[self::columnLetter($index)] = $value === null ? '' : trim((string) $value);
            }

            $rows[$line] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Normalize a header cell for matching.
     *
     * "Wise Recipient ID", "wise_recipient_id" and "Wise-Recipient  Id" all
     * reduce to "wise recipient id".
     */
    public static function normalizeLabel(mixed $value): string
    {
        $label = strtolower(trim((string) $value));
        $label = str_replace(['_', '-', '.', '/', '\\'], ' ', $label);
        $label = preg_replace('/[^a-z0-9 %]+/', '', $label);

        return trim(preg_replace('/\s+/', ' ', $label));
    }

    /**
     * Find the header row by the columns an import declares.
     *
     * Scans rather than assuming row 1: real exports carry title banners and
     * blank rows above the table.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  list<string>  $requiredLabels
     * @return array{0: int, 1: array<string, string>}  [rowNumber, normalizedLabel => columnLetter]
     */
    public static function findHeader(array $rows, array $requiredLabels, int $maxScan = 60): array
    {
        $needed = array_map([self::class, 'normalizeLabel'], $requiredLabels);
        $scanned = 0;

        foreach ($rows as $number => $row) {
            if (++$scanned > $maxScan) {
                break;
            }

            $map = [];

            foreach ($row as $letter => $value) {
                $normalized = self::normalizeLabel($value);

                // First column wins a duplicate label.
                if ($normalized !== '' && ! isset($map[$normalized])) {
                    $map[$normalized] = $letter;
                }
            }

            $found = true;

            foreach ($needed as $label) {
                if (! isset($map[$label])) {
                    $found = false;
                    break;
                }
            }

            if ($found) {
                return [(int) $number, $map];
            }
        }

        return [0, []];
    }

    /**
     * An Excel 1900-system date serial as 'Y-m-d', or null if it is not one.
     *
     * The 25569 offset — days from Excel's 1899-12-30 epoch to the Unix epoch
     * — already absorbs Excel's phantom 1900-02-29 for every serial >= 61,
     * which covers every real payroll date.
     */
    public static function serialToDate(mixed $serial): ?string
    {
        if (! is_numeric($serial)) {
            return null;
        }

        $value = (float) $serial;

        if ($value < 61) {
            return null;                        // pre-1900-03-01, or nonsense
        }

        return gmdate('Y-m-d', (int) (($value - 25569) * 86400));
    }

    /** 0-based column index to letter: 0 => 'A', 26 => 'AA'. */
    public static function columnLetter(int $index): string
    {
        $letters = '';
        $index++;

        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)) . $letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }

    /* ── Internals ───────────────────────────────────────────────────────── */

    /**
     * The shared string table. Cells with `t="s"` hold an index into it.
     *
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $document = new DOMDocument();

        if (! @$document->loadXML($xml)) {
            return [];
        }

        $strings = [];

        foreach ($document->getElementsByTagName('si') as $item) {
            $text = '';

            // A single string can be split across several <t> runs when parts
            // of it are styled differently.
            foreach ($item->getElementsByTagName('t') as $run) {
                $text .= $run->textContent;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * Resolve a sheet name to its part path.
     *
     * workbook.xml maps a name to an r:id, and the rels file maps that r:id to
     * the actual sheetN.xml — which is NOT necessarily numbered in sheet order.
     */
    private static function worksheetPart(ZipArchive $zip, ?string $sheetName): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook !== false && $rels !== false) {
            $targets = [];
            $relsDocument = new DOMDocument();

            if (@$relsDocument->loadXML($rels)) {
                foreach ($relsDocument->getElementsByTagName('Relationship') as $relationship) {
                    $targets[$relationship->getAttribute('Id')] = $relationship->getAttribute('Target');
                }
            }

            $workbookDocument = new DOMDocument();

            if (@$workbookDocument->loadXML($workbook)) {
                $id = null;

                foreach ($workbookDocument->getElementsByTagName('sheet') as $sheet) {
                    if ($sheetName === null) {
                        $id = $sheet->getAttribute('r:id');     // the first sheet
                        break;
                    }

                    if (strcasecmp($sheet->getAttribute('name'), $sheetName) === 0) {
                        $id = $sheet->getAttribute('r:id');
                        break;
                    }
                }

                if ($id !== null && isset($targets[$id])) {
                    $target = $targets[$id];

                    return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                }
            }
        }

        // A workbook without usable rels still has a first sheet.
        return $sheetName === null ? 'xl/worksheets/sheet1.xml' : null;
    }

    /** @param  list<string>  $shared */
    private static function cellValue(\DOMElement $cell, array $shared): string
    {
        if ($cell->getAttribute('t') === 'inlineStr') {
            $value = '';

            foreach ($cell->getElementsByTagName('t') as $run) {
                $value .= $run->textContent;
            }

            return $value;
        }

        $valueElement = $cell->getElementsByTagName('v')->item(0);
        $raw = $valueElement ? $valueElement->textContent : '';

        return $cell->getAttribute('t') === 's' ? ($shared[(int) $raw] ?? '') : $raw;
    }

    /** @param  resource  $handle */
    private static function sniffDelimiter($handle): string
    {
        $delimiter = ',';

        while (($probe = fgets($handle)) !== false) {
            if (trim($probe) === '') {
                continue;
            }

            $probe = preg_replace('/^\xEF\xBB\xBF/', '', $probe);

            $counts = [
                ','  => substr_count($probe, ','),
                ';'  => substr_count($probe, ';'),
                "\t" => substr_count($probe, "\t"),
            ];

            arsort($counts);
            $best = array_key_first($counts);

            if ($counts[$best] > 0) {
                $delimiter = $best;
            }

            break;
        }

        rewind($handle);

        return $delimiter;
    }
}
