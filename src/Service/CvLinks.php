<?php
declare(strict_types=1);
namespace App\Service;

use Twig\Markup;

/** Convert candidate-provided addresses to escaped PDF links without fetching them. */
final class CvLinks
{
    // Common bare domains are recognized; framework names such as Node.js stay plain text.
    private const PATTERN = <<<'REGEX'
~\[(?<label>[^\]\r\n]{1,200})\]\((?<target>(?:[^()\s]|\([^()\s]*\))+)\)
|(?<address>https?://[^\s<>"']+|mailto:[^\s<>"']+|www\.[^\s<>"']+
|(?<![\pL\pN_@/.])(?:[a-z0-9.!#$%&'*+/=?^_`{|}\~-]+)@(?:[a-z0-9-]+\.)+[a-z]{2,63}
|(?<![\pL\pN_@/:.])(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+(?:com|org|net|io|dev|app|me|co|info|biz|eu|uk|ua|pl|us|de|fr|nl|be|es|it|se|fi|cz|sk|at|ch|ca|au|xyz|online|site|tech|ai|cloud|studio|design)\b(?::[0-9]{1,5})?(?:[/?\#][^\s<>"']*)?)~iux
REGEX;

    public function target(string $value): ?string
    {
        if($value==='' || strlen($value)>2000 || preg_match('/[\x00-\x20\x7f\\\\]|%0[ad]/i',$value)){return null;}
        $email=str_starts_with(strtolower($value),'mailto:')?substr($value,7):$value;
        if(filter_var($email,FILTER_VALIDATE_EMAIL)){return 'mailto:'.$email;}
        if(!preg_match('~^https?://~i',$value)){
            if(!preg_match('~^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}(?::[0-9]{1,5})?(?:[/?#]|$)~i',$value)){return null;}
            $value='https://'.$value;
        }
        $parts=parse_url($value);
        if(!filter_var($value,FILTER_VALIDATE_URL) || !in_array(strtolower($parts['scheme']??''),['https','http'],true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])){return null;}
        return $value;
    }
    public function extract(string $text): array
    {
        return array_values(array_unique(array_column(array_filter($this->segments($text),static fn($part)=>isset($part['url'])),'url')));
    }
    public function render(string $text, bool $compact = false): Markup
    {
        $html='';
        foreach($this->segments($text) as $part){
            if($compact && isset($part['url']) && !$part['labelled']){$html.=$this->link($part['url']);continue;}
            $label=htmlspecialchars($part['text'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $html.=isset($part['url'])?'<a href="'.htmlspecialchars($part['url'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">'.$label.'</a>':$label;
        }
        return new Markup($html,'UTF-8');
    }
    public function link(string $destination): Markup
    {
        $url=$this->target($destination);
        if($url===null){return new Markup(htmlspecialchars($destination,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),'UTF-8');}
        if(str_starts_with($url,'mailto:')){$label=substr($url,7);}
        else{
            $parts=parse_url($url);$path=rtrim($parts['path']??'','/');$label=$parts['host'].$path;
            if(mb_strlen($label)>60){$label=$parts['host'].' / '.basename($path);}
            if(mb_strlen($label)>90){$label=$parts['host'];}
        }
        $label=mb_strimwidth($label,0,60,'…','UTF-8');
        return new Markup('<a href="'.htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">'.htmlspecialchars($label,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</a>','UTF-8');
    }
    private function segments(string $text): array
    {
        preg_match_all(self::PATTERN,$text,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE|PREG_UNMATCHED_AS_NULL);
        $parts=[];$offset=0;
        foreach($matches as $match){
            [$whole,$position]=$match[0];
            $parts[]=['text'=>substr($text,$offset,$position-$offset)];
            $markdown=$match['target'][0]!==null;
            $address=$markdown?$match['target'][0]:$match['address'][0];
            if(!$markdown){
                $address=rtrim($address,'.,;:!?');
                foreach([')'=>'(',']'=>'[','}'=>'{'] as $close=>$open){
                    while(str_ends_with($address,$close) && substr_count($address,$close)>substr_count($address,$open)){$address=substr($address,0,-1);}
                }
            }
            $url=$this->target($address);
            if($url!==null){
                $parts[]=['text'=>$markdown?$match['label'][0]:$address,'url'=>$url,'labelled'=>$markdown];
                if(!$markdown){$parts[]=['text'=>substr($whole,strlen($address))];}
            }else{$parts[]=['text'=>$whole];}
            $offset=$position+strlen($whole);
        }
        $parts[]=['text'=>substr($text,$offset)];
        return $parts;
    }
}
