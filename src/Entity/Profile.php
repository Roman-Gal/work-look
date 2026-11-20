<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class Profile
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\OneToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $user;

    #[ORM\Column(length: 120), Assert\NotBlank, Assert\Length(max: 120)]
    public string $displayName = '';

    #[ORM\Column(length: 200), Assert\Length(max: 200)]
    public string $headline = '';

    #[ORM\Column(length: 300), Assert\Length(max: 300)]
    public string $contactLine = '';

    #[ORM\Column(type: 'text'), Assert\NotBlank, Assert\Length(max: 16000)]
    public string $factsText = '';

    #[ORM\Column(type: 'text'), Assert\Length(max: 3000)]
    public string $preferences = '';

    #[ORM\Column(length: 120), Assert\Length(max: 120)]
    public string $searchTerms = '';

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(User $user) { $this->user = $user; $this->updatedAt = new \DateTimeImmutable(); }

    public function snapshot(): array
    {
        $facts = [];
        foreach (preg_split('/\R/u', $this->factsText) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $facts[] = ['id' => 'fact_'.substr(hash('sha256', $line), 0, 16), 'text' => $line];
        }
        return [
            'display_name' => $this->displayName, 'headline' => $this->headline,
            'contact_line' => $this->contactLine, 'facts' => array_values(array_column($facts, null, 'id')),
            'preferences' => $this->preferences, 'search_terms' => $this->searchTerms,
            'version' => hash('sha256', json_encode([$this->displayName, $this->headline, $this->contactLine, $facts, $this->preferences, $this->searchTerms], JSON_THROW_ON_ERROR)),
        ];
    }
}
