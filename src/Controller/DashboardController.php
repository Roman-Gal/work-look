<?php
namespace App\Controller;

use App\Agent\OpenAiClient;
use App\Entity\{AgentRun, JobMatch, LocalWorkRequest, Profile, ResumeDraft, User};
use App\Form\{ProfileType, RunType};
use App\Service\{SearchAllowance, SearchLauncher};
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, #[Autowire('%env(bool:LOCAL_BATCH_ENABLED)%')] private bool $localBatch) {}

    #[Route('/', name: 'app_dashboard', methods: ['GET', 'POST'])]
    public function index(Request $request, OpenAiClient $ai, SearchAllowance $allowance, SearchLauncher $launcher, LockFactory $locks, LoggerInterface $logger): Response
    {
        /** @var User $user */ $user = $this->getUser();
        $profile = $this->em->getRepository(Profile::class)->findOneBy(['user' => $user]);
        if ($this->localBatch) {
            return $this->render('local_work/dashboard.html.twig',[
                'draft'=>$this->em->getRepository(ResumeDraft::class)->findOneBy(['user'=>$user]),
                'jobs'=>$this->em->getRepository(LocalWorkRequest::class)->findBy(['user'=>$user],['createdAt'=>'DESC'],20),
            ]);
        }
        $form = $this->createForm(RunType::class); $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $lock = $locks->createLock('start_run_user_'.$user->id, 30);
            if (!$lock->acquire()) { $this->addFlash('warning', 'flash.starting'); return $this->redirectToRoute('app_dashboard'); }
            try {
                $active = $this->em->getRepository(AgentRun::class)->findOneBy(['user' => $user, 'status' => ['queued', 'running']]);
                if ($active) { $this->addFlash('warning', 'flash.active'); return $this->redirectToRoute('app_run', ['id' => $active->id]); }
                if (!$user->liveAiEnabled) { $this->addFlash('warning', 'trial.account_disabled'); return $this->redirectToRoute('app_dashboard'); }
                if ($allowance->remaining($user) === 0) { $this->addFlash('warning', 'trial.exhausted'); return $this->redirectToRoute('app_dashboard'); }
                if (!$ai->isConfigured()) { $this->addFlash('warning', 'trial.ai_unavailable'); return $this->redirectToRoute('app_dashboard'); }
                if (!$profile || $profile->snapshot()['facts'] === []) { $this->addFlash('warning', 'flash.setup'); return $this->redirectToRoute('app_profile'); }
                try { $run = $launcher->start($user, $profile, $form->getData(), $request->getLocale()); }
                catch (\Throwable $error) {
                    $logger->error('Search queue transaction failed', ['exception_type' => $error::class]);
                    $this->addFlash('warning', 'trial.queue_failed'); return $this->redirectToRoute('app_dashboard');
                }
                if (!$run) { $this->addFlash('warning', 'trial.exhausted'); return $this->redirectToRoute('app_dashboard'); }
                return $this->redirectToRoute('app_run', ['id' => $run->id], Response::HTTP_SEE_OTHER);
            } finally { $lock->release(); }
        }
        $runs = $this->em->getRepository(AgentRun::class)->findBy(['user' => $user], ['createdAt' => 'DESC'], 8);
        $matches = $this->em->createQueryBuilder()->select('m', 'j', 'r')->from(JobMatch::class, 'm')->join('m.job', 'j')->join('m.run', 'r')
            ->where('r.user = :user')->setParameter('user', $user)->orderBy('m.createdAt', 'DESC')->setMaxResults(12)->getQuery()->getResult();
        return $this->render('dashboard/index.html.twig', ['runForm' => $form, 'profile' => $profile, 'runs' => $runs, 'matches' => $matches,
            'aiConfigured' => $ai->isConfigured(), 'freeSearches' => $allowance->remaining($user), 'activeCount' => count(array_filter($runs, fn (AgentRun $r) => $r->isActive()))]);
    }

    #[Route('/profile', name: 'app_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request): Response
    {
        /** @var User $user */ $user = $this->getUser();
        $profile = $this->em->getRepository(Profile::class)->findOneBy(['user' => $user]) ?? new Profile($user);
        $preparedDraft = null;
        if ($request->query->getString('resume') === '1') {
            $preparedDraft = $this->em->getRepository(ResumeDraft::class)->findOneBy(['user' => $user]);
            if (!$preparedDraft?->preparedProfile) { return $this->redirectToRoute('app_resume'); }
            $prepared = $preparedDraft->preparedProfile;
            $profile->displayName = $prepared['display_name']; $profile->headline = $prepared['headline'];
            $profile->contactLine = $prepared['contact_line']; $profile->factsText = $prepared['facts_text'];
            $profile->preferences = $preparedDraft->preferences;
        }
        $form = $this->createForm(ProfileType::class, $profile); $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $profile->updatedAt = new \DateTimeImmutable(); $this->em->persist($profile); $this->em->flush();
            $this->addFlash('success', 'flash.saved');
            return $this->redirectToRoute('app_profile', status: Response::HTTP_SEE_OTHER);
        }
        return $this->render('profile/edit.html.twig', ['form' => $form, 'profile' => $profile, 'preparedDraft' => $preparedDraft]);
    }
}
