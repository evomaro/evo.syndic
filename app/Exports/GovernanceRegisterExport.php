<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class GovernanceRegisterExport implements FromArray, ShouldAutoSize, WithHeadings
{
    public function __construct(private array $rows, private array $headings) {}

    public function array(): array
    {
        return array_map(fn ($row) => array_map(function ($value) {
            if (is_string($value) && preg_match('/^[=+@-]/u', $value)) {
                return "'".$value;
            }

            return $value;
        }, array_values($row)), $this->rows);
    }

    public function headings(): array
    {
        return $this->headings;
    }
}
