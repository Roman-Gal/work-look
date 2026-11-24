<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'singleton_admin_key', columns: ['admin_key'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ADMIN_EMAIL = 'rh-additional@rh-additional.usermd.net';
    public const ADMIN_KEY = 'worklook-owner';

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $adminKey = null;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    public string $email = '';

    #[ORM\Column]
    public string $password = '';

    #[ORM\Column(options: ['default' => false])]
    public bool $isVerified = false;

    #[ORM\Column(options: ['default' => true])]
    public bool $liveAiEnabled = true;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $freeSearchRunId = null;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    public ?string $verificationHash = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $verificationExpiresAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getUserIdentifier(): string { return $this->email; }
    public function isAdministrator(): bool { return $this->isVerified && $this->email === self::ADMIN_EMAIL && $this->adminKey === self::ADMIN_KEY; }
    public function getRoles(): array { return $this->isAdministrator() ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER']; }
    public function getPassword(): string { return $this->password; }
    public function eraseCredentials(): void {}
}
