<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Real Excel files, not CSV renamed.
 *
 * The exports used to write CSV. Excel opens those, but everything about them
 * is a string: ₱25,500.00 arrives as text so it will not sum, dates land in
 * whatever order the reader guesses, and a long reference number gets turned
 * into 6.3E+08. A bookkeeper then retypes the lot, which is both the slow way
 * and the way numbers get changed by accident.
 *
 * Here a number is a number, a date is a date, and the totals row adds up in
 * the sheet itself.
 */
class SpreadsheetExport
{
    /** Column kinds, so a caller says what a column MEANS, not how to format it. */
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const NUMBER = 'number';

    public const DATE = 'date';

    /** Peso, thousands separated, two decimals, negatives in red. */
    private const MONEY_FORMAT = '"₱"#,##0.00_-;[Red]-"₱"#,##0.00';

    private const DATE_FORMAT = 'yyyy-mm-dd hh:mm';

    /**
     * @param  array<int, array{0: string, 1: string}>  $columns  [heading, kind]
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, string>  $totalOf  headings to add a total for
     */
    public static function download(
        string $filename,
        string $sheetTitle,
        array $columns,
        iterable $rows,
        array $totalOf = [],
        ?string $subtitle = null,
    ): StreamedResponse {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        // Excel refuses these characters in a tab name, and silently mangling
        // the title is worse than trimming it.
        $sheet->setTitle(mb_substr(preg_replace('/[\\\\\/\*\?\:\[\]]/', '-', $sheetTitle), 0, 31));

        $lastCol = count($columns);
        $row = 1;

        // A sheet that says what it is and when it was taken. An export with no
        // date on it is one nobody can trust a month later.
        $sheet->setCellValue([1, $row], $sheetTitle);
        $sheet->mergeCells([1, $row, $lastCol, $row]);
        $sheet->getStyle([1, $row])->getFont()->setBold(true)->setSize(14);
        $row++;

        $sheet->setCellValue([1, $row], trim(($subtitle ? $subtitle.' · ' : '').'Exported '.now()->format('j M Y, g:i A')));
        $sheet->mergeCells([1, $row, $lastCol, $row]);
        $sheet->getStyle([1, $row])->getFont()->setSize(9)->getColor()->setRGB('64748B');
        $row += 2;

        $headingRow = $row;

        foreach ($columns as $i => [$heading, $kind]) {
            $sheet->setCellValue([$i + 1, $row], $heading);
        }

        $sheet->getStyle([1, $headingRow, $lastCol, $headingRow])->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E31B23']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($headingRow)->setRowHeight(20);

        $firstDataRow = $headingRow + 1;
        $row = $firstDataRow;

        foreach ($rows as $data) {
            foreach ($columns as $i => [$heading, $kind]) {
                $value = $data[$i] ?? null;
                $cell = [$i + 1, $row];

                if ($value === null || $value === '') {
                    $sheet->setCellValue($cell, '');

                    continue;
                }

                match ($kind) {
                    self::MONEY, self::NUMBER => $sheet->setCellValue($cell, (float) $value),
                    self::DATE => $sheet->setCellValue(
                        $cell,
                        \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($value)
                    ),
                    // Explicitly a string: a reference like 5909978E is read as
                    // scientific notation otherwise, and 0917... loses its zero.
                    default => $sheet->setCellValueExplicit(
                        $cell, (string) $value,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                    ),
                };
            }
            $row++;
        }

        $lastDataRow = $row - 1;

        foreach ($columns as $i => [$heading, $kind]) {
            $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);

            if ($lastDataRow >= $firstDataRow) {
                $range = $letter.$firstDataRow.':'.$letter.$lastDataRow;

                if ($kind === self::MONEY) {
                    $sheet->getStyle($range)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
                } elseif ($kind === self::DATE) {
                    $sheet->getStyle($range)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
                }
            }

            $sheet->getColumnDimension($letter)->setAutoSize(true);
        }

        // Totals that Excel works out itself, so they still agree after the
        // sheet is filtered or a row is removed.
        if ($totalOf !== [] && $lastDataRow >= $firstDataRow) {
            $totalRow = $lastDataRow + 1;
            $sheet->setCellValue([1, $totalRow], 'TOTAL');

            foreach ($columns as $i => [$heading, $kind]) {
                if (! in_array($heading, $totalOf, true)) {
                    continue;
                }

                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValue([$i + 1, $totalRow], "=SUM({$letter}{$firstDataRow}:{$letter}{$lastDataRow})");

                if ($kind === self::MONEY) {
                    $sheet->getStyle($letter.$totalRow)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
                }
            }

            $sheet->getStyle([1, $totalRow, $lastCol, $totalRow])->applyFromArray([
                'font' => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
        }

        // Headings stay put while scrolling, and the filter buttons are there
        // for whoever wants to slice it by method or by month.
        $sheet->freezePane('A'.$firstDataRow);

        if ($lastDataRow >= $firstDataRow) {
            $sheet->setAutoFilter(
                'A'.$headingRow.':'
                .\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastCol).$lastDataRow
            );
        }

        $book->getProperties()
            ->setCreator('Imprint Production')
            ->setTitle($sheetTitle)
            ->setDescription('Exported from the Imprint Customs production system.');

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }
}
