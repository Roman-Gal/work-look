<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\{LocalWorkRequest, ResumeDraft};
use App\Service\{CvPdfRenderer, LocalWork};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;

final class LocalWorkController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em) {}
    #[Route('/requests', name:'app_local_requests', methods:['GET'])]
    public function index(Request $request): Response
    {
        $page=max(1,min(10000,$request->query->getInt('page',1))); $repository=$this->em->getRepository(LocalWorkRequest::class);
        return $this->render('local_work/index.html.twig',['jobs'=>$repository->findBy(['user'=>$this->getUser()],['createdAt'=>'DESC'],50,($page-1)*50),
            'page'=>$page,'hasMore'=>$repository->count(['user'=>$this->getUser()])>$page*50]);
    }
    #[Route('/requests/new', name:'app_local_start', methods:['POST'])]
    public function start(Request $request, LocalWork $work, LockFactory $locks): Response
    {
        if (!$this->isCsrfTokenValid('local_work_start',$request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        if (!$this->getUser()->liveAiEnabled) { $this->addFlash('warning','trial.account_disabled'); return $this->redirectToRoute('app_dashboard',status:303); }
        $draft=$this->em->getRepository(ResumeDraft::class)->findOneBy(['user'=>$this->getUser()]);
        $lock=$locks->createLock('local_work_start_'.$this->getUser()->id,30);
        if (!$lock->acquire()) { return $this->redirectToRoute('app_local_requests'); }
        try {
            if (!$draft) { throw new \InvalidArgumentException('batch.setup'); }
            $job=$work->start($draft,$request->getLocale());
            return $this->redirectToRoute('app_local_result',['id'=>$job->id],303);
        } catch (\InvalidArgumentException $error) {
            $this->addFlash('warning',$error->getMessage()); return $this->redirectToRoute('app_resume',status:303);
        } finally { $lock->release(); }
    }
    private function owned(string $id): LocalWorkRequest
    {
        return $this->em->getRepository(LocalWorkRequest::class)->findOneBy(['id'=>$id,'user'=>$this->getUser()]) ?? throw $this->createNotFoundException();
    }
    #[Route('/requests/{id}', name:'app_local_result', requirements:['id'=>'[a-f0-9]{32}'], methods:['GET'])]
    public function result(string $id): Response { return $this->render('local_work/result.html.twig',['job'=>$this->owned($id)]); }
    #[Route('/requests/{id}/cv/{index}.pdf', name:'app_local_cv', requirements:['id'=>'[a-f0-9]{32}','index'=>'[0-9]'], methods:['GET'])]
    public function cv(string $id, int $index, CvPdfRenderer $pdf): Response
    {
        $job=$this->owned($id); $match=$job->result['matches'][$index]??null;
        if ($job->status!=='completed' || !$match || $match['cv_text']==='') { throw $this->createNotFoundException(); }
        return new Response($pdf->renderPrepared($match['cv_text']),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="worklook-cv-'.($index+1).'.pdf"']);
    }
    #[Route('/requests/{id}/remove', name:'app_local_remove', requirements:['id'=>'[a-f0-9]{32}'], methods:['POST'])]
    public function remove(string $id, Request $request, LockFactory $locks): Response
    {
        $job=$this->owned($id);
        if (!$this->isCsrfTokenValid('local_work_remove_'.$id,$request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $lock=$locks->createLock('local_work_'.$id,60);
        if (!$lock->acquire()) { throw $this->createAccessDeniedException(); }
        try { $this->em->remove($job); $this->em->flush(); } finally { $lock->release(); }
        return $this->redirectToRoute('app_local_requests',status:303);
    }
}
