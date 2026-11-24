<?php
declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'activity_time_idx', columns: ['recorded_at'])]
#[ORM\Index(name: 'activity_actor_idx', columns: ['actor_id', 'recorded_at'])]
#[ORM\Index(name: 'activity_kind_time_idx', columns: ['kind', 'recorded_at'])]
class ActivityEvent
{
    #[ORM\Id, ORM\Column(length: 32)]
    public string $id;
    #[ORM\Column]
    public \DateTimeImmutable $recordedAt;
    #[ORM\Column(length: 32)]
    public string $kind;
    #[ORM\Column(length: 32)]
    public string $requestId;
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $actorId = null;
    #[ORM\Column(length: 180, nullable: true)]
    public ?string $actorName = null;
    #[ORM\Column(length: 45, nullable: true)]
    public ?string $ip = null;
    #[ORM\Column(length: 2, nullable: true)]
    public ?string $country = null;
    #[ORM\Column(length: 120, nullable: true)]
    public ?string $city = null;
    #[ORM\Column(length: 80)]
    public string $routeName = '';
    #[ORM\Column(length: 255)]
    public string $path = '';
    #[ORM\Column(length: 8)]
    public string $method = '';
    #[ORM\Column]
    public int $statusCode = 0;
    #[ORM\Column]
    public int $durationMs = 0;
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $referrerHost = null;
    #[ORM\Column(type: 'json')]
    public array $context = [];
}
