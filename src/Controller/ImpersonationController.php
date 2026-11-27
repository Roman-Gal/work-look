<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\UserImpersonator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class ImpersonationController extends AbstractController
{
    #[Route('/account/impersonation/exit', name: 'app_impersonation_exit', methods: ['POST'])]
    public function exit(Request $request, UserImpersonator $impersonator): Response
    {
        $this->denyAccessUnlessGranted('IS_IMPERSONATOR');
        if (!$this->isCsrfTokenValid('impersonation_exit', $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        return $this->redirectToRoute('app_admin_user', ['id' => $impersonator->leave($request)], 303);
    }
}
