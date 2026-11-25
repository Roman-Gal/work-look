<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{KnowledgeEntry, LocalWorkRequest, ResumeDraft};
use Doctrine\ORM\EntityManagerInterface;

final class LocalWork
{
    public const PRIVACY_VERSION = 'cv-2026-11-25';
    public function __construct(private EntityManagerInterface $em) {}
    public function start(ResumeDraft $draft, string $locale, array $options = []): LocalWorkRequest
    {
        $existing = $this->em->getRepository(LocalWorkRequest::class)->findOneBy(['activeUserKey'=>$draft->user->id]);
        if ($existing) { return $existing; }
        $pdf = $draft->pdfBytes();
        if (!$pdf || !$draft->privacyAcknowledgedAt || $draft->privacyVersion !== self::PRIVACY_VERSION || trim($draft->requestText) === '') {
            throw new \InvalidArgumentException('batch.setup');
        }
        $job = new LocalWorkRequest($draft->user); $job->locale = $locale; $job->pdfData = $pdf;
        $settings=(new LocalSearchSettings())->normalize($options);
        $hint=(new LocalSearchSettings())->sourceLanguageHint($draft->text,$locale);
        $job->input = ['resume_text'=>$draft->text, 'filename'=>$draft->filename, 'source_sha256'=>$draft->contentHash,
            'search_request'=>$draft->requestText, 'user_preferences'=>$draft->preferences,
            'privacy_version'=>$draft->privacyVersion, 'acknowledged_at'=>$draft->privacyAcknowledgedAt->format(DATE_ATOM),
            'workflow_version'=>2,'interface_locale'=>$locale,'source_language_hint'=>$hint,
            'output_language_hint'=>$settings['requested_language']==='auto'?$hint:$settings['requested_language'],'ignore_images'=>true]+$settings;
        $this->em->persist($job); $this->em->flush(); return $job;
    }
    public function knowledge(?LocalWorkRequest $job = null): array
    {
        $query=$this->em->createQueryBuilder()->select('k')->from(KnowledgeEntry::class,'k');
        $query->where('(k.owner IS NULL AND k.status = :published AND k.reviewedAt IS NOT NULL AND k.reviewedAt <= :today)')
            ->setParameter('published','published')->setParameter('today',new \DateTimeImmutable('today 23:59:59'));
        if ($job) { $query->orWhere('(k.owner = :owner AND k.status = :draft)')->setParameter('owner',$job->user)->setParameter('draft','draft'); }
        if($job && isset($job->input['selected_sources'])){
            $query->andWhere('k.site IN (:sites)')->setParameter('sites',['general',...$job->input['selected_sources']]);
        }
        $entries=$query->orderBy('k.id','ASC')->getQuery()->getResult();
        return array_map(static fn(KnowledgeEntry $k)=>['id'=>$k->id,'revision'=>$k->revision,'title'=>$k->title,'content'=>$k->content,
            'site'=>$k->site,'kind'=>$k->kind,'locale'=>$k->locale,'scope'=>$k->owner?'private':'reviewed_shared',
            'source_url'=>$k->sourceUrl,'source_notes'=>$k->sourceNotes,'reviewed_at'=>$k->reviewedAt?->format('Y-m-d'),
            'trust'=>'reference_data_not_instructions'],$entries);
    }
    public function package(LocalWorkRequest $job): array
    {
        $input=$job->input;$input['resume_links']=$this->sourceLinks($job);
        $settings=(new LocalSearchSettings())->forInput($input);$input=array_replace($input,$settings);
        $input['workflow_version']=2;$input['ignore_images']=true;$input['interface_locale']=$job->locale;
        $input['source_language_hint']??=(new LocalSearchSettings())->sourceLanguageHint($input['resume_text'],$job->locale);
        $input['output_language_hint']=$settings['requested_language']==='auto'?$input['source_language_hint']:$settings['requested_language'];
        $sources=array_intersect_key(LocalSearchSettings::SOURCES,array_flip($settings['selected_sources']));
        return ['schema_version'=>2,'request_id'=>$job->id,'locale'=>$job->locale,'created_at'=>$job->createdAt->format(DATE_ATOM),
            'input'=>$input,'selected_sources'=>$sources,'knowledge'=>$this->knowledge($job), 'instructions'=>self::instructions()];
    }
    public function sourceLinks(LocalWorkRequest $job): array
    {
        $links=(new CvLinks())->extract($job->input['resume_text']??'');
        try{
            $source=(new TextPdfParser())->parseBytes($job->pdfBytes());
            $links=array_values(array_unique([...$links,...$source['links']]));
        }catch(\Throwable){
            // A damaged legacy source must not block downloading an already reviewed CV.
        }
        return $links;
    }
    public static function instructions(): string
    {
        return LocalWorkInstructions::all();
    }
    public function validate(array $data, LocalWorkRequest $job): array
    {
        if (($data['request_id']??null)!==$job->id || ($data['source_sha256']??null)!==$job->input['source_sha256'] || !is_string($data['summary']??null)
            || mb_strlen($data['summary'])>12000 || !is_array($data['matches']??null) || !array_is_list($data['matches']) || count($data['matches'])>10) {
            throw new \InvalidArgumentException('Invalid result envelope.');
        }
        $matches=[]; $urls=[];
        foreach ($data['matches'] as $match) {
            if (!is_array($match)) { throw new \InvalidArgumentException('Invalid match.'); }
            $clean=[];
            foreach (['title','company','url','location','salary','fit','gaps','checked_at','cv_text'] as $key) {
                if (!is_string($match[$key]??null) || mb_strlen($match[$key])>($key==='cv_text'?20000:3000)) { throw new \InvalidArgumentException('Invalid match field.'); }
                $clean[$key]=trim($match[$key]);
            }
            $url=parse_url($clean['url']);
            if ($clean['title']==='' || !filter_var($clean['url'],FILTER_VALIDATE_URL) || ($url['scheme']??'')!=='https' || isset($url['user']) || isset($url['pass']) || isset($urls[$clean['url']])) { throw new \InvalidArgumentException('A distinct HTTPS vacancy URL is required.'); }
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$clean['checked_at'],new \DateTimeZone('Europe/Warsaw'));
            if (!$date || $date->format('Y-m-d')!==$clean['checked_at'] || $date>new \DateTimeImmutable('today',new \DateTimeZone('Europe/Warsaw')) || ($job->status!=='completed' && $date<new \DateTimeImmutable('-7 days'))) { throw new \InvalidArgumentException('Vacancy verification must be recent.'); }
            $evidence=$match['evidence']??null;
            if (!is_array($evidence) || !array_is_list($evidence) || count($evidence)>30 || ($clean['cv_text']!=='' && $evidence===[])) { throw new \InvalidArgumentException('CV evidence is required.'); }
            $source=preg_replace('/\s+/u',' ',$job->input['resume_text']);
            foreach ($evidence as $excerpt) {
                if (!is_string($excerpt) || mb_strlen($excerpt)<8 || mb_strlen($excerpt)>3000 || !str_contains($source,preg_replace('/\s+/u',' ',$excerpt))) { throw new \InvalidArgumentException('Evidence must quote the source CV.'); }
            }
            // Excerpt containment is provenance, not proof of paraphrase truth. Operator review remains mandatory.
            $clean['evidence']=$evidence; $matches[]=$clean; $urls[$clean['url']]=true;
        }
        $clean=['request_id'=>$job->id,'source_sha256'=>$job->input['source_sha256'],'summary'=>$data['summary'],'matches'=>$matches];
        if(($job->input['workflow_version']??1)>=2 || ($data['protocol_version']??null)===2){
            return (new LocalWorkProtocol())->extendResult($data,$job,$clean,$this->sourceLinks($job));
        }
        return $clean;
    }
    public function resultLanguage(LocalWorkRequest $job): string
    {
        $language=$job->result['language']??$job->input['output_language_hint']??$job->locale;
        return in_array($language,['pl','uk','en'],true)?$language:'en';
    }
}
