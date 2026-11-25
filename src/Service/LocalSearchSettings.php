<?php
declare(strict_types=1);
namespace App\Service;

final class LocalSearchSettings
{
    public const DEFAULT_SOURCES=['solidjobs','dou','djinni'];
    public const SOURCES=[
        'solidjobs'=>['name'=>'SOLID.Jobs','url'=>'https://solid.jobs/','domain'=>'solid.jobs'],
        'dou'=>['name'=>'DOU','url'=>'https://jobs.dou.ua/','domain'=>'jobs.dou.ua'],
        'djinni'=>['name'=>'Djinni','url'=>'https://djinni.co/jobs/','domain'=>'djinni.co'],
        'justjoinit'=>['name'=>'Just Join IT','url'=>'https://justjoin.it/','domain'=>'justjoin.it'],
        'nofluffjobs'=>['name'=>'No Fluff Jobs','url'=>'https://nofluffjobs.com/','domain'=>'nofluffjobs.com'],
        'pracuj'=>['name'=>'Pracuj.pl','url'=>'https://www.pracuj.pl/','domain'=>'pracuj.pl'],
        'workua'=>['name'=>'Work.ua','url'=>'https://www.work.ua/','domain'=>'work.ua'],
        'robota'=>['name'=>'robota.ua','url'=>'https://robota.ua/','domain'=>'robota.ua'],
    ];
    public function normalize(array $options): array
    {
        $sources=$options['selected_sources']??self::DEFAULT_SOURCES;
        $language=$options['requested_language']??'auto';$mode=$options['adaptation_mode']??'enhanced';
        if(!is_array($sources) || !array_is_list($sources) || count($sources)<1 || count($sources)>count(self::SOURCES)
            || count(array_unique($sources,SORT_REGULAR))!==count($sources)
            || array_filter($sources,static fn($source)=>!is_string($source) || !isset(self::SOURCES[$source]))
            || !in_array($language,['auto','pl','uk','en'],true) || !in_array($mode,['enhanced','strict'],true)){
            throw new \InvalidArgumentException('batch.options_invalid');
        }
        return ['selected_sources'=>array_values($sources),'requested_language'=>$language,'adaptation_mode'=>$mode];
    }
    public function forInput(array $input): array
    {
        return $this->normalize(array_intersect_key($input,array_flip(['selected_sources','requested_language','adaptation_mode'])));
    }
    public function sourceLanguageHint(string $text,string $fallback): string
    {
        $text=mb_strtolower($text);$scores=[];
        foreach(['pl'=>['doświadczenie','umiejętności','wykształcenie','języki','aktualnie','polski','zawodowe'],
            'uk'=>['досвід','навички','освіта','мови','українська','профіль','розробник'],
            'en'=>['experience','skills','education','languages','professional summary','developer','employment']] as $language=>$terms){
            $scores[$language]=array_sum(array_map(static fn($term)=>mb_substr_count($text,$term),$terms));
        }
        arsort($scores);return reset($scores)>0?array_key_first($scores):(in_array($fallback,['pl','uk','en'],true)?$fallback:'en');
    }
    public function sourceForUrl(string $url): ?string
    {
        $parts=parse_url($url);
        if(!filter_var($url,FILTER_VALIDATE_URL) || ($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['pass'])){return null;}
        $host=strtolower($parts['host']??'');
        foreach(self::SOURCES as $id=>$source){if($host===$source['domain'] || str_ends_with($host,'.'.$source['domain'])){return $id;}}
        return null;
    }
}
