<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['user_id', 'created_at'])]
class AgentRun
{
    #[ORM\Id, ORM\Column(length: 32)]
    public string $id;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $user;
    #[ORM\Column(length: 24)]
    public string $status = 'queued';
    #[ORM\Column(length: 20)]
    public string $mode = 'live';
    #[ORM\Column(type: 'text')]
    public string $requestText = '';
    #[ORM\Column(type: 'json')]
    public array $profileSnapshot = [];
    #[ORM\Column(type: 'json')]
    public array $sources = [];
    #[ORM\Column(type: 'json')]
    public array $events = [];
    #[ORM\Column(type: 'text')]
    public string $summary = '';
    #[ORM\Column]
    public int $modelCalls = 0;
    #[ORM\Column]
    public int $toolCalls = 0;
    #[ORM\Column]
    public int $totalTokens = 0;
    #[ORM\Column]
    public int $minimumScore = 65;
    #[ORM\Column]
    public bool $cancelRequested = false;
    #[ORM\Column]
    public \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $startedAt = null;
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    public function __construct(User $user)
    {
        $this->id = bin2hex(random_bytes(16));
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function addEvent(string $message, string $level = 'info', array $parameters = []): void
    {
        $this->events[] = ['at' => (new \DateTimeImmutable())->format(DATE_ATOM), 'message' => $message, 'level' => $level, 'parameters' => $parameters];
    }
    public function isActive(): bool { return in_array($this->status, ['queued', 'running'], true); }
}
