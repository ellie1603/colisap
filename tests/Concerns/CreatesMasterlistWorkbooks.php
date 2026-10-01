<?php

namespace Tests\Concerns;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

trait CreatesMasterlistWorkbooks
{
    /**
     * Write an .xlsx workbook with one sheet per key and return its absolute path.
     *
     * @param  array<string, list<list<mixed>>>  $sheets
     */
    protected function writeMasterlistWorkbook(string $absolutePath, array $sheets): string
    {
        $writer = new Writer;
        $writer->openToFile($absolutePath);

        foreach (array_keys($sheets) as $index => $name) {
            $sheet = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);

            foreach ($sheets[$name] as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        return $absolutePath;
    }
}
