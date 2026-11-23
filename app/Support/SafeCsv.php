<?php

namespace App\Support;

/**
 * CSV writing that is safe to open in Excel or Google Sheets. A text cell starting with =, +, -, @, tab or carriage
 * return would run as a formula there (CSV injection), so it gets a leading apostrophe, which spreadsheets show as text.
 */
final class SafeCsv
{
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $row
     */
    public static function put($handle, array $row): void
    {
        fputcsv($handle, array_map(self::escape(...), $row));
    }

    public static function escape(mixed $value): mixed
    {
        if (! is_string($value) || strlen($value) < 2 || is_numeric($value) || ! in_array($value[0], self::FORMULA_STARTS, true)) {
            return $value;
        }

        return "'".$value;
    }

    /** Undo escape() for a cell read back from one of our own exports (e.g. re-importing the people CSV). */
    public static function unescape(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) > 2 && $value[0] === "'" && in_array($value[1], self::FORMULA_STARTS, true)) {
            return substr($value, 1);
        }

        return $value;
    }
}
