<?php

namespace App\Message;

final readonly class StartAgentRun
{
    public function __construct(public string $runId, public int $userId) {}
}
