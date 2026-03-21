<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchOCRService;
use Illuminate\Console\Command;

class IndexOCRCommand extends Command
{
    protected $signature = 'ocr:index {--recreate : Recreate the index before indexing}';

    protected $description = 'Index all OCR files from storage to Elasticsearch';

    public function __construct(
        private ElasticsearchOCRService $elasticsearch
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('recreate')) {
            $this->info('Recreating OCR index...');
            $this->elasticsearch->recreateOCRIndex();
        } else {
            if (! $this->elasticsearch->indexExists()) {
                $this->elasticsearch->createOCRIndex();
                $this->info('Created OCR index.');
            } else {
                $this->warn('Index already exists, skipping creation. Use --recreate to recreate.');
            }
        }

        $ocrPath = storage_path('app/public/ocr');

        if (! is_dir($ocrPath)) {
            $this->warn('OCR directory not found: '.$ocrPath);

            return Command::FAILURE;
        }

        $directories = array_filter(glob($ocrPath.'/*'), 'is_dir');

        if (empty($directories)) {
            $this->warn('No OCR directories found in storage/app/public/ocr/');

            return Command::FAILURE;
        }

        $this->info('Found '.count($directories).' book directories to index.');

        $bar = $this->output->createProgressBar(count($directories));

        $bar->start();

        $totalIndexed = 0;

        foreach ($directories as $dir) {
            $identifier = basename($dir);
            $indexed = $this->indexBookOCR($identifier);
            $totalIndexed += $indexed;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info("Successfully indexed $totalIndexed OCR entries!");

        return Command::SUCCESS;
    }

    /**
     * ADVANCED INDEXING: Layout Awareness, Confidence Scoring, and Cross-Line Reconstruction
     */
    private function indexBookOCR(string $identifier): int
    {
        $files = glob(storage_path("app/public/ocr/{$identifier}/*.hocr"));
        if (empty($files)) {
            return 0;
        }

        $endpoint = config('viewer.endpoint');
        $indexName = $this->elasticsearch->getIndex();
        $totalIndexed = 0;

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $previousUseInternalErrors = libxml_use_internal_errors(true);
            $dom = new \DOMDocument;
            $dom->loadHTML($content);
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
            $xpath = new \DOMXPath($dom);

            $pageName = basename($file, '.hocr');
            preg_match('/_n(\d+)/', $pageName, $m);
            $canvasNumber = (int) ($m[1] ?? 1);
            $canvasId = "$endpoint/api/presentation/books/$identifier/canvas/$canvasNumber";

            $bulkParams = ['body' => []];

            $areas = $xpath->query('//*[contains(@class, "ocr_carea")]');
            foreach ($areas as $area) {
                $paragraphs = $xpath->query('.//*[contains(@class, "ocr_par")]', $area);
                foreach ($paragraphs as $par) {
                    $lines = $xpath->query('.//*[contains(@class, "ocr_line") or contains(@class, "ocr_header")]', $par);

                    $prevLineData = null;

                    foreach ($lines as $line) {
                        $lineData = $this->processLine($xpath, $line, $canvasId, $identifier);
                        if (! $lineData) {
                            continue;
                        }

                        $bulkParams['body'][] = ['index' => ['_index' => $indexName]];
                        $bulkParams['body'][] = array_merge($lineData, ['is_shingle' => false]);
                        $totalIndexed++;

                        if ($prevLineData) {
                            $shingleText = $prevLineData['text'].' '.$lineData['text'];
                            $shingleLocationKey = $prevLineData['location_key'].'|'.$lineData['location_key'];
                            $shingleCoords = $this->mergeCoords($prevLineData['coords'], $lineData['coords']);

                            if ($shingleCoords !== null) {
                                $bulkParams['body'][] = ['index' => ['_index' => $indexName]];
                                $bulkParams['body'][] = [
                                    'text' => $shingleText,
                                    'text_normalized' => $this->elasticsearch->normalizeText($shingleText),
                                    'confidence' => ($prevLineData['confidence'] + $lineData['confidence']) / 2,
                                    'manifest' => $identifier,
                                    'canvas' => $canvasId,
                                    'coords' => $shingleCoords,
                                    'location_key' => $shingleLocationKey,
                                    'is_shingle' => true,
                                    'timestamp' => now()->toIso8601String(),
                                ];
                                $totalIndexed++;
                            }
                        }
                        $prevLineData = $lineData;
                    }
                }
            }

            if (! empty($bulkParams['body'])) {
                $this->elasticsearch->bulk($bulkParams);
            }
        }

        return $totalIndexed;
    }

    /**
     * Extracts text and Confidence (x_wconf) from an HOCR line
     */
    private function processLine($xpath, $line, $canvasId, $identifier): ?array
    {
        $words = $xpath->query('.//*[@class="ocrx_word"]', $line);
        if ($words->length === 0) {
            return null;
        }

        $totalConf = 0;
        $texts = [];

        foreach ($words as $word) {
            $texts[] = $word->textContent;
            $wordTitle = $word->getAttribute('title');
            if (preg_match('/x_wconf (\d+)/', $wordTitle, $cm)) {
                $totalConf += (int) $cm[1];
            } else {
                $totalConf += 50;
            }
        }

        $avgConf = $totalConf / $words->length;
        $normConf = max(0.1, $avgConf / 100);

        $rawText = implode(' ', $texts);
        $text = preg_replace('/\s+/', ' ', trim($rawText));
        if (! is_string($text) || $text === '') {
            return null;
        }

        $coords = $this->extractCoords($line->getAttribute('title'));
        if ($coords === null) {
            $firstWord = $words->item(0);
            $coords = $firstWord ? $this->extractCoords($firstWord->getAttribute('title')) : null;
        }

        if ($coords === null) {
            return null;
        }

        return [
            'text' => $text,
            'text_normalized' => $this->elasticsearch->normalizeText($text),
            'confidence' => $normConf,
            'manifest' => $identifier,
            'canvas' => $canvasId,
            'coords' => $coords,
            'location_key' => $canvasId.'#'.$coords,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function extractCoords(string $title): ?string
    {
        if (! preg_match('/bbox (\d+) (\d+) (\d+) (\d+)/', $title, $matches)) {
            return null;
        }

        $x1 = (int) $matches[1];
        $y1 = (int) $matches[2];
        $x2 = (int) $matches[3];
        $y2 = (int) $matches[4];
        $width = $x2 - $x1;
        $height = $y2 - $y1;

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return "{$x1},{$y1},{$width},{$height}";
    }

    /**
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function parseCoords(string $coords): ?array
    {
        $parts = array_map('trim', explode(',', $coords));
        if (count($parts) !== 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            if (! ctype_digit($part)) {
                return null;
            }

            $values[] = (int) $part;
        }

        if ($values[2] <= 0 || $values[3] <= 0) {
            return null;
        }

        return $values;
    }

    private function mergeCoords(string $firstCoords, string $secondCoords): ?string
    {
        $coords1 = $this->parseCoords($firstCoords);
        $coords2 = $this->parseCoords($secondCoords);

        if ($coords1 === null || $coords2 === null) {
            return null;
        }

        [$x1, $y1, $width1, $height1] = $coords1;
        [$x2, $y2, $width2, $height2] = $coords2;

        $minX = min($x1, $x2);
        $minY = min($y1, $y2);
        $maxX = max($x1 + $width1, $x2 + $width2);
        $maxY = max($y1 + $height1, $y2 + $height2);

        return "$minX,$minY,".($maxX - $minX).','.($maxY - $minY);
    }
}
