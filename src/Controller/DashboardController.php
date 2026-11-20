<?php
namespace App\Controller;

use App\Agent\OpenAiClient;
use App\Entity\{AgentRun, JobMatch, Profile, User};
use App\Form\{ProfileType, RunType};
use App\Message\StartAgentRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}

    #[Route('/', name: 'app_dashboard', methods: ['GET', 'POST'])]
    public function index(Request $request, OpenAiClient $ai, MessageBusInterface $bus, LockFactory $locks): Response
    {
        /** @var User $user */ $user = $this->getUser();
        $profile = $this->em->getRepository(Profile::class)->findOneBy(['user' => $user]);
        $form = $this->createForm(RunType::class, ['sources' => ['solidjobs'], 'minimumScore' => 65, 'mode' => $ai->isConfigured() ? 'live' : 'demo']); $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData(); $lock = $locks->createLock('start_run_user_'.$user->id, 30);
            if (!$lock->acquire()) { $this->addFlash('warning', 'flash.starting'); return $this->redirectToRoute('app_dashboard'); }
            try {
                $active = $this->em->getRepository(AgentRun::class)->findOneBy(['user' => $user, 'status' => ['queued', 'running']]);
                if ($active) { $this->addFlash('warning', 'flash.active'); return $this->redirectToRoute('app_run', ['id' => $active->id]); }
                if ($data['mode'] === 'live' && (!$profile || $profile->snapshot()['facts'] === [] || !$ai->isConfigured())) {
                    $this->addFlash('warning', 'flash.setup'); return $this->redirectToRoute('app_dashboard');
                }
                $run = new AgentRun($user); $run->mode = $data['mode']; $run->requestText = $data['requestText'] ?? '';
                $run->profileSnapshot = ($profile ?? new Profile($user))->snapshot();
                $run->profileSnapshot['ui_locale'] = $request->getLocale();
                $run->sources = $data['sources']; $run->minimumScore = $data['minimumScore'];
                $run->addEvent('event.queued'); $this->em->persist($run); $this->em->flush();
                try { $bus->dispatch(new StartAgentRun($run->id, $user->id)); }
                catch (\Throwable $error) { $run->status = 'failed'; $run->addEvent('event.queue_error', 'error'); $this->em->flush(); throw $error; }
                return $this->redirectToRoute('app_run', ['id' => $run->id], Response::HTTP_SEE_OTHER);
            } finally { $lock->release(); }
        }
        $runs = $this->em->getRepository(AgentRun::class)->findBy(['user' => $user], ['createdAt' => 'DESC'], 8);
        $matches = $this->em->createQueryBuilder()->select('m', 'j', 'r')->from(JobMatch::class, 'm')->join('m.job', 'j')->join('m.run', 'r')
            ->where('r.user = :user')->setParameter('user', $user)->orderBy('m.createdAt', 'DESC')->setMaxResults(12)->getQuery()->getResult();
        return $this->render('dashboard/index.html.twig', ['runForm' => $form, 'profile' => $profile, 'runs' => $runs, 'matches' => $matches,
            'aiConfigured' => $ai->isConfigured(), 'activeCount' => count(array_filter($runs, fn (AgentRun $r) => $r->isActive()))]);
    }

    #[Route('/profile', name: 'app_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request): Response
    {
        /** @var User $user */ $user = $this->getUser();
        $profile = $this->em->getRepository(Profile::class)->findOneBy(['user' => $user]) ?? new Profile($user);
        $form = $this->createForm(ProfileType::class, $profile); $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $profile->updatedAt = new \DateTimeImmutable(); $this->em->persist($profile); $this->em->flush();
            $this->addFlash('success', 'flash.saved');
            return $this->redirectToRoute('app_profile', status: Response::HTTP_SEE_OTHER);
        }
        return $this->render('profile/edit.html.twig', ['form' => $form, 'profile' => $profile]);
    }
}
