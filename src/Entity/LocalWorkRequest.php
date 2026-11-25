<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'local_work_owner_created', columns: ['user_id', 'created_at'])]
#[ORM\UniqueConstraint(name: 'UNIQ_LOCAL_WORK_ACTIVE', columns: ['active_user_key'])]
class LocalWorkRequest
{
    #[ORM\Id, ORM\Column(length: 32)]
    public string $id;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $user;
    #[ORM\Column(nullable: true)]
    public ?int $activeUserKey = null;
    #[ORM\Column(length: 16)]
    public string $status = 'queued';
    #[ORM\Column(length: 2)]
    public string $locale = 'uk';
    #[ORM\Column(type: 'json')]
    public array $input = [];
    #[ORM\Column(type: 'blob')]
    public mixed $pdfData;
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $result = null;
    #[ORM\Column]
    public \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $completedAt = null;
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $notificationSentAt = null;
    public function __construct(User $user)
    {
        $this->id = bin2hex(random_bytes(16)); $this->user = $user;
        $this->activeUserKey = $user->id; $this->createdAt = new \DateTimeImmutable();
    }
    public function pdfBytes(): string
    {
        if (is_resource($this->pdfData)) { rewind($this->pdfData); return stream_get_contents($this->pdfData); }
        return $this->pdfData;
    }
}
