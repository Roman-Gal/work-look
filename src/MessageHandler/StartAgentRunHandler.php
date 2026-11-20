<?php

namespace App\MessageHandler;

use App\Agent\AgentRunner;
use App\Entity\AgentRun;
use App\Message\StartAgentRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StartAgentRunHandler
{
    public function __construct(private EntityManagerInterface $em, private AgentRunner $runner) {}
    public function __invoke(StartAgentRun $message): void
    {
        $run = $this->em->find(AgentRun::class, $message->runId);
        if (!$run || $run->user->id !== $message->userId) { throw new \RuntimeException('Невідповідність власника задачі.'); }
        $this->runner->run($run);
    }
}
