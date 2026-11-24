<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\ResumeDraft;

final class ResumeProfilePreparation
{
    /** The model may select source text; user confirmation still commits the canonical profile. */
    public function validate(ResumeDraft $draft, string $json): array
    {
        if (strlen($json) > 32000) { throw new \InvalidArgumentException('resume.json_invalid'); }
        try { $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new \InvalidArgumentException('resume.json_invalid'); }
        if (!is_array($data) || array_diff(array_keys($data), ['display_name', 'headline', 'contact_line', 'facts']) !== [] || !is_array($data['facts'] ?? null) || !array_is_list($data['facts']) || count($data['facts']) < 1 || count($data['facts']) > 30) { throw new \InvalidArgumentException('resume.json_invalid'); }
        $source = $this->normalize($draft->text); $prepared = [];
        foreach (['display_name' => 120, 'headline' => 200, 'contact_line' => 300] as $key => $limit) {
            if (!is_string($data[$key] ?? null)) { throw new \InvalidArgumentException('resume.json_invalid'); }
            $value = $this->normalize($data[$key]);
            if (mb_strlen($value) > $limit || ($value !== '' && !str_contains($source, $value))) { throw new \InvalidArgumentException('resume.unsupported_fact'); }
            $prepared[$key] = $value;
        }
        if ($prepared['display_name'] === '') { throw new \InvalidArgumentException('resume.json_invalid'); }
        $facts = [];
        foreach ($data['facts'] as $fact) {
            if (!is_string($fact) || ($value = $this->normalize($fact)) === '' || !str_contains($source, $value)) { throw new \InvalidArgumentException('resume.unsupported_fact'); }
            $facts[] = $value;
        }
        $prepared['facts_text'] = implode("\n", array_unique($facts));
        if (mb_strlen($prepared['facts_text']) > 16000) { throw new \InvalidArgumentException('resume.json_invalid'); }
        return $prepared;
    }
    private function normalize(string $value): string { return trim(preg_replace('/\s+/u', ' ', $value) ?? ''); }
}
