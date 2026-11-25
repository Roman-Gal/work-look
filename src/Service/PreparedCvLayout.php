<?php
declare(strict_types=1);
namespace App\Service;

/** Classify existing CV lines for presentation without generating candidate facts. */
final class PreparedCvLayout
{
    private const HEADINGS = [
        'PROFIL'=>'profile', 'PODSUMOWANIE'=>'profile', 'O MNIE'=>'profile',
        'DOŚWIADCZENIE'=>'experience', 'DOŚWIADCZENIE ZAWODOWE'=>'experience',
        'TECHNOLOGIE I UMIEJĘTNOŚCI'=>'skills', 'UMIEJĘTNOŚCI TECHNICZNE'=>'skills', 'UMIEJĘTNOŚCI'=>'skills', 'TECHNOLOGIE'=>'skills',
        'WYKSZTAŁCENIE'=>'education', 'EDUKACJA'=>'education', 'JĘZYKI'=>'languages',
        'CERTYFIKATY'=>'certificates', 'KURSY'=>'certificates', 'KURSY I CERTYFIKATY'=>'certificates',
        'PROJEKTY'=>'projects', 'PROJEKT WŁASNY'=>'projects',
        'ПРОФІЛЬ'=>'profile', 'ПРО МЕНЕ'=>'profile', 'ДОСВІД'=>'experience', 'ДОСВІД РОБОТИ'=>'experience',
        'НАВИЧКИ'=>'skills', 'ТЕХНІЧНІ НАВИЧКИ'=>'skills', 'ТЕХНОЛОГІЇ'=>'skills',
        'ОСВІТА'=>'education', 'МОВИ'=>'languages', 'СЕРТИФІКАТИ'=>'certificates', 'КУРСИ'=>'certificates',
        'ПРОЄКТИ'=>'projects', 'ПРОЕКТИ'=>'projects', 'ВЛАСНІ ПРОЄКТИ'=>'projects',
        'PROFILE'=>'profile', 'SUMMARY'=>'profile', 'PROFESSIONAL SUMMARY'=>'profile', 'ABOUT ME'=>'profile',
        'EXPERIENCE'=>'experience', 'WORK EXPERIENCE'=>'experience', 'PROFESSIONAL EXPERIENCE'=>'experience',
        'SKILLS'=>'skills', 'TECHNICAL SKILLS'=>'skills', 'TECHNOLOGIES'=>'skills',
        'EDUCATION'=>'education', 'LANGUAGES'=>'languages', 'CERTIFICATES'=>'certificates',
        'CERTIFICATIONS'=>'certificates', 'COURSES'=>'certificates', 'PROJECTS'=>'projects', 'PERSONAL PROJECTS'=>'projects',
        'LINKS'=>'links', 'LINKI'=>'links', 'ПОСИЛАННЯ'=>'links',
        'LINKI ZE ŹRÓDŁOWEGO CV'=>'links', 'LINKI Z CV'=>'links', 'SOURCE LINKS'=>'links',
        'LINKS FROM SOURCE CV'=>'links', 'ПОСИЛАННЯ З РЕЗЮМЕ'=>'links', 'ПОСИЛАННЯ З CV'=>'links',
    ];
    private function kind(string $line): ?string
    {
        $key=mb_strtoupper(trim(preg_replace('/^#{1,6}\s+|:$/u','',$line)));
        return self::HEADINGS[$key]??null;
    }
    public function parse(string $text): array
    {
        $lines=array_map('trim',preg_split('/\R/u',$text));
        while($lines!==[] && $lines[0]===''){array_shift($lines);}
        $boundary=null;
        foreach($lines as $index=>$line){if($this->kind($line)!==null){$boundary=$index;break;}}
        $header=$boundary===null?array_slice($lines,0,2):array_slice($lines,0,$boundary);
        $body=$boundary===null?array_slice($lines,2):array_slice($lines,$boundary);
        // Keep unusually long introductions in the flowing body rather than an unbreakable header.
        if(count($header)>6 || mb_strlen(implode("\n",$header))>500){$header=array_slice($lines,0,2);$body=array_slice($lines,2);}
        if((isset($header[0]) && mb_strlen($header[0])>110) || array_filter($header,static fn($line)=>mb_strlen($line)>180)){$header=[];$body=$lines;}
        $header=array_values(array_filter($header,static fn($line)=>$line!==''));
        $name=array_shift($header)??''; $headline='';
        if(isset($header[0]) && !preg_match('/@|https?:\/\/|^\+?[0-9][0-9 ()-]{5}/u',$header[0])){$headline=array_shift($header);}
        $sections=[];$current=['heading'=>'','kind'=>'other','lines'=>[]];
        foreach($body as $line){
            $kind=$this->kind($line);
            if($kind!==null){
                if($current['lines']!==[] || $current['heading']!==''){$sections[]=$this->section($current);}
                $current=['heading'=>trim(preg_replace('/^#{1,6}\s+|:$/u','',$line)),'kind'=>$kind,'lines'=>[]];
            }else{$current['lines'][]=$line;}
        }
        if($current['lines']!==[] || $current['heading']!==''){$sections[]=$this->section($current);}
        $rows=[];
        for($i=0;$i<count($sections);$i++){
            $row=[$sections[$i]];
            if($this->compact($sections[$i]) && isset($sections[$i+1]) && $this->compact($sections[$i+1])){$row[]=$sections[++$i];}
            $rows[]=$row;
        }
        return ['name'=>$name,'headline'=>$headline,'contacts'=>$header,'rows'=>$rows];
    }
    private function compact(array $section): bool
    {
        return in_array($section['kind'],['education','languages','certificates'],true) && $section['characters']<=500 && count($section['blocks'])<=6;
    }
    private function section(array $section): array
    {
        $blocks=[];$paragraph=[];$items=[];
        $flushParagraph=static function()use(&$blocks,&$paragraph):void{if($paragraph!==[]){$blocks[]=['type'=>'paragraph','text'=>implode("\n",$paragraph)];$paragraph=[];}};
        $flushItems=static function()use(&$blocks,&$items):void{if($items!==[]){$blocks[]=['type'=>'list','items'=>$items];$items=[];}};
        $lines=$section['lines'];
        foreach($lines as $i=>$line){
            if($line===''){$flushParagraph();$flushItems();continue;}
            if(preg_match('/^[-*•]\s+(.+)$/u',$line,$match)){$flushParagraph();$items[]=$match[1];continue;}
            $flushItems();
            if($section['kind']==='skills'){$flushParagraph();$blocks[]=['type'=>'skill','text'=>$line];continue;}
            $isHistory=in_array($section['kind'],['experience','projects','education','certificates'],true);
            if($isHistory && isset($lines[$i+1]) && mb_strlen($line)<=130 && preg_match('/\b(?:19|20)\d{2}\b/u',$lines[$i+1])){
                $flushParagraph();$blocks[]=['type'=>'subheading','text'=>$line];
            }elseif($isHistory && preg_match('/\b(?:19|20)\d{2}\b/u',$line)){
                $flushParagraph();$blocks[]=['type'=>'meta','text'=>$line];
            }else{$paragraph[]=$line;}
        }
        $flushParagraph();$flushItems();
        return ['heading'=>$section['heading'],'kind'=>$section['kind'],'blocks'=>$blocks,'characters'=>mb_strlen(implode("\n",$lines))];
    }
}
