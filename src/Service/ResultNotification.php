<?php
declare(strict_types=1);
namespace App\Service;
use App\Entity\LocalWorkRequest;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
final class ResultNotification
{
    public function __construct(private MailerInterface $mailer, private Environment $twig, private EntityManagerInterface $em,
        private TranslatorInterface $translator, private LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private string $from, #[Autowire('%env(DEFAULT_URI)%')] private string $origin) {}
    public function send(LocalWorkRequest $job): bool
    {
        if ($job->notificationSentAt) { return true; }
        $parts=parse_url($this->origin);
        if (!$job->user->isVerified || !filter_var($job->user->email,FILTER_VALIDATE_EMAIL) || !filter_var($this->from,FILTER_VALIDATE_EMAIL)
            || ($parts['scheme']??'')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path']??'',['','/'],true)) { return false; }
        $link=rtrim($this->origin,'/').'/requests/'.$job->id;
        $locale=$job->result['language']??$job->locale;if(!in_array($locale,['pl','uk','en'],true)){$locale=$job->locale;}
        $subject=$this->translator->trans('batch.mail_subject',[],null,$locale);
        $body=$this->translator->trans('batch.mail_body',[],null,$locale);
        $button=$this->translator->trans('batch.open',[],null,$locale);
        try {
            $this->mailer->send((new Email())->from($this->from)->to($job->user->email)->subject($subject)->text($body."\n\n".$link)
                ->html($this->twig->render('email/result.html.twig',['subject'=>$subject,'body'=>$body,'button'=>$button,'link'=>$link,'origin'=>rtrim($this->origin,'/'),'locale'=>$locale])));
            $job->notificationSentAt=new \DateTimeImmutable(); $this->em->flush(); return true;
        } catch (\Throwable) { $this->logger->warning('WorkLook result notification pending.', ['request_id'=>$job->id]); return false; }
    }
}
