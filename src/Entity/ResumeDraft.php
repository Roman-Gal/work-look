<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'UNIQ_RESUME_USER', columns: ['user_id'])]
class ResumeDraft
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\OneToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $user;
    #[ORM\Column(length: 180)]
    public string $filename = '';
    #[ORM\Column(type: 'text')]
    public string $text = '';
    #[ORM\Column(type: 'blob', nullable: true)]
    public mixed $pdfData = null;
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $privacyAcknowledgedAt = null;
    #[ORM\Column(length: 24, nullable: true)]
    public ?string $privacyVersion = null;
    #[ORM\Column]
    public int $pages = 0;
    #[ORM\Column(length: 64)]
    public string $contentHash = '';
    #[ORM\Column(type: 'text')]
    public string $requestText = '';
    #[ORM\Column(type: 'text')]
    public string $preferences = '';
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $preparedProfile = null;
    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;
    public function __construct(User $user) { $this->user = $user; $this->updatedAt = new \DateTimeImmutable(); }
    public function pdfBytes(): ?string
    {
        if (is_resource($this->pdfData)) { rewind($this->pdfData); return stream_get_contents($this->pdfData); }
        return is_string($this->pdfData) ? $this->pdfData : null;
    }
}
