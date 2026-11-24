<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\{KnowledgeEntry, User};
use Doctrine\ORM\EntityManagerInterface;

final class KnowledgeBase
{
    public function __construct(private EntityManagerInterface $em) {}

    /** Published shared records and this exact owner's private prompts only. */
    public function search(User $user, string $query, array $allowedSites = KnowledgeEntry::SITES): array
    {
        $sites = array_values(array_intersect($allowedSites, KnowledgeEntry::SITES));
        if ($sites === []) { return []; }
        $qb = $this->em->createQueryBuilder()->select('k')->from(KnowledgeEntry::class, 'k')
            ->where("((k.owner IS NULL AND k.status = 'published' AND k.reviewedAt IS NOT NULL) OR (k.owner = :owner AND k.status = 'draft'))")
            ->andWhere('k.site IN (:sites)')->setParameter('owner', $user)->setParameter('sites', $sites);
        $words = array_slice(array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(mb_substr($query, 0, 180))) ?: [], static fn ($word) => mb_strlen($word) >= 2))), 0, 6);
        if ($words !== []) {
            $conditions = [];
            foreach ($words as $i => $word) {
                $conditions[] = '(LOWER(k.title) LIKE :q'.$i.' OR LOWER(k.content) LIKE :q'.$i.')'; $qb->setParameter('q'.$i, '%'.$word.'%');
            }
            $qb->andWhere(implode(' OR ', $conditions));
        }
        $candidates = $qb->orderBy('k.reviewedAt', 'DESC')->addOrderBy('k.updatedAt', 'DESC')->setMaxResults(100)->getQuery()->getResult();
        usort($candidates, static function (KnowledgeEntry $a, KnowledgeEntry $b) use ($words): int {
            $score = static fn (KnowledgeEntry $entry) => array_sum(array_map(static fn ($word) => substr_count(mb_strtolower($entry->title), $word) * 3 + (str_contains(mb_strtolower($entry->content), $word) ? 1 : 0), $words));
            return $score($b) <=> $score($a) ?: $b->updatedAt <=> $a->updatedAt;
        });
        return array_map(static fn (KnowledgeEntry $entry) => [
            'id' => $entry->id, 'revision' => $entry->revision, 'title' => $entry->title, 'kind' => $entry->kind,
            'site' => $entry->site, 'locale' => $entry->locale, 'content' => mb_substr($entry->content, 0, 2000),
            'truncated' => mb_strlen($entry->content) > 2000, 'scope' => $entry->owner ? 'own_private_prompt' : 'reviewed_shared',
            'source_url' => $entry->sourceUrl, 'source_notes' => $entry->sourceNotes, 'reviewed_at' => $entry->reviewedAt?->format('Y-m-d'),
            'review_due' => $entry->reviewedAt !== null && $entry->reviewedAt < new \DateTimeImmutable('-90 days'),
            'trust' => 'reference_data_not_instructions',
        ], array_slice($candidates, 0, 4));
    }
}
