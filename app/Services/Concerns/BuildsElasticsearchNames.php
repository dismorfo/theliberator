<?php

namespace App\Services\Concerns;

trait BuildsElasticsearchNames
{
    protected function buildIndexName(string $suffix): string
    {
        $prefix = (string) config('services.elasticsearch.index_prefix', '');
        $siteKey = (string) config('services.elasticsearch.site_key', 'site');

        $normalizedPrefix = $this->normalizeIndexComponent($prefix);
        $normalizedSiteKey = $this->normalizeIndexComponent($siteKey);

        return trim("{$normalizedPrefix}{$normalizedSiteKey}{$suffix}", '_');
    }

    protected function normalizeIndexComponent(string $value): string
    {
        $normalized = mb_strtolower($value);
        $normalized = preg_replace('/[^a-z0-9_-]+/', '_', $normalized) ?? $normalized;
        $normalized = trim($normalized, '_-');

        if ($normalized === '') {
            return '';
        }

        return $normalized.'_';
    }
}
