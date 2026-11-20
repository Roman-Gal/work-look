<?php
namespace App\Controller;

use App\Entity\{AgentRun, JobMatch};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class RunController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}
    private function owned(string $id): AgentRun
    {
        return $this->em->getRepository(AgentRun::class)->findOneBy(['id' => $id, 'user' => $this->getUser()]) ?? throw $this->createNotFoundException();
    }
    #[Route('/runs/{id}', name: 'app_run', methods: ['GET'])]
    public function show(string $id): Response
    {
        $run = $this->owned($id);
        return $this->render('run/show.html.twig', ['run' => $run, 'matches' => $this->em->getRepository(JobMatch::class)->findBy(['run' => $run], ['score' => 'DESC'])]);
    }
    #[Route('/runs/{id}/cancel', name: 'app_run_cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request): Response
    {
        $run = $this->owned($id);
        if (!$this->isCsrfTokenValid('cancel_'.$id, $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        if ($run->isActive()) {
            $this->em->getConnection()->executeStatement('UPDATE agent_run SET cancel_requested = ? WHERE id = ?', [true, $id], [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::STRING]);
            $this->addFlash('success', 'flash.cancel');
        }
        return $this->redirectToRoute('app_run', ['id' => $id], Response::HTTP_SEE_OTHER);
    }
}
