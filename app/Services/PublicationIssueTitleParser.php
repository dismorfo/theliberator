<?php

namespace App\Services;

class PublicationIssueTitleParser
{
    public function parse(string $title): PublicationIssueMetadata
    {
        $displayTitle = trim($title);
        $dateString = $this->extractDateString($displayTitle);

        return new PublicationIssueMetadata(
            displayTitle: $displayTitle,
            dateString: $dateString,
            releaseDate: $this->releaseDateFromDateString($dateString),
        );
    }

    public function extractDateString(string $title): string
    {
        return trim((string) preg_replace('/^(The Liberator|The Masses),\s*/', '', $title));
    }

    public function releaseDateFromTitle(string $title): string
    {
        return $this->releaseDateFromDateString($this->extractDateString($title));
    }

    private function releaseDateFromDateString(string $dateString): string
    {
        $publishedAt = \DateTimeImmutable::createFromFormat('!F Y', $dateString);

        if ($publishedAt === false) {
            return '';
        }

        return $publishedAt->format('Y-m-d');
    }
}
