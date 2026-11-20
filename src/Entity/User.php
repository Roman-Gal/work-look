<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    public string $email = '';

    #[ORM\Column]
    public string $password = '';

    #[ORM\Column(options: ['default' => false])]
    public bool $isVerified = false;

    #[ORM\Column(options: ['default' => false])]
    public bool $liveAiEnabled = false;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    public ?string $verificationHash = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $verificationExpiresAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getUserIdentifier(): string { return $this->email; }
    public function getRoles(): array { return ['ROLE_USER']; }
    public function getPassword(): string { return $this->password; }
    public function eraseCredentials(): void {}
}
