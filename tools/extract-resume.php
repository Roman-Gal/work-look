<?php
declare(strict_types=1);

// CLI-only worker. It never boots Symfony, executes PDF actions or performs OCR.
if (PHP_SAPI !== 'cli' || $argc !== 2) { exit(2); }
require dirname(__DIR__).'/vendor/autoload.php';
try {
    $path = $argv[1];
    if (!is_file($path) || filesize($path) > 5_000_000 || file_get_contents($path, false, null, 0, 5) !== '%PDF-') { throw new RuntimeException('invalid'); }
    $config = new Smalot\PdfParser\Config();
    $config->setDecodeMemoryLimit(8_000_000); $config->setMaxNestingDepth(200); $config->setRetainImageContent(false);
    $document = (new Smalot\PdfParser\Parser([], $config))->parseFile($path);
    $pages = $document->getPages();
    if (count($pages) > 20) { throw new RuntimeException('too_large'); }
    $text = '';
    foreach ($pages as $page) {
        $text .= $page->getText()."\n";
        if (mb_strlen($text) > 60000) { throw new RuntimeException('too_large'); }
    }
    $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '');
    if (mb_strlen(preg_replace('/\s+/u', '', $text) ?? '') < 20) { throw new RuntimeException('no_text'); }
    echo json_encode(['text' => $text, 'pages' => count($pages)], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    $code = $error instanceof RuntimeException && in_array($error->getMessage(), ['too_large', 'no_text'], true) ? $error->getMessage() : 'invalid';
    echo json_encode(['error' => $code]); exit(2);
}
