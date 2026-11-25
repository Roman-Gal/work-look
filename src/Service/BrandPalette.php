<?php
declare(strict_types=1);
namespace App\Service;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
final class BrandPalette
{
    private array $colors;
    public function __construct(#[Autowire('%kernel.project_dir%')] string $project)
    {
        preg_match_all('/--([a-z0-9-]+):\s*(#[a-fA-F0-9]{3,8})\s*;?/',file_get_contents($project.'/assets/styles/brand.css'),$matches,PREG_SET_ORDER);
        foreach ($matches as $match) { $this->colors[$match[1]]=$match[2]; }
    }
    public function color(string $name): string { return $this->colors[$name] ?? throw new \InvalidArgumentException('Unknown brand color.'); }
}
