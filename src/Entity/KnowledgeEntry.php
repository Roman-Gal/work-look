<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity]
#[ORM\Index(name: 'knowledge_scope_idx', columns: ['owner_id', 'status', 'site'])]
#[ORM\Index(name: 'IDX_KNOWLEDGE_OWNER', columns: ['owner_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_KNOWLEDGE_SEED', columns: ['seed_key'])]
class KnowledgeEntry
{
    public const SITES = ['general', 'solidjobs', 'dou', 'djinni', 'justjoinit', 'nofluffjobs', 'pracuj', 'workua', 'other'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $seedKey = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?User $owner = null;
    #[ORM\Column(length: 180), Assert\NotBlank, Assert\Length(max: 180)]
    public string $title = '';
    #[ORM\Column(type: 'text'), Assert\NotBlank, Assert\Length(max: 6000)]
    public string $content = '';
    #[ORM\Column(length: 24), Assert\Choice(choices: ['site_tip', 'search_prompt'])]
    public string $kind = 'site_tip';
    #[ORM\Column(length: 24), Assert\Choice(choices: self::SITES)]
    public string $site = 'general';
    #[ORM\Column(length: 2), Assert\Choice(choices: ['uk', 'pl', 'en'])]
    public string $locale = 'uk';
    #[ORM\Column(length: 16), Assert\Choice(choices: ['draft', 'published', 'archived'])]
    public string $status = 'draft';
    #[ORM\Column(length: 1000), Assert\Length(max: 1000), Assert\Url(protocols: ['https'], requireTld: true)]
    public string $sourceUrl = '';
    #[ORM\Column(type: 'text'), Assert\Length(max: 1000)]
    public string $sourceNotes = '';
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $reviewedAt = null;
    #[ORM\Version, ORM\Column(type: 'integer')]
    public int $revision = 1;
    #[ORM\Column]
    public \DateTimeImmutable $createdAt;
    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;
    public function __construct() { $this->createdAt = $this->updatedAt = new \DateTimeImmutable(); }
    #[Assert\Callback]
    public function publication(ExecutionContextInterface $context): void
    {
        if ($this->status !== 'published') { return; }
        if ($this->owner !== null || !$this->reviewedAt || $this->reviewedAt->format('Y-m-d') > (new \DateTimeImmutable())->format('Y-m-d') || ($this->sourceUrl === '' && trim($this->sourceNotes) === '')) {
            $context->buildViolation('kb.publish_validation')->atPath('status')->addViolation();
        }
    }
}
