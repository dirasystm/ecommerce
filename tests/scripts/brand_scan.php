<?php

declare(strict_types=1);

/**
 * Old-brand scan (Ecommerce rebrand): lists every place the old name "Waimart" appears in text a person could see —
 * PHP string literals, inline HTML and JavaScript/CSS strings — ignoring identifiers that intentionally keep the old
 * name (the Waimart\ namespace, WAIMART_ environment variables, the waimart database, test addresses).
 * Usage: php tests/scripts/brand_scan.php   (exit code 1 if anything is found)
 */

$root = dirname(__DIR__, 2);
// Deliberate: 'waimart' in Brand::protectedNames() (sellers cannot register the old name) and 'with_waimart', the old
// Seller Centre tab address kept working for saved links.
$allowed = '/^\'(waimart|with_waimart)\'$|Waimart\\\\|WAIMART_|waimart_(sid|test)|@waimart\.test|`waimart`|\bwaimart\b(?=[`.\'"]?\s*(database|DB|db))/i';
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }
    foreach (token_get_all((string) file_get_contents($f->getPathname())) as $t) {
        if (!is_array($t) || !in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
            continue;
        }
        if (stripos($t[1], 'waimart') !== false && stripos((string) preg_replace($allowed, '', $t[1]), 'waimart') !== false) {
            $hits[] = substr($f->getPathname(), strlen($root) + 1) . ':' . $t[2] . '  ' . trim(mb_substr(preg_replace('/\s+/', ' ', $t[1]) ?? '', 0, 120));
        }
    }
}
foreach (['public/assets/js', 'public/assets/css'] as $dir) {
    foreach (glob("$root/$dir/*.{js,css}", GLOB_BRACE) ?: [] as $file) {
        foreach (file($file) ?: [] as $n => $line) {
            if (stripos($line, 'waimart') !== false && !preg_match('#^\s*(//|/?\*)#', $line)) {
                $hits[] = substr($file, strlen($root) + 1) . ':' . ($n + 1) . '  ' . trim(mb_substr($line, 0, 120));
            }
        }
    }
}
echo $hits ? implode("\n", $hits) . "\n" . count($hits) . " place(s) still show the old name.\n" : "No visible old branding found.\n";
exit($hits ? 1 : 0);
