<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'job_source_identity', columns: ['source', 'external_id'])]
class Job
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\Column(length: 24)]
    public string $source = '';
    #[ORM\Column(length: 512)]
    public string $externalId = '';
    #[ORM\Column(length: 400)]
    public string $title = '';
    #[ORM\Column(length: 250)]
    public string $company = '';
    #[ORM\Column(length: 1500)]
    public string $url = '';
    #[ORM\Column(type: 'text')]
    public string $description = '';
    #[ORM\Column(type: 'json')]
    public array $locations = [];
    #[ORM\Column(type: 'json')]
    public array $salaries = [];
    #[ORM\Column(type: 'json')]
    public array $skills = [];
    #[ORM\Column(length: 20)]
    public string $workMode = 'unknown';
    #[ORM\Column(length: 20)]
    public string $completeness = 'unknown';
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $expiresAt = null;
    #[ORM\Column]
    public \DateTimeImmutable $lastSeenAt;
    #[ORM\Column(length: 64)]
    public string $contentHash = '';

    public function __construct() { $this->lastSeenAt = new \DateTimeImmutable(); }
    public function isExpired(): bool { return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable(); }

    public function payload(bool $details = false): array
    {
        return ['job_id' => $this->id, 'source' => $this->source, 'title' => $this->title, 'company' => $this->company,
            'url' => $this->url, 'locations' => $this->locations, 'work_mode' => $this->workMode,
            'salaries' => $this->salaries, 'skills' => $this->skills, 'content_completeness' => $this->completeness,
            'expired' => $this->isExpired(), 'description' => mb_substr($this->description, 0, $details ? 10000 : 800)];
    }
}
