<?php

namespace App\Source;

use App\Entity\Job;
use Doctrine\ORM\EntityManagerInterface;

final class JobCatalog
{
    public function __construct(private SolidJobsSource $solid, private RssJobsSource $rss, private EntityManagerInterface $em) {}

    public function search(string $source, string $keywords, int $page, bool $demo = false): array
    {
        if ($page < 0 || $page > 2 || mb_strlen($keywords) > 120) { throw new \InvalidArgumentException('Параметри пошуку перевищують дозволені межі.'); }
        if ($demo) {
            $data = ['jobs' => [[
                'source' => 'demo', 'external_id' => 'sample-php', 'title' => 'demo.title',
                'company' => 'demo.company', 'url' => 'https://example.com/demo-job',
                'description' => 'demo.description',
                'locations' => ['demo.location'], 'salaries' => [], 'skills' => ['PHP', 'Symfony'],
                'work_mode' => 'remote', 'completeness' => 'full', 'expires_at' => null,
            ]], 'next_page' => null, 'coverage' => 'demo'];
        } else {
            $data = match ($source) {
                'solidjobs' => $this->solid->search($keywords, $page),
                'dou', 'djinni' => $this->rss->search($source, $keywords, $page),
                default => throw new \InvalidArgumentException('Джерело не дозволене.'),
            };
        }
        $saved = [];
        foreach ($data['jobs'] as $row) {
            $job = $this->em->getRepository(Job::class)->findOneBy(['source' => $row['source'], 'externalId' => $row['external_id']]) ?? new Job();
            $job->source = $row['source']; $job->externalId = mb_substr($row['external_id'], 0, 512);
            $job->title = mb_substr($row['title'], 0, 400); $job->company = mb_substr($row['company'], 0, 250);
            $job->url = mb_substr($row['url'], 0, 1500); $job->description = $row['description'];
            $job->locations = $row['locations']; $job->salaries = $row['salaries']; $job->skills = $row['skills'];
            $job->workMode = $row['work_mode']; $job->completeness = $row['completeness'];
            try { $job->expiresAt = $row['expires_at'] ? new \DateTimeImmutable($row['expires_at']) : null; }
            catch (\Exception) { $job->expiresAt = null; }
            $job->lastSeenAt = new \DateTimeImmutable(); $job->contentHash = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
            $this->em->persist($job); $saved[] = $job;
        }
        $this->em->flush();
        return ['jobs' => $saved, 'next_page' => $data['next_page'], 'coverage' => $data['coverage']];
    }
}
