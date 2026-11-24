<?php
declare(strict_types=1);
namespace App\Service;

/** Pure PHP parsing for hosts that prohibit spawning processes from web requests. */
final class TextPdfParser
{
    public function parse(string $path): array
    {
        $oldMemory = ini_get('memory_limit'); $oldTime = (int) ini_get('max_execution_time');
        $limitedMemory = false; $limitedTime = false;
        try {
            $memory = trim((string) $oldMemory); $unit = strtolower(substr($memory, -1));
            $bytes = (int) $memory * match ($unit) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
            if ($memory === '-1' || $bytes > 134217728) {
                if (@ini_set('memory_limit', '128M') === false) { throw new \RuntimeException('unavailable'); }
                $limitedMemory = true;
            }
            if (PHP_SAPI !== 'cli' && ($oldTime === 0 || $oldTime > 15)) {
                if (@ini_set('max_execution_time', '15') === false) { throw new \RuntimeException('unavailable'); }
                $limitedTime = true;
            }
            if (!is_file($path) || filesize($path) > 5_000_000 || file_get_contents($path, false, null, 0, 5) !== '%PDF-') { throw new \RuntimeException('invalid'); }
            $config = new \Smalot\PdfParser\Config(); $config->setDecodeMemoryLimit(8_000_000);
            $config->setMaxNestingDepth(200); $config->setRetainImageContent(false);
            $document = (new \Smalot\PdfParser\Parser([], $config))->parseFile($path); $pages = $document->getPages();
            if (count($pages) > 20) { throw new \RuntimeException('too_large'); }
            $text = '';
            foreach ($pages as $page) {
                $text .= $page->getText()."\n";
                if (mb_strlen($text) > 60000) { throw new \RuntimeException('too_large'); }
            }
            $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '');
            if (mb_strlen(preg_replace('/\s+/u', '', $text) ?? '') < 20) { throw new \RuntimeException('no_text'); }
            return ['text' => $text, 'pages' => count($pages)];
        } finally {
            if ($limitedMemory) { ini_set('memory_limit', $oldMemory); }
            if ($limitedTime) { ini_set('max_execution_time', (string) $oldTime); }
        }
    }
}
