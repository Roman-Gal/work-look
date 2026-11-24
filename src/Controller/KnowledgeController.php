<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\{AgentRun, KnowledgeEntry};
use App\Form\KnowledgeType;
use App\Service\ActivityRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class KnowledgeController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private ActivityRecorder $activity) {}
    #[Route('/knowledge', name: 'app_knowledge', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = mb_substr(trim($request->query->getString('q')), 0, 180); $site = $request->query->getString('site');
        $page = max(1, min(10000, $request->query->getInt('page', 1)));
        $qb = $this->em->createQueryBuilder()->from(KnowledgeEntry::class, 'k')
            ->where("(k.owner = :owner OR (k.owner IS NULL AND k.status = 'published'))")->setParameter('owner', $this->getUser());
        if ($this->isGranted('ROLE_ADMIN')) { $qb->where('(k.owner = :owner OR k.owner IS NULL)'); }
        if (in_array($site, KnowledgeEntry::SITES, true)) { $qb->andWhere('k.site = :site')->setParameter('site', $site); } else { $site = ''; }
        if ($q !== '') { $qb->andWhere('(LOWER(k.title) LIKE :q OR LOWER(k.content) LIKE :q)')->setParameter('q', '%'.mb_strtolower($q).'%'); }
        $count = (int) (clone $qb)->select('COUNT(k.id)')->getQuery()->getSingleScalarResult(); $page = min($page, max(1, (int) ceil($count / 30)));
        $entries = $qb->select('k')->orderBy('k.updatedAt', 'DESC')->setFirstResult(($page - 1) * 30)->setMaxResults(30)->getQuery()->getResult();
        return $this->render('knowledge/index.html.twig', compact('entries', 'q', 'site', 'count', 'page') + ['sites' => KnowledgeEntry::SITES]);
    }
    #[Route('/knowledge/prompts/new', name: 'app_knowledge_prompt_new', methods: ['GET', 'POST'])]
    public function newPrompt(Request $request): Response
    {
        if ($this->em->getRepository(KnowledgeEntry::class)->count(['owner' => $this->getUser()]) >= 100) {
            $this->addFlash('warning', 'kb.limit'); return $this->redirectToRoute('app_knowledge', status: 303);
        }
        $entry = new KnowledgeEntry(); $entry->owner = $this->getUser(); $entry->kind = 'search_prompt'; $entry->locale = $request->getLocale();
        if ($request->query->has('run')) {
            $run = $this->em->getRepository(AgentRun::class)->findOneBy(['id' => $request->query->getString('run'), 'user' => $this->getUser()]) ?? throw $this->createNotFoundException();
            $entry->title = 'WorkLook · '.$run->createdAt->format('Y-m-d'); $entry->content = $run->requestText;
        }
        return $this->edit($entry, $request, false);
    }
    #[Route('/knowledge/prompts/{id}/remove', name: 'app_knowledge_prompt_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removePrompt(int $id, Request $request): Response
    {
        $entry = $this->em->getRepository(KnowledgeEntry::class)->findOneBy(['id' => $id, 'owner' => $this->getUser()]) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('prompt_remove_'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $this->em->remove($entry); $this->em->flush(); return $this->redirectToRoute('app_knowledge', status: 303);
    }
    #[Route('/knowledge/prompts/{id}', name: 'app_knowledge_prompt', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function prompt(int $id, Request $request): Response
    {
        $entry = $this->em->getRepository(KnowledgeEntry::class)->findOneBy(['id' => $id, 'owner' => $this->getUser(), 'kind' => 'search_prompt']) ?? throw $this->createNotFoundException();
        return $this->edit($entry, $request, false);
    }
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/admin/knowledge/new', name: 'app_admin_knowledge_new', methods: ['GET', 'POST'])]
    public function newShared(Request $request): Response { return $this->edit(new KnowledgeEntry(), $request, true); }
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/admin/knowledge/{id}', name: 'app_admin_knowledge_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function shared(int $id, Request $request): Response
    {
        $entry = $this->em->getRepository(KnowledgeEntry::class)->findOneBy(['id' => $id, 'owner' => null]) ?? throw $this->createNotFoundException();
        return $this->edit($entry, $request, true);
    }
    private function edit(KnowledgeEntry $entry, Request $request, bool $shared): Response
    {
        $version = $entry->revision; $form = $this->createForm(KnowledgeType::class, $entry, ['shared' => $shared]); $form->handleRequest($request);
        if ($form->isSubmitted() && (string) $form->get('entryVersion')->getData() !== (string) $version) { $form->addError(new FormError('kb.conflict')); }
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->wrapInTransaction(function () use ($entry, $request, $shared): void {
                $entry->updatedAt = new \DateTimeImmutable(); $this->em->persist($entry); $this->em->flush();
                if ($shared && !$this->activity->record($request, 'admin_action', 303, $this->getUser(), ['action' => 'knowledge_updated', 'entry_id' => $entry->id, 'revision' => $entry->revision, 'status' => $entry->status])) { throw new \RuntimeException('Administrator action audit unavailable.'); }
            });
            $this->addFlash('success', 'kb.saved'); return $this->redirectToRoute('app_knowledge', status: 303);
        }
        return $this->render('knowledge/edit.html.twig', ['form' => $form, 'entry' => $entry, 'shared' => $shared]);
    }
}
