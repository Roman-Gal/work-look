<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\User;
use App\Security\VerifiedUserChecker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\Firewall\SwitchUserListener;
use Symfony\Component\Security\Http\SecurityEvents;

/** Native Symfony tokens, reached only through the application's POST + CSRF controllers. */
final class UserImpersonator
{
    public function __construct(
        private TokenStorageInterface $tokens,
        #[Autowire(service: 'security.user.provider.concrete.app_users')] private UserProviderInterface $users,
        private VerifiedUserChecker $checker,
        private AccessDecisionManagerInterface $decisions,
        #[Autowire(service: 'kernel')] private HttpKernelInterface $kernel,
        private ActivityRecorder $activity,
    ) {}

    public function enter(Request $request, User $target): void
    {
        $token = $this->tokens->getToken();
        $admin = $token?->getUser();
        if ($token instanceof SwitchUserToken || !$admin instanceof User || !$admin->isAdministrator()
            || !$target->isVerified || $target->isAdministrator() || $target->id === $admin->id) {
            throw new AccessDeniedException();
        }
        $this->switch($request, $target->email, $target, 'impersonation_started');
    }

    public function leave(Request $request): int
    {
        $token = $this->tokens->getToken();
        if (!$token instanceof SwitchUserToken || !$token->getUser() instanceof User) { throw new AccessDeniedException(); }
        $target = $token->getUser();
        $this->switch($request, SwitchUserListener::EXIT_VALUE, $target, 'impersonation_ended');
        return $target->id;
    }

    private function switch(Request $request, string $identifier, User $target, string $action): void
    {
        if (!$request->isMethod('POST')) { throw new AccessDeniedException(); }
        $events = new EventDispatcher();
        // Symfony dispatches this event before replacing the active token. Audit failure leaves identity intact.
        $events->addListener(SecurityEvents::SWITCH_USER, function (SwitchUserEvent $event) use ($target, $action): void {
            $token = $event->getToken();
            $admin = ($token instanceof SwitchUserToken ? $token->getOriginalToken() : $token)?->getUser();
            if (!$admin instanceof User || !$admin->isAdministrator()) { throw new AccessDeniedException(); }
            if ($token instanceof SwitchUserToken) {
                $effective = $token->getUser();
                if (!$effective instanceof User || $effective->id !== $target->id || !$effective->isVerified || $effective->isAdministrator()) {
                    throw new AccessDeniedException();
                }
            }
            if (!$this->activity->record($event->getRequest(), 'admin_action', 303, $admin, [
                'action' => $action, 'target_user_id' => $target->id,
                'impersonator_user_id' => $admin->id, 'effective_user_id' => $target->id,
            ])) { throw new \RuntimeException('Administrator action audit unavailable.'); }
        });
        // No firewall switch_user parameter: query strings and headers cannot trigger a switch.
        $controlled = $request->duplicate([], ['_worklook_impersonate' => $identifier]);
        $listener = new SwitchUserListener($this->tokens, $this->users, $this->checker, 'main', $this->decisions,
            usernameParameter: '_worklook_impersonate', role: 'ROLE_ADMIN', dispatcher: $events);
        $listener->supports($controlled);
        $listener->authenticate(new RequestEvent($this->kernel, $controlled, HttpKernelInterface::MAIN_REQUEST));
        $request->getSession()->migrate(true);
    }
}
