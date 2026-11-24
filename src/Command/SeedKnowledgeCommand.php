<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\KnowledgeEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:knowledge:seed', description: 'Add dated source notes and editorial search templates; preserve existing edits.')]
final class SeedKnowledgeCommand extends Command
{
    public function __construct(private EntityManagerInterface $em) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $records = [
            ['solid-query', 'solidjobs', 'site_tip', 'SOLID.Jobs: compare focused search phrases',
                'Try a focused technology or role phrase, such as PHP, then compare a second wording instead of assuming one query covers every relevant title. WorkLook currently searches the IT offer feed. Review currency, pay period and contract type together; do not compare B2B and employment figures as equivalent.',
                'https://solid.jobs/public-api/offers/IT', 'Source coverage reviewed on 2026-11-20. Search-phrase comparison is editorial guidance; recheck when platform behaviour changes.', '2026-11-20'],
            ['dou-window', 'dou', 'site_tip', 'DOU: RSS is a recent window, not the full catalog',
                'A search such as PHP returns a recent RSS vacancy window. No matching result in that feed does not prove that the website has no relevant vacancy. Check the original offer before making an application decision. Location, salary, company and work eligibility may be unknown in the imported feed data.',
                'https://jobs.dou.ua/vacancies/feeds/', 'Source coverage reviewed on 2026-11-20. Only the recent feed window is covered; website filters may offer a broader search.', '2026-11-20'],
            ['djinni-window', 'djinni', 'site_tip', 'Djinni: verify details outside the RSS summary',
                'Use focused keywords, for example PHP, and inspect the original vacancy when a summary looks promising. A short RSS description may omit essential requirements. WorkLook treats these records as summaries and does not prepare a tailored CV from them. Remote wording alone does not confirm eligibility to work from Poland.',
                'https://djinni.co/jobs/rss/', 'Source coverage reviewed on 2026-11-20. Check the original vacancy for complete requirements.', '2026-11-20'],
            ['request-template', 'general', 'search_prompt', 'A concrete job-search request',
                'Template: Find [role or technology, e.g. PHP] roles in [locations] with [work mode]. Must have: [hard conditions]. Nice to have: [preferences]. Acceptable contracts: [types]. Salary expectation: [amount, currency, pay period and gross/net if known]. Exclude: [conditions]. Explain any missing information and ask before relaxing a hard requirement.',
                '', 'Editorial WorkLook search-request template. Replace brackets with the user\'s own requirements; placeholders are not candidate facts.', '2026-11-24'],
            ['compare-results', 'general', 'site_tip', 'Compare relevance and coverage, not only the score',
                'Review why a vacancy matches, which requirements are missing, whether the description is complete, and which sources or query variants were actually checked. A high score is an explanation aid, not a hiring probability. Keep unknown information explicit and verify the original vacancy before applying.',
                '', 'Editorial WorkLook review guidance, consistent with the bounded search and immutable-fact CV design.', '2026-11-24'],
        ];
        $added = 0;
        foreach ($records as [$key, $site, $kind, $title, $content, $url, $notes, $date]) {
            if ($this->em->getRepository(KnowledgeEntry::class)->findOneBy(['seedKey' => 'initial-'.$key])) { continue; }
            $entry = new KnowledgeEntry(); $entry->seedKey = 'initial-'.$key; $entry->site = $site; $entry->kind = $kind;
            $entry->title = $title; $entry->content = $content; $entry->sourceUrl = $url; $entry->sourceNotes = $notes;
            $entry->reviewedAt = new \DateTimeImmutable($date); $entry->locale = 'en'; $entry->status = 'published';
            $this->em->persist($entry); ++$added;
        }
        $this->em->flush(); $output->writeln(sprintf('Added %d reviewed knowledge records; existing records were preserved.', $added));
        return Command::SUCCESS;
    }
}
