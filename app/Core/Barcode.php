<?php

declare(strict_types=1);

namespace Waimart\Core;

/**
 * Code 128 (character set B) barcodes as SVG, for package labels. Code 128 is what courier and shop scanners read by
 * default; set B covers letters, digits and punctuation, so order numbers like "BM-7M7SU5JX" encode directly.
 */
final class Barcode
{
    /** Bar/space widths (in modules) of the 107 Code 128 symbols; 104 = Start B, 106 = Stop. */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** The modules of the barcode, "1" = bar, "0" = space (no quiet zone). */
    public static function modules(string $text): string
    {
        if ($text === '' || !preg_match('/^[\x20-\x7E]+$/', $text)) {
            throw new \InvalidArgumentException('Code 128 B encodes printable ASCII only.');
        }
        $codes = [104];
        $sum = 104;
        foreach (str_split($text) as $i => $ch) {
            $v = ord($ch) - 32;
            $codes[] = $v;
            $sum += $v * ($i + 1);
        }
        $codes[] = $sum % 103;   // check symbol
        $codes[] = 106;          // stop
        $out = '';
        foreach ($codes as $c) {
            foreach (str_split(self::PATTERNS[$c]) as $k => $w) {
                $out .= str_repeat($k % 2 === 0 ? '1' : '0', (int) $w);
            }
        }
        return $out;
    }

    /** An SVG of the barcode (with the required quiet zones), scaled to fit its box. */
    public static function svg(string $text, int $height = 60, string $label = ''): string
    {
        $m = self::modules($text);
        $quiet = 10;
        $width = strlen($m) + 2 * $quiet;
        $rects = '';
        $x = $quiet;
        foreach (preg_split('/(?<=1)(?=0)|(?<=0)(?=1)/', $m) as $run) {
            if ($run[0] === '1') {
                $rects .= '<rect x="' . $x . '" y="0" width="' . strlen($run) . '" height="' . $height . '"/>';
            }
            $x += strlen($run);
        }
        $title = htmlspecialchars($label !== '' ? $label : 'Barcode ' . $text, ENT_QUOTES);
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="' . $title . '"'
            . ' shape-rendering="crispEdges"><rect width="' . $width . '" height="' . $height . '" fill="#fff"/><g fill="#000">' . $rects . '</g></svg>';
    }
}
