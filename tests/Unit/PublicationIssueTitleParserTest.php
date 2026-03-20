<?php

namespace Tests\Unit;

use App\Services\PublicationIssueTitleParser;
use Tests\TestCase;

class PublicationIssueTitleParserTest extends TestCase
{
    public function test_parse_derives_display_title_date_string_and_release_date(): void
    {
        $metadata = app(PublicationIssueTitleParser::class)->parse('The Liberator, January 1911');

        $this->assertSame('The Liberator, January 1911', $metadata->displayTitle);
        $this->assertSame('January 1911', $metadata->dateString);
        $this->assertSame('1911-01-01', $metadata->releaseDate);
    }

    public function test_parse_returns_empty_release_date_for_unparseable_title(): void
    {
        $metadata = app(PublicationIssueTitleParser::class)->parse('The Liberator, Special Issue');

        $this->assertSame('Special Issue', $metadata->dateString);
        $this->assertSame('', $metadata->releaseDate);
    }
}
