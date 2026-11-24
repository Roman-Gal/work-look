<?php

namespace App\Agent;

use App\Entity\{AgentRun, Job, JobMatch};
use App\Service\CanonicalCv;
use App\Source\JobCatalog;
use Doctrine\ORM\EntityManagerInterface;

final class ToolExecutor
{
    public function __construct(private JobCatalog $catalog, private EntityManagerInterface $em, private CanonicalCv $cv) {}

    public function execute(AgentRun $run, string $name, array $args, array &$state): array
    {
        return match ($name) {
            'get_my_profile' => ['facts' => $run->profileSnapshot['facts'], 'headline' => $run->profileSnapshot['headline'],
                'preferences' => $run->profileSnapshot['preferences'], 'sources' => $run->sources, 'minimum_score' => $run->minimumScore],
            'get_search_knowledge' => $this->knowledge($run, $args),
            'search_jobs' => $this->search($run, $args, $state),
            'get_job_details' => $this->inspect($run, $args, $state),
            'save_job_match' => $this->saveMatch($run, $args, $state),
            'prepare_cv' => $this->prepareCv($run, $args, $state),
            default => throw new \InvalidArgumentException('Невідомий інструмент агента.'),
        };
    }

    private function knowledge(AgentRun $run, array $args): array
    {
        $query = $args['query'] ?? null; $source = $args['source'] ?? null;
        if (!is_string($query) || mb_strlen($query) > 180 || !is_string($source) || !in_array($source, ['general', ...$run->sources], true)) { throw new \InvalidArgumentException('Недозволені параметри бази знань.'); }
        $entries = (new \App\Service\KnowledgeBase($this->em))->search($run->user, $query, $source === 'general' ? ['general', ...$run->sources] : ['general', $source]);
        $run->addEvent('event.knowledge', parameters: ['%count%' => count($entries)]);
        return ['entries' => $entries, 'candidate_facts' => false, 'live_source_access' => false];
    }

    private function search(AgentRun $run, array $args, array &$state): array
    {
        $source = $args['source'] ?? ''; $keywords = $args['keywords'] ?? ''; $page = $args['page'] ?? null;
        if (!in_array($source, $run->sources, true) || !is_string($keywords) || !is_int($page)) { throw new \InvalidArgumentException('Недозволені параметри пошуку.'); }
        if (($state['search_count'] ?? 0) >= 4) { return ['error' => 'Ліміт: максимум 4 пошукові запити за запуск. Оцініть уже знайдені вакансії.']; }
        ++$state['search_count'];
        $data = $this->catalog->search($source, $keywords, $page);
        $ids = array_map(fn (Job $job) => $job->id, $data['jobs']);
        $hash = hash('sha256', json_encode($ids));
        if (isset($state['page_hashes'][$hash]) && $ids !== []) { return ['error' => 'Джерело повторило сторінку; припиніть цю пагінацію.']; }
        $state['page_hashes'][$hash] = true;
        foreach ($ids as $id) { $state['discovered'][$id] = true; }
        $run->addEvent('event.search', parameters: ['%source%' => $source, '%count%' => count($ids), '%query%' => mb_substr($keywords, 0, 120)]);
        return ['jobs' => array_map(fn (Job $job) => $job->payload(), $data['jobs']), 'next_page' => $data['next_page'],
            'coverage' => $data['coverage'], 'remaining_searches' => 4 - $state['search_count']];
    }

    private function ownedJob(array $args, array $state): Job
    {
        $id = $args['job_id'] ?? null;
        if (!is_int($id) || !isset($state['discovered'][$id])) { throw new \InvalidArgumentException('Вакансія не була знайдена в цьому запуску.'); }
        return $this->em->find(Job::class, $id) ?? throw new \InvalidArgumentException('Вакансія не знайдена.');
    }

    private function inspect(AgentRun $run, array $args, array &$state): array
    {
        $job = $this->ownedJob($args, $state); $state['inspected'][$job->id] = true;
        $run->addEvent('event.inspect', parameters: ['%title%' => $job->title]);
        return $job->payload(true);
    }

    private function saveMatch(AgentRun $run, array $args, array $state): array
    {
        $job = $this->ownedJob($args, $state);
        if (!isset($state['inspected'][$job->id])) { throw new \InvalidArgumentException('Спочатку прочитайте опис вакансії.'); }
        if ($job->isExpired()) { throw new \InvalidArgumentException('Термін вакансії завершився.'); }
        $score = $args['score'] ?? null;
        if (!is_int($score) || $score < 0 || $score > 100) { throw new \InvalidArgumentException('Оцінка має бути від 0 до 100.'); }
        $match = $this->em->getRepository(JobMatch::class)->findOneBy(['run' => $run, 'job' => $job]) ?? new JobMatch($run, $job);
        $match->score = $score; $match->reasons = $this->strings($args['reasons'] ?? null); $match->gaps = $this->strings($args['gaps'] ?? null);
        $this->em->persist($match); $this->em->flush();
        $run->addEvent('event.score', parameters: ['%score%' => $score, '%title%' => $job->title]);
        return ['saved' => true, 'match_id' => $match->id, 'cv_eligible' => $score >= $run->minimumScore && $job->completeness === 'full'];
    }

    private function prepareCv(AgentRun $run, array $args, array $state): array
    {
        $job = $this->ownedJob($args, $state);
        $match = $this->em->getRepository(JobMatch::class)->findOneBy(['run' => $run, 'job' => $job]);
        if (!$match || $match->score < $run->minimumScore || $job->completeness !== 'full' || $job->isExpired()) {
            throw new \InvalidArgumentException('CV доступне лише після оцінки вище порогу та за наявності повного опису.');
        }
        $ids = $args['fact_ids'] ?? [];
        if (!is_array($ids)) { throw new \InvalidArgumentException('Некоректний список фактів.'); }
        $facts = $this->cv->selectFacts($run->profileSnapshot, $ids);
        $match->selectedFactIds = $ids; $this->em->flush();
        $run->addEvent('event.cv', parameters: ['%count%' => count($facts)]);
        return ['prepared' => true, 'fact_ids' => $ids, 'changelog' => 'Відібрано й упорядковано факти канонічного CV. Нових тверджень немає.'];
    }

    private function strings(mixed $items): array
    {
        if (!is_array($items) || count($items) > 8) { throw new \InvalidArgumentException('Забагато пунктів пояснення.'); }
        foreach ($items as $item) { if (!is_string($item) || mb_strlen($item) > 1000) { throw new \InvalidArgumentException('Некоректне пояснення.'); } }
        return array_values($items);
    }
}
