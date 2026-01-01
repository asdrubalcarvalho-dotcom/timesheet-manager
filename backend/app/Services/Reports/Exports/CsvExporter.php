<?php

declare(strict_types=1);

namespace App\Services\Reports\Exports;

use Illuminate\Support\Facades\Storage;

final class CsvExporter
{
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
