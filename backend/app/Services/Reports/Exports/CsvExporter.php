<?php

declare(strict_types=1);

namespace App\Services\Reports\Exports;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CsvExporter
{
    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function stream(array $rows, string $filename): StreamedResponse
    {
        $flatRows = array_map([$this, 'flattenRow'], $rows);

        $headers = [];
        foreach ($flatRows as $row) {
            $headers = array_values(array_unique(array_merge($headers, array_keys($row))));
        }

        return new StreamedResponse(function () use ($flatRows, $headers): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new \RuntimeException('Failed to open CSV output stream.');
            }

            fputcsv($handle, $headers);

            foreach ($flatRows as $row) {
                $line = [];
                foreach ($headers as $header) {
                    $line[] = $row[$header] ?? '';
                }
                fputcsv($handle, $line);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return string relative storage path
     */
    public function store(array $rows, string $exportId): string
    {
        $path = "reports/tmp/{$exportId}.csv";

        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Failed to create CSV buffer.');
        }

        $flatRows = array_map([$this, 'flattenRow'], $rows);

        $headers = [];
        foreach ($flatRows as $row) {
            $headers = array_values(array_unique(array_merge($headers, array_keys($row))));
        }

        fputcsv($handle, $headers);

        foreach ($flatRows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if ($csv === false) {
            throw new \RuntimeException('Failed to read CSV buffer.');
        }

        Storage::disk('local')->put($path, $csv);

        return $path;
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
