<?php
declare(strict_types=1);
namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

final class ResumeTextExtractor
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $project,
        #[Autowire('%env(default::RESUME_PHP_BINARY)%')] private ?string $phpBinary = null) {}
    public function extract(UploadedFile $file): array
    {
        if (!$file->isValid() || $file->getSize() > 5_000_000 || strtolower($file->getClientOriginalExtension()) !== 'pdf') { throw new \InvalidArgumentException('resume.invalid'); }
        if (PHP_SAPI !== 'cli' || !function_exists('proc_open')) {
            try { return (new TextPdfParser())->parse($file->getPathname()); }
            catch (\Throwable $error) {
                $code = in_array($error->getMessage(), ['no_text', 'too_large', 'unavailable'], true) ? $error->getMessage() : 'invalid';
                throw new \InvalidArgumentException('resume.'.$code);
            }
        }
        $binary = $this->phpBinary ?: (PHP_SAPI === 'cli' ? PHP_BINARY : '');
        if ($binary === '' || !is_file($binary) || !function_exists('proc_open')) { throw new \RuntimeException('resume.unavailable'); }
        $environment = array_fill_keys(array_keys(array_merge(getenv(), $_SERVER, $_ENV)), false);
        foreach (['PATH', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR', 'LANG'] as $name) {
            if (($value = getenv($name)) !== false) { $environment[$name] = $value; }
        }
        $process = new Process([$binary, '-d', 'memory_limit=128M', '-d', 'display_errors=0', '-d', 'log_errors=0', $this->project.'/tools/extract-resume.php', $file->getPathname()], env: $environment);
        $process->setTimeout(20);
        try { $process->run(); } catch (\Throwable) { throw new \InvalidArgumentException('resume.invalid'); }
        $output = $process->getOutput();
        if (strlen($output) > 400000) { throw new \InvalidArgumentException('resume.too_large'); }
        try { $data = json_decode($output, true, 8, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new \InvalidArgumentException('resume.invalid'); }
        if (!$process->isSuccessful() || !is_string($data['text'] ?? null)) {
            throw new \InvalidArgumentException('resume.'.(in_array($data['error'] ?? '', ['no_text', 'too_large'], true) ? $data['error'] : 'invalid'));
        }
        return ['text' => $data['text'], 'pages' => (int) $data['pages']];
    }
}
