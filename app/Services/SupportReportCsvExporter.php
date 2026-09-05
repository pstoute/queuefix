<?php

namespace App\Services;

use RuntimeException;

class SupportReportCsvExporter
{
    /**
     * @param  array{from: string, to: string, timezone: string}  $range
     * @param array{
     *   summary: array<string, int|float|null>,
     *   breakdowns: array<string, list<array<string, int|float|string|null>>>
     * } $report
     */
    public function export(array $range, array $report): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to create the report export.');
        }

        fputcsv($stream, [
            'period_from',
            'period_to',
            'timezone',
            'dimension',
            'label',
            'created',
            'resolved',
            'currently_open',
            'first_response_sla_percent',
            'resolution_sla_percent',
            'first_response_median_seconds',
            'first_response_average_seconds',
            'resolution_median_seconds',
            'resolution_average_seconds',
            'rating_count',
            'average_csat',
            'low_rating_percent',
        ], escape: '');

        $this->writeRow($stream, $range, 'summary', 'All filtered tickets', $report['summary']);

        foreach ($report['breakdowns'] as $dimension => $rows) {
            foreach ($rows as $row) {
                $this->writeRow($stream, $range, $dimension, (string) $row['label'], $row);
            }
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false) {
            throw new RuntimeException('Unable to read the report export.');
        }

        return $contents;
    }

    /**
     * @param  resource  $stream
     * @param  array{from: string, to: string, timezone: string}  $range
     * @param  array<string, int|float|string|null>  $metrics
     */
    private function writeRow($stream, array $range, string $dimension, string $label, array $metrics): void
    {
        fputcsv($stream, [
            $range['from'],
            $range['to'],
            $range['timezone'],
            $dimension,
            $this->safeText($label),
            $metrics['created_count'] ?? '',
            $metrics['resolved_count'] ?? '',
            $metrics['currently_open_count'] ?? '',
            $metrics['first_response_sla_percent'] ?? '',
            $metrics['resolution_sla_percent'] ?? '',
            $metrics['first_response_median_seconds'] ?? '',
            $metrics['first_response_average_seconds'] ?? '',
            $metrics['resolution_median_seconds'] ?? '',
            $metrics['resolution_average_seconds'] ?? '',
            $metrics['rating_count'] ?? '',
            $metrics['average_csat'] ?? '',
            $metrics['low_rating_percent'] ?? '',
        ], escape: '');
    }

    private function safeText(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/u', $value) === 1 ? "'{$value}" : $value;
    }
}
