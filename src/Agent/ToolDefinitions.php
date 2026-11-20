<?php

namespace App\Agent;

final class ToolDefinitions
{
    public static function all(): array
    {
        $string = ['type' => 'string']; $integer = ['type' => 'integer'];
        $strings = ['type' => 'array', 'items' => $string];
        return [
            self::tool('get_my_profile', 'Read canonical CV facts and search preferences of the current run owner.', []),
            self::tool('search_jobs', 'Search one approved API/RSS source. Page 0 is first; max page 2. RSS only page 0. Unknown country/work eligibility must be reported.',
                ['source' => ['type' => 'string', 'enum' => ['solidjobs', 'dou', 'djinni']], 'keywords' => $string, 'page' => $integer]),
            self::tool('get_job_details', 'Read the complete stored API/feed description of a job discovered in this run. Read before saving a match.', ['job_id' => $integer]),
            self::tool('save_job_match', 'Save relevance score 0-100 with honest reasons and missing skills/red flags. Never claim the candidate has absent skills. Does not submit applications.',
                ['job_id' => $integer, 'score' => $integer, 'reasons' => $strings, 'gaps' => $strings]),
            self::tool('prepare_cv', 'Prepare CV by selecting and reordering existing canonical fact IDs verbatim. Only full-description jobs above the configured score threshold. No invented or rewritten facts.',
                ['job_id' => $integer, 'fact_ids' => $strings]),
        ];
    }

    private static function tool(string $name, string $description, array $properties): array
    {
        return ['type' => 'function', 'name' => $name, 'description' => $description, 'strict' => true,
            'parameters' => ['type' => 'object', 'properties' => $properties ?: new \stdClass(),
                'required' => array_keys($properties), 'additionalProperties' => false]];
    }
}
