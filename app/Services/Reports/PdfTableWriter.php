<?php

namespace App\Services\Reports;

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;

/**
 * Draws a report table straight onto PDF pages.
 *
 * Laying a large HTML table out with DomPDF needs about half a megabyte of memory per row — a 1,000-row
 * report ran out of memory and the browser showed a blank error box. Drawing the text directly keeps the
 * whole masterlist to a few seconds and about 100 MB, with the column headings repeated on every page.
 */
class PdfTableWriter
{
    private const MARGIN = 28.0;

    private const FONT = 'DejaVu Sans';

    private const FONT_SIZE = 7.5;

    private const LINE_HEIGHT = 9.5;

    private const ROW_HEIGHT = 15.0;

    private const CELL_PADDING = 5.0;

    private const FOOTER_HEIGHT = 18.0;

    private const INK = [0.122, 0.161, 0.216];

    private const NAVY = [0.118, 0.165, 0.353];

    private const INDIGO = [0.180, 0.227, 0.549];

    private const MUTED = [0.420, 0.447, 0.502];

    private const HAIRLINE = [0.898, 0.906, 0.922];

    private const ZEBRA = [0.973, 0.980, 0.988];

    private const WHITE = [1.0, 1.0, 1.0];

    /**
     * @var array<string, float>
     */
    private array $widthCache = [];

    private CPDF $canvas;

    private string $regular;

    private string $bold;

    /**
     * @param  list<string>  $metaLines  shown under the title (record count, filters, generated date)
     * @param  list<string>  $headings
     * @param  iterable<list<mixed>>  $rows
     * @return string the PDF document
     */
    public function render(string $title, array $metaLines, array $headings, iterable $rows, ?string $logoPath = null): string
    {
        /** @var Dompdf $dompdf */
        $dompdf = app('dompdf');

        $this->canvas = new CPDF('a4', count($headings) > 6 ? 'landscape' : 'portrait', $dompdf);
        $this->regular = $dompdf->getFontMetrics()->getFont(self::FONT, 'normal');
        $this->bold = $dompdf->getFontMetrics()->getFont(self::FONT, 'bold');
        $this->widthCache = [];

        $pageWidth = (float) $this->canvas->get_width();
        $pageHeight = (float) $this->canvas->get_height();
        $tableWidth = $pageWidth - 2 * self::MARGIN;
        $bottom = $pageHeight - self::MARGIN - self::FOOTER_HEIGHT;

        [$table, $rightAligned] = $this->readRows($headings, $rows);
        [$widths, $headingLines] = $this->layoutColumns($headings, $table, $tableWidth);

        $y = $this->drawDocumentHeader($title, $metaLines, $logoPath, $pageWidth);
        $y = $this->drawHeadings($headingLines, $widths, $rightAligned, $y);

        foreach ($table as $rowNumber => $cells) {
            if ($y + self::ROW_HEIGHT > $bottom) {
                $this->canvas->new_page();
                $y = $this->drawHeadings($headingLines, $widths, $rightAligned, self::MARGIN);
            }

            if ($rowNumber % 2 === 1) {
                $this->canvas->filled_rectangle(self::MARGIN, $y, $tableWidth, self::ROW_HEIGHT, self::ZEBRA);
            }

            $this->drawCells($cells, $widths, $rightAligned, $y + (self::ROW_HEIGHT - self::LINE_HEIGHT) / 2, $this->regular, self::INK);
            $this->canvas->line(self::MARGIN, $y + self::ROW_HEIGHT, self::MARGIN + $tableWidth, $y + self::ROW_HEIGHT, self::HAIRLINE, 0.5);

            $y += self::ROW_HEIGHT;
        }

        if ($table === []) {
            $this->canvas->text(self::MARGIN + self::CELL_PADDING, $y + 4, 'No records match the selected filters.', $this->regular, self::FONT_SIZE, self::MUTED);
        }

        $footerY = $pageHeight - self::MARGIN - 8;
        $this->canvas->page_text(self::MARGIN, $footerY, 'COLISAP Monitoring System · Barbaza Multi-Purpose Cooperative', $this->regular, 7, self::MUTED);
        $this->canvas->page_text($pageWidth - self::MARGIN - 62, $footerY, 'Page {PAGE_NUM} of {PAGE_COUNT}', $this->regular, 7, self::MUTED);

        return (string) $this->canvas->output();
    }

