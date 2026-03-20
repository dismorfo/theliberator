<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;

class CollectionIndexParser
{
    /**
     * Parse the HTML content of the collection index and return an array of entries grouped by date.
     *
     * @param string $html
     * @return array<string, array<string>>
     */
    public function parse(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $entriesByDate = [];

        // The structure seems to be <ul><li> Author/Title <span>Issue Info</span></li></ul>
        $listItems = $xpath->query('//section/ul/li');

        foreach ($listItems as $li) {
            $text = $li->textContent;
            
            // Extract dates in format (Month Year) or (Month/Month Year)
            preg_match_all('/\((?:[A-Z][a-z]{2,}(?:\/[A-Z][a-z]{2,})?\s\d{4})\)/', $text, $matches);

            foreach ($matches[0] as $dateMatch) {
                $date = trim($dateMatch, '()');
                
                // We want to normalize the date to match what's in the book documents if possible.
                // Based on the example "Aug 1917", it's already in a good format.
                
                if (!isset($entriesByDate[$date])) {
                    $entriesByDate[$date] = [];
                }
                
                // Clean up the text a bit to remove the extra whitespace
                $cleanText = preg_replace('/\s+/', ' ', trim($text));
                $entriesByDate[$date][] = $cleanText;
            }
        }

        return $entriesByDate;
    }
}
