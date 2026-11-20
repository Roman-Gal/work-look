<?php

namespace App\Agent;

interface ModelClientInterface
{
    public function respond(array $input, array $tools, int $maxOutputTokens): array;
}
