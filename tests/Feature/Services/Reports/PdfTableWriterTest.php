<?php

namespace Tests\Feature\Services\Reports;

use App\Services\Reports\PdfTableWriter;
use Tests\TestCase;

class PdfTableWriterTest extends TestCase
{
    private function pageCount(string $pdf): int
    {
        return (int) preg_match_all('/\/Type\s*\/Page\b/', $pdf);
    }

    public function test_a_five_thousand_row_report_becomes_a_multi_page_pdf_within_a_small_memory_budget(): void
    {
        $rows = (function () {
            for ($number = 1; $number <= 5000; $number++) {
                yield [sprintf('001%08d', $number), "DELA CRUZ, JUAN {$number}", 'Barotac Viejo', 'Unassigned', '40K', 'Active', 1500.5 + $number, '2024-01-15', null];
            }
        })();
        $memoryBefore = memory_get_usage();

        $pdf = app(PdfTableWriter::class)->render(
            'Member masterlist',
            ['5,000 record(s)', 'Branch: Barotac Viejo'],
            ['Acct. Number', 'Account Name', 'Branch', 'Segment', 'Category', 'Status', 'Savings Balance', 'Approval Date', 'Remarks'],
            $rows,
            public_path('images/logo-192.png'),
        );

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(140, $this->pageCount($pdf), 'About 32 rows fit on a landscape page.');
        $this->assertLessThan(100 * 1024 * 1024, memory_get_peak_usage() - $memoryBefore, 'The old HTML renderer needed about 0.5 MB per row.');
    }

    public function test_a_report_without_rows_is_still_a_one_page_pdf(): void
    {
        $pdf = app(PdfTableWriter::class)->render('Deceased members', ['0 record(s)'], ['Acct. Number', 'Account Name'], []);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, $this->pageCount($pdf));
    }
}
