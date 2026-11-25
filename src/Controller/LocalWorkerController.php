<?php
declare(strict_types=1);
namespace App\Controller;

use App\Entity\LocalWorkRequest;
use App\Service\{LocalWork, ResultNotification};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, UnauthorizedHttpException};
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/internal/local-work')]
final class LocalWorkerController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private LocalWork $work,
        #[Autowire('%env(LOCAL_WORKER_TOKEN_SHA256)%')] private string $tokenHash) {}
    private function authorize(Request $request): void
    {
        $header=$request->headers->get('Authorization','');
        if (!preg_match('/^[a-f0-9]{64}$/D',$this->tokenHash) || !preg_match('/^Bearer ([a-f0-9]{64})$/D',$header,$matches)
            || !hash_equals($this->tokenHash,hash('sha256',$matches[1]))) { throw new UnauthorizedHttpException('Bearer'); }
    }
    private function job(string $id): LocalWorkRequest { return $this->em->find(LocalWorkRequest::class,$id) ?? throw $this->createNotFoundException(); }
    #[Route('/manifest', name:'worker_manifest', methods:['GET'])]
    public function manifest(Request $request): JsonResponse
    {
        $this->authorize($request);
        // Deliberately no resume text, file bytes, email addresses or private prompts in the inventory.
        $rows=$this->em->getConnection()->fetchAllAssociative('SELECT id, status FROM local_work_request ORDER BY created_at ASC');
        return $this->json(['schema_version'=>1,'requests'=>$rows]);
    }
    #[Route('/knowledge', name:'worker_knowledge', methods:['GET'])]
    public function knowledge(Request $request): JsonResponse { $this->authorize($request); return $this->json(['entries'=>$this->work->knowledge()]); }
    #[Route('/{id}/package', name:'worker_package', requirements:['id'=>'[a-f0-9]{32}'], methods:['GET'])]
    public function package(string $id, Request $request): JsonResponse
    {
        $this->authorize($request); return $this->json($this->work->package($this->job($id)));
    }
    #[Route('/{id}/original.pdf', name:'worker_pdf', requirements:['id'=>'[a-f0-9]{32}'], methods:['GET'])]
    public function pdf(string $id, Request $request): Response
    {
        $this->authorize($request); return new Response($this->job($id)->pdfBytes(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="resume.pdf"']);
    }
    #[Route('/{id}/result', name:'worker_result', requirements:['id'=>'[a-f0-9]{32}'], methods:['POST'])]
    public function publish(string $id, Request $request, LockFactory $locks, ResultNotification $notification): JsonResponse
    {
        $this->authorize($request);
        if (strlen($request->getContent())>500000 || $request->headers->get('X-Operator-Reviewed')!=='yes') { throw new BadRequestHttpException('Reviewed results up to 500 KB are required.'); }
        try { $data=json_decode($request->getContent(),true,32,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new BadRequestHttpException('Invalid JSON.'); }
        if (!is_array($data)) { throw new BadRequestHttpException('Invalid JSON object.'); }
        $lock=$locks->createLock('local_work_'.$id,120);
        if (!$lock->acquire()) { return $this->json(['retry'=>true],409); }
        try {
            $job=$this->job($id); $this->em->refresh($job);
            try { $clean=$this->work->validate($data,$job); }
            catch (\InvalidArgumentException $error) { return $this->json(['error'=>$error->getMessage()],422); }
            if ($job->status==='completed') {
                if ($job->result!==$clean) { return $this->json(['error'=>'This result is already published.'],409); }
            } else {
                $job->result=$clean; $job->status='completed'; $job->activeUserKey=null; $job->completedAt=new \DateTimeImmutable(); $this->em->flush();
            }
            $sent=$notification->send($job);
            return $this->json(['published'=>true,'notification_sent'=>$sent,'result_path'=>'/requests/'.$id]);
        } finally { $lock->release(); }
    }
}
