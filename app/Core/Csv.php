<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * CSV export with spreadsheet formula-injection protection: any cell starting with
 * = + - @ (or tab/CR) is prefixed with an apostrophe so Excel/Sheets treat it as text.
 * Numbers that are genuinely numeric (e.g. -150.00) are left untouched.
 */
final class Csv
{
    /**
     * @param list<string> $headers
     * @param iterable<array<int|string,mixed>> $rows
     */
    public static function response(string $filename, array $headers, iterable $rows): Response
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");    // UTF-8 BOM so Excel shows names correctly
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, array_map([self::class, 'cell'], array_values($row)));
        }
        rewind($fh);
        $body = (string) stream_get_contents($fh);
        fclose($fh);
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'export.csv';
        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $safeName . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function cell(mixed $v): string
    {
        $s = $v === null ? '' : (string) $v;
        if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s) && !preg_match('/^-?\d+(\.\d+)?$/', $s)) {
            return "'" . $s;
        }
        return $s;
    }
}
