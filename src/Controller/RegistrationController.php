<?php
namespace App\Controller;

use App\Service\EmailRegistration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private EmailRegistration $registration,
        #[Autowire(service: 'limiter.registration_ip')] private RateLimiterFactory $ipLimiter,
        #[Autowire(service: 'limiter.registration_email')] private RateLimiterFactory $emailLimiter,
        #[Autowire(service: 'limiter.registration_global')] private RateLimiterFactory $globalLimiter,
    ) {}

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request): Response
    {
        if ($this->getUser()) { return $this->redirectToRoute('app_dashboard'); }
        $notice = null; $error = null; $email = '';
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register', $request->request->getString('_token'))) { throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Invalid CSRF token.'); }
            $email = mb_strtolower(trim($request->request->getString('email')));
            if (!$this->registration->isEnabled()) { $error = 'registration.unavailable'; }
            elseif (strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'registration.invalid_email'; }
            else {
                // Same response for new/existing/throttled addresses prevents account enumeration.
                $ip = $this->ipLimiter->create(hash('sha256', $request->getClientIp() ?? 'unknown'))->consume()->isAccepted();
                $address = $this->emailLimiter->create(hash('sha256', $email))->consume()->isAccepted();
                if ($ip && $address && $this->globalLimiter->create('all')->consume()->isAccepted()) {
                    $this->registration->request($email, $request->getLocale());
                }
                $notice = 'registration.check_mail'; $email = '';
            }
        }
        return $this->render('security/register.html.twig', ['enabled' => $this->registration->isEnabled(), 'notice' => $notice, 'error' => $error, 'email' => $email]);
    }

    #[Route('/verify-email', name: 'app_verify_email', methods: ['GET', 'POST'])]
    public function verify(Request $request): Response
    {
        if ($this->getUser()) { return $this->redirectToRoute('app_dashboard'); }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('verify_email', $request->request->getString('_token'))) { throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Invalid CSRF token.'); }
            $token = trim($request->request->getString('verification_code'));
            $password = $request->request->getString('password');
            if (mb_strlen($password) < 15 || mb_strlen($password) > 128 || str_contains($password, "\0")) { $error = 'registration.password_length'; }
            elseif (!hash_equals($password, $request->request->getString('password_confirm'))) { $error = 'registration.password_mismatch'; }
            elseif (!$this->registration->confirm($token, $password)) { $error = 'registration.invalid_link'; }
            else {
                $this->addFlash('success', 'registration.verified');
                return $this->redirectToRoute('app_login', [], Response::HTTP_SEE_OTHER);
            }
        }
        return $this->render('security/verify.html.twig', ['error' => $error, 'enabled' => $this->registration->isEnabled()]);
    }
}