    /**
     * Every row as display text, plus which columns hold only numbers (those are right-aligned so digits line up).
     *
     * @param  list<string>  $headings
     * @param  iterable<list<mixed>>  $rows
     * @return array{0: list<list<string>>, 1: list<bool>}
     */
    private function readRows(array $headings, iterable $rows): array
    {
        $table = [];
        $numeric = array_fill(0, count($headings), null);

        foreach ($rows as $row) {
            $cells = [];

            foreach (array_keys($headings) as $column) {
                $value = array_values($row)[$column] ?? null;
                $cells[] = $this->display($value);

                if ($value !== null && $value !== '') {
                    $numeric[$column] = ($numeric[$column] ?? true) && (is_int($value) || is_float($value));
                }
            }

            $table[] = $cells;
        }

        return [$table, array_map(fn (?bool $isNumeric) => $isNumeric === true, $numeric)];
    }

    /**
     * Give every column the room its longest value needs. A heading longer than its values is split over two
     * lines instead of widening the column. If the table is still too wide, only the widest columns give up
     * room (their longest values end in "…"); if it is narrower, the spare room is shared out.
     *
     * @param  list<string>  $headings
     * @param  list<list<string>>  $table
     * @return array{0: list<float>, 1: list<list<string>>}
     */
    private function layoutColumns(array $headings, array $table, float $tableWidth): array
    {
        $natural = [];
        $headingLines = [];

        foreach ($headings as $column => $heading) {
            $longestValue = 0.0;

            foreach ($table as $cells) {
                $longestValue = max($longestValue, $this->textWidth($cells[$column], $this->regular));
            }

            $lines = $this->textWidth($heading, $this->bold) > $longestValue ? $this->splitInTwo($heading) : [$heading];
            $longestHeadingLine = max(array_map(fn (string $line) => $this->textWidth($line, $this->bold), $lines));

            $headingLines[] = $lines;
            $natural[] = max($longestValue, $longestHeadingLine) + 2 * self::CELL_PADDING;
        }

        $total = array_sum($natural);

        if ($total <= $tableWidth) {
            $scale = $tableWidth / max(1.0, $total);

            return [array_map(fn (float $width) => $width * $scale, $natural), $headingLines];
        }

        $sorted = $natural;
        sort($sorted);
        $remaining = $tableWidth;
        $cap = (float) end($sorted);

        foreach ($sorted as $index => $width) {
            $share = $remaining / (count($sorted) - $index);

            if ($width > $share) {
                $cap = $share;

                break;
            }

            $remaining -= $width;
        }

        return [array_map(fn (float $width) => min($width, $cap), $natural), $headingLines];
    }

    /**
     * "Application Date" → ["Application", "Date"], split at the space closest to the middle.
     *
     * @return list<string>
     */
    private function splitInTwo(string $heading): array
    {
        $words = explode(' ', $heading);

        if (count($words) < 2) {
            return [$heading];
        }

        $best = [$heading];
        $bestWidth = INF;

        for ($cut = 1; $cut < count($words); $cut++) {
            $lines = [implode(' ', array_slice($words, 0, $cut)), implode(' ', array_slice($words, $cut))];
            $width = max($this->textWidth($lines[0], $this->bold), $this->textWidth($lines[1], $this->bold));

            if ($width < $bestWidth) {
                [$best, $bestWidth] = [$lines, $width];
            }
        }

        return $best;
    }

