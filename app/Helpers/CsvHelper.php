<?php

namespace App\Helpers;

/**
 * CSV Helper
 *
 * Writes and reads the CSV files of the export and import pages
 */
class CsvHelper
{
    /**
     * How a cell starts that a spreadsheet may run as a formula: = + - @ (also their full-width forms),
     * a tab or a line break. See https://owasp.org/www-community/attacks/CSV_Injection
     */
    private const FORMULA_START = '(?:[=+\-@\t\r\n]|\xEF\xBC[\x8B\x8D\x9D\xA0])';

    /**
     * Build a CSV file
     *
     * @param array $rows Rows of cell values
     * @param array $headers Column names for the first line
     * @return string
     */
    public static function build(array $rows, array $headers): string
    {
        $handle = fopen('php://temp', 'r+');
        // No escape character: with one, a backslash before a quote lets a value spill into the next cell
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'guardCell'], array_values($row)), ',', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Read a CSV file into rows keyed by the lower-case column names of its first line
     *
     * @param string $content File content
     * @return array
     */
    public static function parse(string $content): array
    {
        // Excel puts a byte order mark in front of the first column name
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        $header = null;
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue; // blank line
            }
            if ($header === null) {
                $header = array_map(fn($name) => strtolower(trim((string)$name)), $row);
                continue;
            }
            $item = [];
            foreach ($header as $i => $name) {
                $item[$name] = self::unguardCell((string)($row[$i] ?? ''));
            }
            $rows[] = $item;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Put an apostrophe before a value that a spreadsheet would run as a formula.
     * A value that already starts with apostrophes gets one more, so reading it back returns the original.
     */
    private static function guardCell($value)
    {
        if (is_string($value) && preg_match('/^\'*' . self::FORMULA_START . '/', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Take the apostrophe of guardCell() off again
     */
    private static function unguardCell(string $value): string
    {
        return preg_match('/^\'+' . self::FORMULA_START . '/', $value) ? substr($value, 1) : $value;
    }
}
