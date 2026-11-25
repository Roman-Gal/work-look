<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\LocalWorkRequest;

final class LocalWorkProtocol
{
    private function text(mixed $value,int $max,bool $empty=false): string
    {
        if(!is_string($value) || mb_strlen($value)>$max || (!$empty && trim($value)==='')){throw new \InvalidArgumentException('Invalid workflow text.');}
        return trim($value);
    }
    public function extendResult(array $data,LocalWorkRequest $job,array $clean,array $sourceLinks): array
    {
        $settings=(new LocalSearchSettings())->forInput($job->input);$catalog=new LocalSearchSettings();
        $language=$data['language']??null;
        if(($data['protocol_version']??null)!==2 || !in_array($language,['pl','uk','en'],true)
            || ($settings['requested_language']!=='auto' && $language!==$settings['requested_language'])){throw new \InvalidArgumentException('Invalid result language or workflow version.');}
        $links=$data['candidate_links']??null;$reports=$data['source_report']??null;
        if(!is_array($links) || !array_is_list($links) || count($links)>100 || !is_array($reports) || !array_is_list($reports)
            || count($reports)!==count($settings['selected_sources'])){throw new \InvalidArgumentException('Link classification and every selected site report are required.');}
        $seen=[];$described=[];$formatter=new CvLinks();
        foreach($links as $link){
            if(!is_array($link) || !is_string($link['url']??null) || ($url=$formatter->target($link['url']))===null || !in_array($url,$sourceLinks,true)
                || isset($seen[$url]) || !in_array($link['category']??null,['certificate','code','linkedin','social','portfolio','website','email','other'],true)){
                throw new \InvalidArgumentException('Candidate links must preserve source destinations.');
            }
            $described[]=['url'=>$url,'label'=>$this->text($link['label']??null,160),'category'=>$link['category']];$seen[$url]=true;
        }
        $seen=[];$coverage=[];
        foreach($reports as $report){
            if(!is_array($report) || !in_array($report['source']??null,$settings['selected_sources'],true) || isset($seen[$report['source']])
                || !in_array($report['status']??null,['checked','no_results','blocked','not_checked'],true) || !is_array($report['checked_urls']??null)
                || !array_is_list($report['checked_urls']) || count($report['checked_urls'])>20){throw new \InvalidArgumentException('Invalid selected site coverage.');}
            foreach($report['checked_urls'] as $url){if(!is_string($url) || strlen($url)>2000 || $catalog->sourceForUrl($url)!==$report['source']){throw new \InvalidArgumentException('Checked URLs must belong to their selected site.');}}
            if(in_array($report['status'],['checked','no_results'],true) && $report['checked_urls']===[]){throw new \InvalidArgumentException('A checked source needs a checked URL.');}
            $coverage[]=['source'=>$report['source'],'status'=>$report['status'],'details'=>$this->text($report['details']??null,3000),'checked_urls'=>$report['checked_urls']];$seen[$report['source']]=true;
        }
        $source=preg_replace('/\s+/u',' ',$job->input['resume_text']);
        foreach($clean['matches'] as $index=>&$match){
            $original=$data['matches'][$index];$site=$catalog->sourceForUrl($match['url']);
            if(!in_array($original['source']??null,$settings['selected_sources'],true) || $site!==$original['source']){throw new \InvalidArgumentException('Vacancy must belong to a selected site.');}
            $match['source']=$site;$match['conclusion']=$this->text($original['conclusion']??null,5000);
            $adaptations=$original['adaptations']??null;
            if(!is_array($adaptations) || !array_is_list($adaptations) || count($adaptations)>30){throw new \InvalidArgumentException('CV adaptation notes are required.');}
            $match['adaptations']=[];
            foreach($adaptations as $adaptation){
                if(!is_array($adaptation) || !in_array($adaptation['kind']??null,['wording','inference'],true)
                    || ($adaptation['kind']==='inference' && $settings['adaptation_mode']==='strict')){throw new \InvalidArgumentException('Unsupported adaptation kind or inference in strict mode.');}
                $basis=$this->text($adaptation['basis']??null,3000);
                if(mb_strlen($basis)<8 || !str_contains($source,preg_replace('/\s+/u',' ',$basis))){throw new \InvalidArgumentException('Adaptations must quote a source basis.');}
                $match['adaptations'][]=['kind'=>$adaptation['kind'],'description'=>$this->text($adaptation['description']??null,3000),'basis'=>$basis];
            }
        }
        unset($match);
        return $clean+['protocol_version'=>2,'language'=>$language,'candidate_links'=>$described,'source_report'=>$coverage];
    }
}
