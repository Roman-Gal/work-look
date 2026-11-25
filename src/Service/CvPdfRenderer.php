<?php
namespace App\Service;

use App\Entity\JobMatch;
use Dompdf\{Dompdf, Options};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

final class CvPdfRenderer
{
    public function __construct(private Environment $twig, #[Autowire('%kernel.project_dir%')] private string $projectDir,
        #[Autowire('%env(DEFAULT_URI)%')] private string $origin = '') {}

    public function render(JobMatch $match, array $facts): string
    {
        return $this->document($this->twig->render('cv/document.html.twig', [
            'match' => $match, 'profile' => $match->run->profileSnapshot, 'facts' => $facts, 'cvBrandUrl'=>$this->brandUrl(), 'cvLinks'=>new CvLinks(),
        ]));
    }
    public function renderPrepared(string $text, array $sourceLinks = []): string
    {
        $links=new CvLinks();$known=$links->extract($text);$missing=[];
        // The credit already links to this destination; it is not an extra candidate profile.
        if($this->brandUrl()!==''){$known[]=$this->brandUrl();}
        foreach($sourceLinks as $source){
            if(is_string($source) && ($url=$links->target($source))!==null && !in_array($url,$known,true)){$missing[]=$url;$known[]=$url;}
        }
        return $this->document($this->twig->render('cv/prepared.html.twig',[
            'cv'=>(new PreparedCvLayout())->parse($text),'cvBrandUrl'=>$this->brandUrl(),'cvLinks'=>$links,'sourceLinks'=>$missing,
        ]));
    }
    private function brandUrl(): string
    {
        $parts=parse_url($this->origin);
        if(!filter_var($this->origin,FILTER_VALIDATE_URL) || ($parts['scheme']??'')!=='https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path']??'',['','/'],true)){return '';}
        return rtrim($this->origin,'/').'/';
    }
    private function document(string $html): string
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
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return $pdf->output();
    }
}
