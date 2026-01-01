<?php

declare(strict_types=1);

namespace App\Services\Reports\Exports;

use Illuminate\Support\Facades\Storage;

/**
 * Minimal XLSX writer (single sheet) with no external dependencies.
 * Uses inline strings (no sharedStrings.xml) to keep it small and safe.
 */
final class SimpleXlsxExporter
{
    /**
     * @param array<int,array<string,mixed>> $rows
     * @return string relative storage path
     */
    public function store(array $rows, string $exportId): string
    {
        $path = "reports/tmp/{$exportId}.xlsx";

        $flatRows = array_map([$this, 'flattenRow'], $rows);

        $headers = [];
        foreach ($flatRows as $row) {
            $headers = array_values(array_unique(array_merge($headers, array_keys($row))));
        }

        $xlsxBinary = $this->buildXlsx($headers, $flatRows);
        Storage::disk('local')->put($path, $xlsxBinary);

        return $path;
    }

    /**
     * @param list<string> $headers
     * @param array<int,array<string,string|int|float>> $rows
     */
    private function buildXlsx(array $headers, array $rows): string
    {
        $tmp = sys_get_temp_dir() . '/xlsx_' . bin2hex(random_bytes(8));
        if (!mkdir($tmp, 0700) && !is_dir($tmp)) {
            throw new \RuntimeException('Failed to create temp directory for XLSX.');
        }

        try {
            $this->writeFile($tmp . '/[Content_Types].xml', $this->contentTypesXml());
            $this->mkdirp($tmp . '/_rels');
            $this->writeFile($tmp . '/_rels/.rels', $this->relsXml());

            $this->mkdirp($tmp . '/xl/_rels');
            $this->mkdirp($tmp . '/xl/worksheets');

            $this->writeFile($tmp . '/xl/workbook.xml', $this->workbookXml());
            $this->writeFile($tmp . '/xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
            $this->writeFile($tmp . '/xl/styles.xml', $this->stylesXml());
            $this->writeFile($tmp . '/xl/worksheets/sheet1.xml', $this->sheetXml($headers, $rows));

            $zipPath = $tmp . '.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to create XLSX zip.');
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                $fullPath = $file->getPathname();
                $relative = ltrim(str_replace($tmp, '', $fullPath), '/');

                if ($file->isDir()) {
                    $zip->addEmptyDir($relative);
                } else {
                    $zip->addFile($fullPath, $relative);
                }
            }

            $zip->close();

            $bin = file_get_contents($zipPath);
            if ($bin === false) {
                throw new \RuntimeException('Failed to read XLSX zip.');
            }

            return $bin;
        } finally {
            $this->rrmdir($tmp);
            @unlink($tmp . '.zip');
        }
    }

    private function mkdirp(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create directory: ' . $dir);
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Failed to write file: ' . $path);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($dir);
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function stylesXml(): string
    {
        // Minimal style sheet.
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>';
    }

    /**
     * @param list<string> $headers
     * @param array<int,array<string,string|int|float>> $rows
     */
    private function sheetXml(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>';

        $rowIndex = 1;
        $xml .= $this->sheetRowXml($rowIndex++, $headers, true);

        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $h) {
                $cells[] = $row[$h] ?? '';
            }
            $xml .= $this->sheetRowXml($rowIndex++, $cells, false);
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    /**
     * @param int $rowIndex
     * @param list<mixed> $values
     */
    private function sheetRowXml(int $rowIndex, array $values, bool $isHeader): string
    {
        $xml = '<row r="' . $rowIndex . '">';

        foreach ($values as $colIndex => $value) {
            $cellRef = $this->cellRef($colIndex + 1, $rowIndex);

            if (is_int($value) || is_float($value)) {
                $xml .= '<c r="' . $cellRef . '" t="n"><v>' . $value . '</v></c>';
                continue;
            }

            $text = $this->xmlEscape((string) $value);
            $xml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . $text . '</t></is></c>';
        }

        $xml .= '</row>';
        return $xml;
    }

    private function cellRef(int $colIndex, int $rowIndex): string
    {
        // Convert 1-based column index to Excel letters (A, B, ..., AA, AB, ...)
        $col = '';
        $n = $colIndex;
        while ($n > 0) {
            $n--;
            $col = chr(65 + ($n % 26)) . $col;
            $n = intdiv($n, 26);
        }
        return $col . $rowIndex;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string|int|float>
     */
    private function flattenRow(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    $out[$key . '.' . $subKey] = $this->scalarize($subValue);
                }
                continue;
            }

            $out[$key] = $this->scalarize($value);
        }

        return $out;
    }

    private function scalarize(mixed $value): string|int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }
}
