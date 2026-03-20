<?php

namespace App\Services;

class PublicationIssueMetadata
{
    public function __construct(
        public readonly string $displayTitle,
        public readonly string $dateString,
        public readonly string $releaseDate,
    ) {}
}
