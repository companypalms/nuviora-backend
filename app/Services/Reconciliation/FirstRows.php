<?php

namespace App\Services\Reconciliation;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/** Para leer solo las primeras filas de una hoja de cálculo (un Excel grande no se carga entero en memoria). */
class FirstRows implements IReadFilter
{
    public function __construct(private int $rows)
    {
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row <= $this->rows;
    }
}
