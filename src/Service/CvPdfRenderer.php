<?php
namespace App\Service;

use App\Entity\JobMatch;
use Dompdf\{Dompdf, Options};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

final class CvPdfRenderer
{
    public function __construct(private Environment $twig, #[Autowire('%kernel.project_dir%')] private string $projectDir) {}

    public function render(JobMatch $match, array $facts): string
    {
        $fontSource = $this->projectDir.'/assets/fonts/zakon';
        $fontCache = $this->projectDir.'/var/pdf-fonts';
        if (!is_dir($fontCache) && !mkdir($fontCache, 0700, true) && !is_dir($fontCache)) {
            throw new \RuntimeException('Could not create the private PDF font cache.');
        }
        $pdf = new Dompdf(new Options([
            'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
            'defaultFont' => 'Zakon Text', 'isFontSubsettingEnabled' => true,
            'fontDir' => $fontCache, 'fontCache' => $fontCache, 'tempDir' => $fontCache,
            'chroot' => [$this->projectDir.'/var', $fontSource],
        ]));
        foreach (['Regular' => [400, 'normal'], 'Bold' => [700, 'normal'], 'Italic' => [400, 'italic'], 'BoldItalic' => [700, 'italic']] as $face => [$weight, $style]) {
            if (!$pdf->getFontMetrics()->registerFont(['family' => 'Zakon Text', 'weight' => $weight, 'style' => $style], $fontSource.'/Zakon-'.$face.'.ttf')) {
                throw new \RuntimeException('Could not load the bundled Zakon Text font.');
            }
        }
        $pdf->loadHtml($this->twig->render('cv/document.html.twig', [
            'match' => $match, 'profile' => $match->run->profileSnapshot, 'facts' => $facts,
        ]), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return $pdf->output();
    }
}
