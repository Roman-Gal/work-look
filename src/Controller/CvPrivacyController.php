<?php
namespace App\Controller;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
final class CvPrivacyController extends AbstractController
{
    #[Route('/privacy/cv', name:'app_cv_privacy', methods:['GET'])]
    public function index(): Response { return $this->render('privacy/cv.html.twig'); }
}
