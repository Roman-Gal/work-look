<?php
declare(strict_types=1);

// CLI-only worker. It never boots Symfony, executes PDF actions or performs OCR.
if (PHP_SAPI !== 'cli' || $argc !== 2) { exit(2); }
require dirname(__DIR__).'/vendor/autoload.php';
try {
    echo json_encode((new App\Service\TextPdfParser())->parse($argv[1]), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    $code = $error instanceof RuntimeException && in_array($error->getMessage(), ['too_large', 'no_text'], true) ? $error->getMessage() : 'invalid';
    echo json_encode(['error' => $code]); exit(2);
}
