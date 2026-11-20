<?php
namespace App\Service;

use App\Entity\{AgentRun, Profile, User};
use App\Message\StartAgentRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class SearchLauncher
{
    public function __construct(private EntityManagerInterface $em, private MessageBusInterface $bus, private SearchAllowance $allowance) {}

    public function start(User $user, Profile $profile, array $data, string $locale): ?AgentRun
    {
        if ($profile->user->id !== $user->id || $profile->snapshot()['facts'] === []) { throw new \InvalidArgumentException('A canonical profile is required.'); }
        $run = new AgentRun($user); $run->mode = 'live'; $run->requestText = $data['requestText'] ?? '';
        $run->profileSnapshot = $profile->snapshot(); $run->profileSnapshot['ui_locale'] = $locale;
        $run->sources = $data['sources']; $run->minimumScore = $data['minimumScore']; $run->addEvent('event.queued');
        try {
            $started = $this->em->getConnection()->transactional(function () use ($user, $run): bool {
                if (!$this->allowance->reserve($user, $run)) { return false; }
                $this->em->persist($run); $this->em->flush();
                // Doctrine Messenger uses the same connection: debit, run and queue commit together.
                $this->bus->dispatch(new StartAgentRun($run->id, $user->id));
                return true;
            });
        } catch (\Throwable $error) {
            if ($this->em->isOpen()) {
                if ($this->em->contains($run)) { $this->em->detach($run); }
                $this->em->refresh($user);
            }
            throw $error;
        }
        if (!$started) { return null; }
        $this->em->refresh($user);
        return $run;
    }
}
