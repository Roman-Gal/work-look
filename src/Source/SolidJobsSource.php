<?php

namespace App\Source;

final class SolidJobsSource
{
    public function __construct(private SourceHttpClient $http) {}

    public function search(string $keywords, int $page): array
    {
        $data = json_decode($this->http->get('https://solid.jobs/public-api/offers/IT', [
            'campaign' => 'work-look-personal', 'pageIndex' => $page, 'pageSize' => 10,
            'search.searchTerm' => $keywords, 'sortActive' => 'validFrom', 'sortDirection' => 'desc',
        ]), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data['jobs'] ?? null) || !is_int($data['pageIndex'] ?? null) || $data['pageIndex'] !== $page) {
            throw new \RuntimeException('Схема SOLID.Jobs або пагінація змінилися.');
        }
        $jobs = [];
        foreach (array_slice($data['jobs'], 0, 10) as $row) {
            if (!is_string($row['jobOfferKey'] ?? null) || !is_string($row['title'] ?? null) || !is_string($row['url'] ?? null)) {
                throw new \RuntimeException('У вакансії SOLID.Jobs відсутні обов’язкові поля.');
            }
            $salary = [];
            foreach (['salary', 'secondarySalary'] as $key) {
                if (is_array($row[$key] ?? null)) {
                    $s = $row[$key];
                    $salary[] = ['min' => $s['from'] ?? null, 'max' => $s['to'] ?? null, 'currency' => $s['currency'] ?? null,
                        'period' => $s['period'] ?? null, 'contract_type' => $s['employmentType'] ?? null, 'gross_net' => null, 'source_path' => $key];
                }
            }
            $description = SourceHttpClient::plain((string) ($row['description'] ?? ''));
            $jobs[] = [
                'source' => 'solidjobs', 'external_id' => $row['jobOfferKey'], 'title' => $row['title'],
                'company' => (string) ($row['company'] ?? ''), 'url' => SourceHttpClient::safeUrl($row['url'], 'solid.jobs'),
                'description' => $description, 'locations' => array_values(array_filter($row['locations'] ?? [], 'is_string')),
                'salaries' => $salary, 'skills' => array_values(array_map(fn (array $s) => (string) ($s['name'] ?? ''), $row['skills'] ?? [])),
                'work_mode' => ($row['isHybrid'] ?? false) ? 'hybrid' : (($row['isRemote'] ?? false) ? 'remote' : 'unknown'),
                'completeness' => mb_strlen($description) >= 300 ? 'full' : 'summary', 'expires_at' => $row['validTo'] ?? null,
            ];
        }
        return ['jobs' => $jobs, 'next_page' => $page + 1 < (int) ($data['totalPages'] ?? 0) ? $page + 1 : null, 'coverage' => 'partial'];
    }
}
