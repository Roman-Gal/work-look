<?php
namespace App\Tests;

use App\Entity\{Profile, User};
use App\Service\CanonicalCv;
use PHPUnit\Framework\TestCase;

final class CanonicalCvTest extends TestCase
{
    public function testDraftCopiesOnlySnapshotFactsAndPreservesOrder(): void
    {
        $profile = new Profile(new User());
        $profile->factsText = "PHP: 3 роки практики.\nАнглійська: B2.";
        $snapshot = $profile->snapshot(); $facts = $snapshot['facts'];
        $profile->factsText = 'Зовсім інший профіль після запуску.';
        $selected = (new CanonicalCv())->selectFacts($snapshot, [$facts[1]['id'], $facts[0]['id']]);
        self::assertSame(['Англійська: B2.', 'PHP: 3 роки практики.'], array_column($selected, 'text'));
    }
    public function testInventedFactIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CanonicalCv())->selectFacts(['facts' => [['id' => 'confirmed', 'text' => 'PHP']]], ['invented_certificate']);
    }
    public function testDuplicateFactIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CanonicalCv())->selectFacts(['facts' => [['id' => 'confirmed', 'text' => 'PHP']]], ['confirmed', 'confirmed']);
    }
}
