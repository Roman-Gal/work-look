<?php
declare(strict_types=1);
namespace App\Service;

final class CvLinkDescriptions
{
    public function describe(string $url,array $descriptions,string $language,?string $labelOverride = null): string
    {
        $parts=parse_url($url);$host=strtolower($parts['host']??'');$path=trim($parts['path']??'','/');
        $category='website';$label=$host.($path!==''?'/'.$path:'');
        if(str_starts_with($url,'mailto:')){$category='email';$label=substr($url,7);}
        elseif(preg_match('/(^|\.)(github\.com|gitlab\.com|bitbucket\.org)$/',$host)){$category='code';}
        elseif(preg_match('/(^|\.)linkedin\.com$/',$host)){$category='linkedin';}
        elseif(preg_match('/(^|\.)(facebook\.com|instagram\.com|x\.com|twitter\.com|t\.me)$/',$host)){$category='social';}
        elseif(preg_match('/(^|\.)(diplomasafe\.com|credly\.com|coursera\.org)$/',$host)){$category='certificate';$label=$host.($path!==''?' · '.basename($path):'');}
        elseif(str_contains($path,'portfolio')){$category='portfolio';}
        foreach($descriptions as $description){
            if(($description['url']??null)===$url && is_string($description['label']??null) && trim($description['label'])!==''){
                $label=$description['label'];$category=$description['category']??$category;break;
            }
        }
        if($labelOverride!==null && trim($labelOverride)!==''){$label=$labelOverride;}
        $labels=[
            'pl'=>['certificate'=>'Certyfikat','code'=>'Kod / GitHub','linkedin'=>'LinkedIn','social'=>'Profil społecznościowy','portfolio'=>'Portfolio','website'=>'Strona','email'=>'Email','other'=>'Link'],
            'uk'=>['certificate'=>'Сертифікат','code'=>'Код / GitHub','linkedin'=>'LinkedIn','social'=>'Соцмережа','portfolio'=>'Портфоліо','website'=>'Сайт','email'=>'Email','other'=>'Посилання'],
            'en'=>['certificate'=>'Certificate','code'=>'Code / GitHub','linkedin'=>'LinkedIn','social'=>'Social profile','portfolio'=>'Portfolio','website'=>'Website','email'=>'Email','other'=>'Link'],
        ];
        $localized=$labels[$language]??$labels['en'];
        return ($localized[$category]??$localized['other']).' · '.$label;
    }
}
