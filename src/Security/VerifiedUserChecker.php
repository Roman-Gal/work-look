<?php
namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\{UserCheckerInterface, UserInterface};

final class VerifiedUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void {}
    public function checkPostAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isVerified) {
            throw new CustomUserMessageAccountStatusException('Email confirmation required.');
        }
    }
}
