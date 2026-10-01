<?php

namespace App\Services\Reports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams rows to the browser as XLSX or CSV without building the file in memory.
 */
class SpreadsheetWriter
{
    public const FORMATS = [
        'xlsx' => 'Excel (.xlsx)',
        'csv' => 'CSV (.csv)',
    ];

    /**
     * @param  list<string>  $headings
     * @param  iterable<array<int, mixed>>  $rows
     * @param  list<int>  $textColumns  columns forced to text (e.g. Acct. Number with leading zeros)
     * @param  list<string>  $titleLines  lines written above the headings (report title, filters)
     */
    public function download(string $baseName, string $format, array $headings, iterable $rows, array $textColumns = [], array $titleLines = []): StreamedResponse
    {
        $format = array_key_exists($format, self::FORMATS) ? $format : 'xlsx';

        return response()->streamDownload(function () use ($format, $headings, $rows, $textColumns, $titleLines) {
            $writer = $format === 'csv' ? new CsvWriter : new XlsxWriter;
            $writer->openToFile('php://output');

            foreach ($titleLines as $line) {
                $writer->addRow(Row::fromValues([$line], (new Style)->setFontBold()));
            }

            if ($titleLines !== []) {
                $writer->addRow(Row::fromValues([]));
            }

            $writer->addRow(Row::fromValues($headings, (new Style)->setFontBold()));

            foreach ($rows as $row) {
                $cells = [];

                foreach (array_values($row) as $index => $value) {
                    $cells[] = in_array($index, $textColumns, true) && $value !== null
                        ? new Cell\StringCell((string) $value, null)
                        : Cell::fromValue($value);
                }

                $writer->addRow(new Row($cells));
            }

            $writer->close();
        }, "{$baseName}.{$format}", [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
