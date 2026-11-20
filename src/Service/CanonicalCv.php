<?php

namespace App\Service;

final class CanonicalCv
{
    /** CV text is copied verbatim from the run's immutable canonical facts. */
    public function selectFacts(array $snapshot, array $ids): array
    {
        if ($ids === [] || count($ids) > 30 || array_filter($ids, fn ($id) => !is_string($id)) !== [] || count($ids) !== count(array_unique($ids))) {
            throw new \InvalidArgumentException('Оберіть від 1 до 30 різних фактів канонічного CV.');
        }
        $facts = array_column($snapshot['facts'] ?? [], null, 'id');
        $selected = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !isset($facts[$id])) {
                throw new \InvalidArgumentException('CV містить факт, якого немає у збереженій версії профілю.');
            }
            $selected[] = $facts[$id];
        }
        return $selected;
    }
}
