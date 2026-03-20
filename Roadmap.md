Hybrid Plan
  The right near-term architecture is not “replace OCR search with CV.” It is:

  1. Keep OCR search as the grounding layer for coordinates/highlights.
  2. Add visual page retrieval as a second candidate generator.
  3. Fuse the two at page ranking time.
  4. Resolve final highlight boxes from OCR regions whenever possible.

  Recommended pipeline:

  - Ingest per page:
      - Current OCR lines/words with coordinates.
      - Page image thumbnail or normalized raster for CV embedding.
      - Page-level metadata: identifier, page, date, issue, manifest/canvas.
  - Build two indexes:
      - Lexical OCR index: your current Elasticsearch OCR line/shingle index.
      - Visual page index: one record per page with ColPali multi-vector embeddings stored in a vector-capable system.
  - Query flow:
      - Run lexical search on OCR text.
      - Run visual retrieval on page images using ColPali.
      - Normalize both scores.
      - Fuse with reciprocal-rank fusion or weighted sum.
      - Rerank top pages with lightweight features:
          - exact phrase hit
          - OCR token coverage
          - CV score
          - page proximity if multiple hits in same issue
          - title/date/metadata boosts from collection search
  - Highlight resolution:
      - If OCR hit exists on a winning page, use its coordinates directly.
      - If only CV matched, derive a heatmap region, then intersect that region with OCR words/lines on the page.
      - Promote overlapping OCR boxes into final highlight rectangles.
      - If overlap is weak, return page-level navigation only instead of fake precise highlights.

  For your codebase, I would implement it in phases:

  1. Add a page_search_candidates concept in collection search result shaping.
  2. Add a new per-page search table/index keyed by identifier + page.
  3. Keep Elasticsearch for metadata + OCR lexical search.
  4. Put ColPali vectors in a separate retrieval service first, not directly inside Elasticsearch.
  5. Add a fusion service, for example HybridPageSearchService, that returns:
      - identifier
      - page
      - score_lexical
      - score_visual
      - score_fused
      - matched_regions
      - highlight_source = ocr|cv|hybrid
  6. Only after that, feed fused page hits back into:
      - /search collection results
      - /api/search/{identifier} item-level results

  Data model to add:

  - identifier
  - page
  - canvas
  - image_path or IIIF image URL
  - ocr_words[] or ocr_lines[] with text, bbox, confidence
  - cv_embedding_ref
  - optional cv_patch_grid metadata so heatmap-to-pixel mapping is deterministic

  Important design rule:

  - Do not index CV matches directly as if they were text hits.
  - Instead, index CV at page level, then resolve to OCR coordinates post-retrieval.
  - That keeps your highlights honest and explainable.

  Scoring strategy I’d start with:

  - If exact OCR phrase hit exists: lexical dominates.
  - If OCR weak but CV strong: surface the page, then try OCR-region projection from the heatmap.
  - If both agree on the same page: strong boost.
  - If CV and OCR disagree across nearby pages in same issue: keep top 3 and rerank with issue context.

  Operational advice for your hardware limits:

  - Start with offline page embedding only.
  - Do not run ColPali in the request path initially.
  - Precompute embeddings during ingest.
  - Query only top-N pages visually, then rerank.
  - Use smaller page images first.
  - Consider batching embeddings per issue.

  Examples and useful patterns:

  - Princeton Digital PUL explicitly supports OCR text search at site, collection, and item level, including item-level viewer search: D
    PUL search functionality (https://dpul.princeton.edu/exhibits_documentation/feature/search-functionality-in-dpul)
  - Princeton’s Figgy repository description shows OCR as a first-class repository feature alongside structural page data: Figgy at Prin
    ceton (https://dpul.princeton.edu/specialized-digital-imaging/feature/figgy-pul-s-digital-repository)
  - Harvard’s IIIF/Viewer stack is centered on IIIF delivery and Mirador, which is the same interoperability direction you are already u
    sing: Harvard IIIF manifests (https://library.harvard.edu/services-tools/iiif-manifests-digital-objects), Harvard IIIF at scale
    (https://iiif.harvard.edu/collaborators/harvard-library/)
  - Stanford’s IIIF documentation also reflects the same viewer/interoperability model rather than custom locked-in viewers: Stanford II
    IF (https://library.stanford.edu/iiif)
  - For the model itself, the ColPali model card is the right baseline reference for constraints and late-interaction behavior: vidore/c
    olpali-v1.2 (https://huggingface.co/vidore/colpali-v1.2)

  Two practical recommendations before starting CV:

  - Upgrade OCR storage from line-only to word-level boxes if possible. Heatmap-to-highlight alignment will be much better.
  - Add evaluation sets now: 50 to 100 real queries with expected issue/page/region. Without that, fusion tuning will drift.

  If you want, I can turn this into a concrete implementation roadmap for this repo with proposed classes, endpoints, index schemas, and
  ingestion jobs.

Roadmap

  Build hybrid retrieval as a new page-level layer that feeds your existing book and OCR flows, not as a replacement.

  Phase 1: stabilize current lexical stack

  - Reindex OCR with the new normalized analyzer.
  - Add evaluation queries now:
      - 30 exact OCR phrase queries
      - 30 noisy/OCR-corrupted queries
      - 20 metadata/title queries
      - 20 “layout-heavy” queries likely to benefit from CV later
  - Store expected outputs as identifier, page, and if possible xywh.
  - Add regression tests around:
      - /search result ranking
      - /api/search/{identifier} highlight quality
      - query-to-matched_phrase link generation

  Phase 2: introduce a page retrieval abstraction

  - Add a new service: app/Services/HybridPageSearchService.php
  - Responsibility:
      - call lexical page search
      - call visual page search
      - fuse scores
      - return normalized page candidates
  - Add a DTO/array contract like:

  [
    'identifier' => 'lib000027',
    'page' => 44,
    'canvas' => '...',
    'score_lexical' => 12.4,
    'score_visual' => 0.81,
    'score_fused' => 0.92,
    'highlight_source' => 'ocr|cv|hybrid',
    'regions' => [
      ['coords' => '1036,4879,1395,88', 'text' => 'the Court on Receiving Sentence.'],
    ],
  ]

  Phase 3: add a dedicated page index

  - Add a new ingest model in Elasticsearch or a separate store keyed by page:
      - identifier
      - page
      - canvas
      - issue_title
      - date_string
      - ocr_text
      - ocr_lines[]
      - ocr_words[] if available
      - image_ref
  - New service: app/Services/PageSearchIndexService.php
  - New command: app/Console/Commands/IndexPageSearchCommand.php
  - Purpose:
      - create one searchable unit per page instead of inferring pages from nested book docs
      - simplify hybrid fusion later

  Phase 4: separate lexical and visual retrieval

  - Keep current app/Services/ElasticsearchService.php for collection/item metadata.
  - Keep app/Services/ElasticsearchOCRService.php for issue-level OCR highlights.
  - Add:
      - app/Services/LexicalPageSearchService.php
      - app/Services/VisualPageSearchService.php
  - LexicalPageSearchService should query page units directly, not nested ocr_pages.
  - VisualPageSearchService should query precomputed ColPali embeddings and return top page candidates plus patch/heatmap evidence.

  Phase 5: embed pages offline

  - Add a background embedding job, not request-time inference.
  - New command/job pair:
      - app/Console/Commands/EmbedPageImagesCommand.php
      - app/Jobs/GeneratePageEmbeddingsJob.php
  - Input:
      - IIIF image URL or local rasterized page image
  - Output:
      - vector payload reference
      - patch grid metadata
      - image dimensions
  - Store vectors in a dedicated vector backend first. I would not force this into Elasticsearch unless your cluster already supports the
    exact vector workflow you want.

  Phase 6: project CV heatmaps into OCR coordinates

  - Add app/Services/RegionGroundingService.php
  - Inputs:
      - CV patch heatmap
      - page image size
      - OCR words/lines with bounding boxes
  - Logic:
      - map top heatmap patches to image-space boxes
      - intersect with OCR boxes
      - merge overlapping OCR boxes into final xywh
  - Rule:
      - if overlap with OCR boxes is strong, emit real highlights
      - if overlap is weak, emit page-level match only
  - This is the key step that lets CV still drive Mirador highlights honestly.

  Phase 7: fuse ranking

  - Add app/Services/HybridRankingService.php
  - Start simple:
      - reciprocal-rank fusion or weighted sum
  - Features to combine:
      - exact phrase hit
      - token coverage
      - OCR confidence
      - visual score
      - agreement bonus when OCR and CV hit same page
      - title/date/index-entry boosts from collection search
  - Make weights config-driven in config/search.php

  Phase 8: integrate into existing endpoints

  - /search
      - collection results should rank by best fused page evidence per issue
      - result cards still deep-link to /book/{identifier}/{page}?q=...
  - /api/search/{identifier}
      - first run lexical OCR search
      - if empty or weak, optionally consult visual page candidates for that issue
      - grounded regions become IIIF Search hits when possible
  - Add one internal endpoint if needed:
      - /api/hybrid-search/{identifier} for debugging fusion and grounding only

  Phase 9: add observability

  - Log for each query:
      - lexical top page
      - visual top page
      - fused winner
      - whether highlight was OCR or grounded-from-CV
  - Add an admin/debug dump view for one query showing:
      - OCR snippets
      - visual top patches
      - final merged boxes

  Repo Changes
  New files I would add:

  - app/Services/HybridPageSearchService.php
  - app/Services/LexicalPageSearchService.php
  - app/Services/VisualPageSearchService.php
  - app/Services/HybridRankingService.php
  - app/Services/RegionGroundingService.php
  - app/Services/PageEmbeddingStore.php
  - app/Console/Commands/IndexPageSearchCommand.php
  - app/Console/Commands/EmbedPageImagesCommand.php
  - app/Jobs/GeneratePageEmbeddingsJob.php
  - config/search.php

  Data Shape
  Minimum page record:

  [
    'identifier' => 'lib000027',
    'page' => 44,
    'canvas' => '.../canvas/44',
    'ocr_text' => '...',
    'ocr_lines' => [
      ['text' => '...', 'coords' => 'x,y,w,h', 'confidence' => 0.91],
    ],
    'image_ref' => 'iiif-image-or-local-path',
    'embedding_ref' => 'vector-store-key',
  ]

  Practical Advice

  - Do not wait for full CV rollout to move to page-level indexing. That helps search now.
  - If you can enrich OCR ingestion to word-level boxes, do it before CV. Grounding quality will improve substantially.
  - With limited hardware, embed offline, query online.
  - Keep CV optional behind config until evaluation proves the lift.

  Comparable Patterns

  - Princeton DPUL exposes OCR text search at collection and item levels, which is the right UX model for your split between /search and
    /api/search/{identifier}: DPUL search functionality
    (https://dpul.princeton.edu/exhibits_documentation/feature/search-functionality-in-dpul)
  - Princeton Figgy reflects repository-level OCR plus structural page data, which is close to the page-unit approach above: Figgy at Pr
    inceton (https://dpul.princeton.edu/specialized-digital-imaging/feature/figgy-pul-s-digital-repository)
  - Harvard and Stanford both center IIIF and Mirador-style interoperability, which supports keeping your highlight output IIIF-compatib
    le even if retrieval becomes hybrid: Harvard IIIF manifests
    (https://library.harvard.edu/services-tools/iiif-manifests-digital-objects), Harvard IIIF
    (https://iiif.harvard.edu/collaborators/harvard-library/), Stanford IIIF (https://library.stanford.edu/iiif)
  - For ColPali itself, use the model card as the baseline operational reference: vidore/colpali-v1.2
    (https://huggingface.co/vidore/colpali-v1.2)

  If you want, I can turn this into a task-by-task implementation checklist ordered for this codebase, starting with the page-level
  lexical index.
