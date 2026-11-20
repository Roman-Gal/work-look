<?php
namespace App\Service;

use App\Entity\{AgentRun, User};
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/** One complimentary search per account; the reserved run ID is the durable debit ledger. */
final class SearchAllowance
{
    public function __construct(private EntityManagerInterface $em) {}

    public function remaining(User $user): int
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT free_search_run_id FROM app_user WHERE id = ?', [$user->id]);
        return $row && $row['free_search_run_id'] === null ? 1 : 0;
    }

    public function reserve(User $user, AgentRun $run): bool
    {
        return $this->em->getConnection()->executeStatement(
            'UPDATE app_user SET free_search_run_id = ? WHERE id = ? AND free_search_run_id IS NULL AND is_verified = ? AND live_ai_enabled = ?',
            [$run->id, $user->id, true, true], [ParameterType::STRING, ParameterType::INTEGER, ParameterType::BOOLEAN, ParameterType::BOOLEAN]
        ) === 1;
    }

    public function permits(AgentRun $run): bool
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM app_user WHERE id = ? AND free_search_run_id = ? AND is_verified = ? AND live_ai_enabled = ?',
            [$run->user->id, $run->id, true, true], [ParameterType::INTEGER, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::BOOLEAN]
        ) === 1;
    }

    public function releaseUnused(AgentRun $run): void
    {
        // Once a model request was attempted, never silently refund paid API usage.
        if ($run->modelCalls !== 0 || !in_array($run->status, ['failed', 'cancelled'], true)) { return; }
        $changed = $this->em->getConnection()->executeStatement(
            'UPDATE app_user SET free_search_run_id = NULL WHERE id = ? AND free_search_run_id = ?', [$run->user->id, $run->id]
        );
        if ($changed && $this->em->contains($run->user)) { $this->em->refresh($run->user); }
    }
}
