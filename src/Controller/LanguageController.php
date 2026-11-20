<?php
namespace App\Controller;

use App\EventSubscriber\LocaleSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Cookie, Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class LanguageController extends AbstractController
{
    #[Route('/language/{locale}', name: 'app_language', requirements: ['locale' => 'uk|pl|en'], methods: ['POST'])]
    public function change(string $locale, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('language', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $returnTo = $request->request->get('return_to', '/');
        // Relative paths only: never redirect to a supplied host or back to a POST endpoint.
        if (!is_string($returnTo) || !preg_match('~^/(?:login|register|verify-email|profile|runs/[a-f0-9]{32}|matches/[0-9]+)?$~D', $returnTo)) {
            $returnTo = '/';
        }
        $response = $this->redirect($returnTo, Response::HTTP_SEE_OTHER);
        $response->headers->setCookie(Cookie::create(LocaleSubscriber::COOKIE, $locale)
            ->withExpires(new \DateTimeImmutable('+1 year'))->withPath('/')
            ->withSecure($request->isSecure())->withHttpOnly(true)->withSameSite(Cookie::SAMESITE_LAX));
        return $response;
    }
}
