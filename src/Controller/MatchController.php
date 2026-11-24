<?php
namespace App\Controller;

use App\Entity\JobMatch;
use App\Service\{CanonicalCv, CvPdfRenderer};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class MatchController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private CanonicalCv $cv, private CvPdfRenderer $pdfRenderer, private \App\Service\ActivityRecorder $activity) {}
    private function owned(int $id): JobMatch
    {
        $qb = $this->em->createQueryBuilder()->select('m', 'r', 'j')->from(JobMatch::class, 'm')
            ->join('m.run', 'r')->join('m.job', 'j')->where('m.id = :id')->setParameter('id', $id);
        if (!$this->isGranted('ROLE_ADMIN')) { $qb->andWhere('r.user = :user')->setParameter('user', $this->getUser()); }
        return $qb->getQuery()->getOneOrNullResult() ?? throw $this->createNotFoundException();
    }
    #[Route('/matches/{id}', name: 'app_match', methods: ['GET'])]
    public function show(int $id): Response
    {
        $match = $this->owned($id);
        return $this->render('match/show.html.twig', ['match' => $match, 'facts' => $match->selectedFactIds ? $this->cv->selectFacts($match->run->profileSnapshot, $match->selectedFactIds) : []]);
    }
    #[Route('/matches/{id}/status', name: 'app_match_status', methods: ['POST'])]
    public function status(int $id, Request $request): Response
    {
        $match = $this->owned($id);
        if (!$this->isCsrfTokenValid('status_'.$id, $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $status = $request->request->get('status');
        if (!in_array($status, ['New', 'Reviewed', 'Applied', 'Skipped'], true)) { throw $this->createNotFoundException(); }
        $this->em->wrapInTransaction(function () use ($match, $status, $request, $id): void {
            $match->status = $status; $this->em->flush();
            if ($this->isGranted('ROLE_ADMIN') && !$this->activity->record($request, 'admin_action', 303, $this->getUser(), ['action' => 'match_status_changed', 'match_id' => $id, 'status' => $status])) {
                throw new \RuntimeException('Administrator action audit unavailable.');
            }
        });
        return $this->redirectToRoute('app_match', ['id' => $id], Response::HTTP_SEE_OTHER);
    }
    #[Route('/matches/{id}/cv.pdf', name: 'app_cv_pdf', methods: ['GET'])]
    public function pdf(int $id): Response
    {
        $match = $this->owned($id);
        if (!$match->selectedFactIds) { throw $this->createNotFoundException(); }
        $facts = $this->cv->selectFacts($match->run->profileSnapshot, $match->selectedFactIds);
        return new Response($this->pdfRenderer->render($match, $facts), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="work-look-cv-'.$id.'.pdf"', 'Cache-Control' => 'private, no-store']);
    }
}
