<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\{AgentRun, JobMatch, User};
use App\Service\{ActivityQuery, ActivityRecorder, IpLocation, UserImpersonator};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private ActivityRecorder $activity) {}

    #[Route('/admin', name: 'app_admin', methods: ['GET'])]
    public function index(Request $request, ActivityQuery $query, IpLocation $location): Response
    {
        $filters = $query->filters($request);
        return $this->render('admin/index.html.twig', ['filters' => $filters, 'report' => $query->report($filters), 'geoAvailable' => $location->available(),
            'userCount' => $this->em->getRepository(User::class)->count([]), 'kinds' => ActivityQuery::KINDS]);
    }
    #[Route('/admin/users', name: 'app_admin_users', methods: ['GET'])]
    public function users(Request $request): Response
    {
        $page = max(1, min(10000, (int) $request->query->getString('page', '1')));
        $q = mb_substr(trim($request->query->getString('q')), 0, 180);
        $qb = $this->em->createQueryBuilder()->from(User::class, 'u');
        if ($q !== '') { $qb->where('LOWER(u.email) LIKE :q')->setParameter('q', '%'.mb_strtolower($q).'%'); }
        $count = (int) (clone $qb)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
        $page = min($page, max(1, (int) ceil($count / 50)));
        $users = $qb->select('u')->orderBy('u.createdAt', 'DESC')->setFirstResult(($page - 1) * 50)->setMaxResults(50)->getQuery()->getResult();
        return $this->render('admin/users.html.twig', compact('users', 'q', 'count', 'page'));
    }
    #[Route('/admin/users/{id}', name: 'app_admin_user', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function user(int $id): Response
    {
        $user = $this->em->find(User::class, $id) ?? throw $this->createNotFoundException();
        return $this->render('admin/user.html.twig', ['targetUser' => $user,
            'runs' => $this->em->getRepository(AgentRun::class)->findBy(['user' => $user], ['createdAt' => 'DESC'], 30),
            'matches' => $this->em->createQueryBuilder()->select('m', 'j', 'r')->from(JobMatch::class, 'm')->join('m.run', 'r')->join('m.job', 'j')
                ->where('r.user = :user')->setParameter('user', $user)->orderBy('m.createdAt', 'DESC')->setMaxResults(30)->getQuery()->getResult()]);
    }
    #[Route('/admin/users/{id}/impersonate', name: 'app_admin_user_impersonate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function impersonate(int $id, Request $request, UserImpersonator $impersonator): Response
    {
        if (!$this->isCsrfTokenValid('admin_impersonate_'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $target = $this->em->find(User::class, $id) ?? throw $this->createNotFoundException();
        $impersonator->enter($request, $target);
        return $this->redirectToRoute('app_dashboard', [], 303);
    }
    #[Route('/admin/users/{id}/ai', name: 'app_admin_user_ai', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ai(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_ai_'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $user = $this->em->find(User::class, $id) ?? throw $this->createNotFoundException();
        if ($user->isAdministrator()) { throw $this->createAccessDeniedException('The singleton administrator cannot be suspended here.'); }
        $enabled = $request->request->getString('enabled');
        if (!in_array($enabled, ['0', '1'], true)) { throw $this->createNotFoundException(); }
        $this->em->wrapInTransaction(function () use ($user, $enabled, $id, $request): void {
            $user->liveAiEnabled = $enabled === '1'; $this->em->flush();
            if (!$this->activity->record($request, 'admin_action', 303, $this->getUser(), ['action' => 'ai_permission_changed', 'target_user_id' => $id, 'enabled' => $user->liveAiEnabled])) {
                throw new \RuntimeException('Administrator action audit unavailable.');
            }
        });
        $this->addFlash('success', 'admin.saved');
        return $this->redirectToRoute('app_admin_user', ['id' => $id], 303);
    }
}