    /**
     * Logo, cooperative name, report title and one line of details. Returns the y position below it.
     *
     * @param  list<string>  $metaLines
     */
    private function drawDocumentHeader(string $title, array $metaLines, ?string $logoPath, float $pageWidth): float
    {
        $top = self::MARGIN;
        $textX = self::MARGIN;

        if ($logoPath !== null && is_file($logoPath)) {
            $this->canvas->image($logoPath, self::MARGIN, $top, 40, 40);
            $textX += 50;
        }

        $textWidth = $pageWidth - self::MARGIN - $textX;

        $this->canvas->text($textX, $top + 1, 'Barbaza Multi-Purpose Cooperative — COLISAP', $this->bold, 11, self::NAVY);
        $this->canvas->text($textX, $top + 16, $this->fit($title, $this->bold, $textWidth, 11), $this->bold, 11, self::INDIGO);
        $this->canvas->text($textX, $top + 31, $this->fit(implode(' · ', $metaLines), $this->regular, $textWidth), $this->regular, self::FONT_SIZE, self::MUTED);
        $this->canvas->line(self::MARGIN, $top + 47, $pageWidth - self::MARGIN, $top + 47, self::INDIGO, 1.2);

        return $top + 56;
    }

    /**
     * The column headings, repeated at the top of every page. Returns the y position below them.
     *
     * @param  list<list<string>>  $headingLines
     * @param  list<float>  $widths
     * @param  list<bool>  $rightAligned
     */
    private function drawHeadings(array $headingLines, array $widths, array $rightAligned, float $y): float
    {
        $lineCount = max(array_map('count', $headingLines));
        $height = $lineCount * self::LINE_HEIGHT + 9;

        $this->canvas->filled_rectangle(self::MARGIN, $y, array_sum($widths), $height, self::INDIGO);

        foreach (range(0, $lineCount - 1) as $line) {
            // Single-line headings sit on the bottom line, level with the last line of the split ones.
            $cells = array_map(fn (array $lines) => $lines[$line - ($lineCount - count($lines))] ?? '', $headingLines);
            $this->drawCells($cells, $widths, $rightAligned, $y + 4.5 + $line * self::LINE_HEIGHT, $this->bold, self::WHITE);
        }

        return $y + $height;
    }

    /**
     * @param  list<string>  $cells
     * @param  list<float>  $widths
     * @param  list<bool>  $rightAligned
     * @param  array{0: float, 1: float, 2: float}  $color
     */
    private function drawCells(array $cells, array $widths, array $rightAligned, float $textY, string $font, array $color): void
    {
        $x = self::MARGIN;

        foreach ($widths as $column => $width) {
            $inner = $width - 2 * self::CELL_PADDING;
            $text = $this->fit($cells[$column] ?? '', $font, $inner);

            if ($text !== '') {
                $offset = $rightAligned[$column] ? $inner - $this->textWidth($text, $font) : 0.0;
                $this->canvas->text($x + self::CELL_PADDING + $offset, $textY, $text, $font, self::FONT_SIZE, $color);
            }

            $x += $width;
        }
    }

    private function display(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_float($value) => number_format($value, 2),
            is_int($value) => number_format($value),
            default => trim((string) $value),
        };
    }

    /**
     * The text, shortened with "…" when it is wider than the space it has.
     */
    private function fit(string $text, string $font, float $maxWidth, float $size = self::FONT_SIZE): string
    {
        // A hair of tolerance, so a value that defined its column's width is never shortened by rounding.
        if ($text === '' || $this->textWidth($text, $font, $size) <= $maxWidth + 0.01) {
            return $text;
        }

        $low = 0;
        $high = mb_strlen($text);

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);

            if ($this->textWidth(rtrim(mb_substr($text, 0, $middle)).'…', $font, $size) <= $maxWidth) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return $low === 0 ? '' : rtrim(mb_substr($text, 0, $low)).'…';
    }

    private function textWidth(string $text, string $font, float $size = self::FONT_SIZE): float
    {
        return $this->widthCache[$font.'|'.$size.'|'.$text] ??= (float) $this->canvas->get_text_width($text, $font, $size);
    }
}
