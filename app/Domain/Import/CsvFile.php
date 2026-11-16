<?php

namespace App\Domain\Import;

/** A CSV file as CsvReader understood it. */
final readonly class CsvFile
{
    /**
     * @param  list<array{line: int, cells: array<string, string>}>  $rows  Cells by template column, trimmed; line is the file's row number, header included
     * @param  list<string>  $warnings  About the file as a whole
     */
    public function __construct(public array $rows, public array $warnings) {}
}
