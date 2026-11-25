<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\ResumeDraft;
use App\Service\{LocalSearchSettings, LocalWork, ManualResumePrompt, ResumeProfilePreparation, ResumeTextExtractor};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class ResumeController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em,
        #[Autowire(service: 'limiter.resume_import')] private RateLimiterFactory $limiter) {}
    private function draft(): ResumeDraft
    {
        return $this->em->getRepository(ResumeDraft::class)->findOneBy(['user' => $this->getUser()]) ?? throw $this->createNotFoundException();
    }
    #[Route('/resume', name: 'app_resume', methods: ['GET'])]
    public function index(): Response
    {
        $last=$this->em->getRepository(\App\Entity\LocalWorkRequest::class)->findOneBy(['user'=>$this->getUser()],['createdAt'=>'DESC']);
        return $this->render('resume/index.html.twig', ['draft' => $this->em->getRepository(ResumeDraft::class)->findOneBy(['user' => $this->getUser()]), 'jsonInput' => '',
            'searchSources'=>LocalSearchSettings::SOURCES,'searchOptions'=>(new LocalSearchSettings())->forInput($last?->input??[])]);
    }
    #[Route('/resume/upload', name: 'app_resume_upload', methods: ['POST'])]
    public function upload(Request $request, ResumeTextExtractor $extractor, LockFactory $locks): Response
    {
        if (!$this->isCsrfTokenValid('resume_upload', $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        if ($request->request->getString('privacy_ack') !== '1') { $this->addFlash('warning','batch.privacy_required'); return $this->redirectToRoute('app_resume',status:303); }
        if (!$this->limiter->create((string) $this->getUser()->id)->consume()->isAccepted()) { $this->addFlash('warning', 'resume.rate_limit'); return $this->redirectToRoute('app_resume', status: 303); }
        $file = $request->files->get('resume');
        if (!$file instanceof UploadedFile) { $this->addFlash('warning', 'resume.invalid'); return $this->redirectToRoute('app_resume', status: 303); }
        $lock = $locks->createLock('resume_upload_'.$this->getUser()->id, 30);
        if (!$lock->acquire()) { $this->addFlash('warning', 'resume.rate_limit'); return $this->redirectToRoute('app_resume', status: 303); }
        try {
            $data = $extractor->extract($file);
            $draft = $this->em->getRepository(ResumeDraft::class)->findOneBy(['user' => $this->getUser()]) ?? new ResumeDraft($this->getUser());
            $draft->text = $data['text']; $draft->pages = $data['pages']; $draft->preparedProfile = null;
            $draft->pdfData = file_get_contents($file->getPathname());
            $draft->privacyAcknowledgedAt = new \DateTimeImmutable(); $draft->privacyVersion = LocalWork::PRIVACY_VERSION;
            $draft->contentHash = hash_file('sha256', $file->getPathname());
            $draft->filename = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', mb_scrub(basename(str_replace('\\', '/', $file->getClientOriginalName())))) ?? 'resume.pdf', 0, 180);
            $draft->updatedAt = new \DateTimeImmutable(); $this->em->persist($draft); $this->em->flush();
            $this->addFlash('success', 'resume.uploaded');
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            $message = in_array($error->getMessage(), ['resume.invalid', 'resume.no_text', 'resume.too_large', 'resume.unavailable'], true) ? $error->getMessage() : 'resume.invalid';
            $this->addFlash('warning', $message);
        } finally { $lock->release(); }
        return $this->redirectToRoute('app_resume', status: 303);
    }
    #[Route('/resume/notes', name: 'app_resume_notes', methods: ['POST'])]
    public function notes(Request $request): Response
    {
        $draft = $this->draft();
        if (!$this->isCsrfTokenValid('resume_notes_'.$draft->contentHash, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $requestText = trim($request->request->getString('requestText')); $preferences = trim($request->request->getString('preferences'));
        if (mb_strlen($requestText) > 2000 || mb_strlen($preferences) > 3000) { throw $this->createAccessDeniedException(); }
        $draft->requestText = $requestText; $draft->preferences = $preferences; $draft->updatedAt = new \DateTimeImmutable(); $this->em->flush();
        $this->addFlash('success', 'flash.saved'); return $this->redirectToRoute('app_resume', status: 303);
    }
    #[Route('/resume/original.pdf', name:'app_resume_original', methods:['GET'])]
    public function original(): Response
    {
        $bytes=$this->draft()->pdfBytes();
        if (!$bytes) { throw $this->createNotFoundException(); }
        return new Response($bytes,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="resume.pdf"']);
    }
    #[Route('/resume/prompt.txt', name: 'app_resume_prompt', methods: ['GET'])]
    public function prompt(Request $request, ManualResumePrompt $prompt): Response
    {
        return new Response($prompt->build($this->draft(), $request->getLocale()), 200, ['Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="worklook-resume-prompt.txt"', 'Cache-Control' => 'private, no-store']);
    }
    #[Route('/resume/prepare', name: 'app_resume_prepare', methods: ['POST'])]
    public function prepare(Request $request, ResumeProfilePreparation $preparation): Response
    {
        $draft = $this->draft();
        if (!$this->isCsrfTokenValid('resume_prepare_'.$draft->contentHash, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $json = $request->request->getString('profile_json');
        try {
            $draft->preparedProfile = $preparation->validate($draft, $json); $this->em->flush();
        } catch (\InvalidArgumentException $error) {
            $this->addFlash('warning', $error->getMessage());
            return $this->render('resume/index.html.twig', ['draft' => $draft, 'jsonInput' => mb_substr($json, 0, 32000),
                'searchSources'=>LocalSearchSettings::SOURCES,'searchOptions'=>(new LocalSearchSettings())->normalize([])], new Response(status: 422));
        }
        return $this->redirectToRoute('app_profile', ['resume' => '1'], 303);
    }
    #[Route('/resume/remove', name: 'app_resume_remove', methods: ['POST'])]
    public function remove(Request $request): Response
    {
        $draft = $this->draft();
        if (!$this->isCsrfTokenValid('resume_remove_'.$draft->contentHash, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $this->em->remove($draft); $this->em->flush();
        $this->addFlash('success', 'resume.removed'); return $this->redirectToRoute('app_resume', status: 303);
    }
}
