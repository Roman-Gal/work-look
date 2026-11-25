<?php
declare(strict_types=1);
namespace App\Service;

/** Pure PHP parsing for hosts that prohibit spawning processes from web requests. */
final class TextPdfParser
{
    public function parse(string $path): array
    {
        if(!is_file($path) || filesize($path)>5_000_000){throw new \RuntimeException('invalid');}
        return $this->parseBytes(file_get_contents($path));
    }
    public function parseBytes(string $pdf): array
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
            if (strlen($pdf)>5_000_000 || !str_starts_with($pdf,'%PDF-')) { throw new \RuntimeException('invalid'); }
            $config = new \Smalot\PdfParser\Config(); $config->setDecodeMemoryLimit(8_000_000);
            $config->setMaxNestingDepth(200); $config->setRetainImageContent(false);
            $document = (new \Smalot\PdfParser\Parser([], $config))->parseContent($pdf); $pages = $document->getPages();
            if (count($pages) > 20) { throw new \RuntimeException('too_large'); }
            $text = '';
            foreach ($pages as $page) {
                $text .= $page->getText()."\n";
                if (mb_strlen($text) > 60000) { throw new \RuntimeException('too_large'); }
            }
            $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '');
            if (mb_strlen(preg_replace('/\s+/u', '', $text) ?? '') < 20) { throw new \RuntimeException('no_text'); }
            $formatter=new CvLinks();$visible=$formatter->extract($text);$links=$visible;
            foreach($pages as $page){
                $annotations=$page->get('Annots');
                if(!$annotations instanceof \Smalot\PdfParser\Element\ElementArray){continue;}
                foreach($annotations->getContent() as $annotation){
                    if(!$annotation instanceof \Smalot\PdfParser\PDFObject && !$annotation instanceof \Smalot\PdfParser\Header){continue;}
                    if((string)$annotation->get('Subtype')!=='Link'){continue;}
                    $action=$annotation->get('A');
                    if(!$action instanceof \Smalot\PdfParser\PDFObject && !$action instanceof \Smalot\PdfParser\Header){continue;}
                    if((string)$action->get('S')!=='URI'){continue;}
                    $uri=$action->get('URI');
                    if(!$uri instanceof \Smalot\PdfParser\Element){continue;}
                    $url=$formatter->target((string)$uri);
                    if($url!==null && !in_array($url,$links,true)){$links[]=$url;}
                    if(count($links)>100){throw new \RuntimeException('too_large');}
                }
            }
            $hidden=array_values(array_diff($links,$visible));
            if($hidden!==[]){$text.="\n\nLinks\n".implode("\n",$hidden);}
            if(mb_strlen($text)>60000){throw new \RuntimeException('too_large');}
            return ['text' => $text, 'pages' => count($pages), 'links'=>$links];
        } finally {
            if ($limitedMemory) { ini_set('memory_limit', $oldMemory); }
            if ($limitedTime) { ini_set('max_execution_time', (string) $oldTime); }
        }
    }
}
