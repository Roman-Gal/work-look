<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'match_run_job', columns: ['run_id', 'job_id'])]
class JobMatch
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public AgentRun $run;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    public Job $job;
    #[ORM\Column]
    public int $score = 0;
    #[ORM\Column(type: 'json')]
    public array $reasons = [];
    #[ORM\Column(type: 'json')]
    public array $gaps = [];
    #[ORM\Column(length: 16)]
    public string $status = 'New';
    #[ORM\Column(type: 'json')]
    public array $selectedFactIds = [];
    #[ORM\Column(type: 'json')]
    public array $jobSnapshot = [];
    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct(AgentRun $run, Job $job)
    {
        $this->run = $run; $this->job = $job; $this->jobSnapshot = $job->payload(true);
        $this->createdAt = new \DateTimeImmutable();
    }
}
