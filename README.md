# The Liberator (prototype)

The Liberator is a Laravel application for browsing, viewing, and searching NYU's digitized **The Liberator** collection. It includes a collection landing page, per-issue reading views, a Mirador-based viewer, a collection-wide Elasticsearch search, and an IIIF Search API for OCR search inside a single issue.

## What the Prototype Does

- Lists issues from the external viewer API on the home page.
- Displays issue metadata and page-level views at `/book/{identifier}/{page}`.
- Serves a Mirador viewer at `/mirador/{identifier}/{page}`.
- Serves an About page at `/about`.
- Serves the collection topic index at `/collectionindex`.
- Provides a collection-wide search UI at `/search`.
- Provides an issue-scoped OCR search API at `/api/search/{identifier}` for IIIF consumers such as Mirador.

## Stack

- PHP 8.2
- Laravel 12
- Elasticsearch 9.3 client
- Vite + Sass for frontend assets
- PHPUnit 11 + Mockery for tests
- Optional DDEV setup for local development

## Architecture Overview

### External dependencies

- **Viewer API**: issue metadata, issue detail, and IIIF manifests come from `VIEWER_ENDPOINT`.
- **Elasticsearch**: powers both search modes.
- **Local storage**:
  - `storage/app/public/ocr/{identifier}` contains OCR text and HOCR source files used by the indexers.
  - `storage/app/public/mirador` contains compiled Mirador JS/CSS assets.
  - `public/images` contains issue thumbnails.

### Main application areas

- `app/Http/Controllers/HomeController.php`: home page with issue list.
- `app/Http/Controllers/AboutController.php`: about page.
- `app/Http/Controllers/CollectionIndexController.php`: collection topic index page.
- `app/Http/Controllers/BookController.php`: issue page view.
- `app/Http/Controllers/BookViewerController.php`: Mirador shell plus proxied IIIF manifest with OCR search service injected.
- `app/Http/Controllers/SearchController.php`: collection-wide search page.
- `app/Http/Controllers/OCRSearchController.php`: issue-scoped OCR search API.
- `app/Services/ViewerApiClient.php`: outbound viewer API client with shared timeout/retry behavior.
- `app/Services/CachedViewerApiService.php`: cached and normalized viewer API access for the web app.
- `app/Services/PublicationIssueTitleParser.php`: derives issue title metadata such as `date_string` and `release_date`.
- `app/Services/ElasticsearchService.php`: collection search index and query builder.
- `app/Services/ElasticsearchOCRService.php`: OCR search index and query builder.
- `app/Services/ElasticsearchClientFactory.php`: shared Elasticsearch client creation.
- `app/Console/Commands/IndexBooksCommand.php`: builds the collection-search index.
- `app/Console/Commands/IndexOCRCommand.php`: builds the OCR-search index from HOCR files.

## Search Modes

The application has **two separate search systems**. They serve different use cases and return different response shapes.

### 1. `/search` for collection search

This is the user-facing search page for searching across the collection.

Route:

```text
GET /search?q=history&page=1
```

Controller:

- `app/Http/Controllers/SearchController.php`

Back-end service:

- `app/Services/ElasticsearchService.php`

What it searches:

- issue title
- issue date string
- normalized metadata such as author, subject, publisher, contributor
- parsed collection index entries
- nested OCR page text as supporting evidence for deep links

Response:

- HTML page rendered by `resources/views/search.blade.php`
- search result cards linked to `/book/{identifier}/{page}`
- page number for the best OCR hit when the match came from OCR text
- aggregation/facet data from Elasticsearch

Search behavior:

- phrase-first ranking
- strong boosts for title and collection index entries
- cross-field metadata matching
- nested OCR page matching with `inner_hits` so a result can deep-link to the matching page
- rescore phase to improve ranking for exact or near-exact phrase matches

### 2. `/api/search/{identifier}` for OCR search

This is the issue-level OCR search endpoint, intended for Mirador and other IIIF clients.

Route:

```text
GET /api/search/{identifier}?q=workers+strike&page=1&per_page=20
```

Controller:

- `app/Http/Controllers/OCRSearchController.php`

Request validation:

- `app/Http/Requests/OCRSearchRequest.php`

Back-end service:

- `app/Services/ElasticsearchOCRService.php`

What it searches:

- OCR lines for a single issue
- synthetic "shingle" records created from adjacent lines to recover phrases that span line breaks

Response:

- IIIF Search API style JSON
- `sc:AnnotationList`
- `resources` with annotation targets using canvas fragments (`#xywh=...`)
- `hits` with `before`, `match`, and `after`
- `within.total` and pagination metadata

Behavior:

- search is scoped to a single issue identifier
- validation requires `q`, enforces `min:2`, and caps `per_page` at `50`
- duplicate hits are filtered before the response is emitted
- invalid OCR hits are discarded if they cannot produce a valid annotation target

### Relationship between the two search systems

- `/search` answers: "Which issue should I open?"
- `/api/search/{identifier}` answers: "Where inside this issue is the text?"

They are complementary rather than interchangeable.

## Search Indexes

The application uses two Elasticsearch indexes, but their final names are **derived from configuration**, not hardcoded in the repository.

- `services.elasticsearch.site_key` defaults to `VIEWER_COLLECTION_CODE`
- `services.elasticsearch.index_prefix` is optional
- index names are built from those values plus the suffixes `books`, `ocr`, and `analyzer`

With the default configuration in this repository, the index names are typically:

- `theliberator_books`: collection-wide issue search
- `theliberator_ocr`: issue-level OCR search

### `books:index`

Command:

```bash
php artisan books:index --no-interaction
php artisan books:index --recreate --no-interaction
```

Behavior:

- renders the local collection index view and parses the extra topic index entries
- fetches issue records from the viewer API
- fetches per-issue metadata from the viewer API
- loads OCR `.txt` files from `storage/app/public/ocr`
- writes enriched issue documents into the configured `books` index
- fails the command if Elasticsearch bulk indexing reports item-level errors

### `ocr:index`

Command:

```bash
php artisan ocr:index --no-interaction
php artisan ocr:index --recreate --no-interaction
```

Behavior:

- reads HOCR files from `storage/app/public/ocr/{identifier}`
- extracts OCR lines, confidence, coordinates, and canvas targets
- creates additional shingle documents from adjacent lines
- writes the result into the configured `ocr` index
- fails the command if Elasticsearch bulk indexing reports item-level errors

## Local Development

### Standard Laravel setup

```bash
composer install
cp .env.example .env
php artisan key:generate --no-interaction
php artisan migrate --no-interaction
npm install
npm run build
php artisan storage:link
```

Then start the app:

```bash
composer run dev
```

### Required environment variables

At minimum, configure these values in `.env`:

```dotenv
APP_NAME="The Liberator"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

ELASTICSEARCH_HOST=http://127.0.0.1:9200
VIEWER_ENDPOINT=https://sites.dlib.nyu.edu/viewer
VIEWER_CACHE_STORE=file
VIEWER_COLLECTION_CODE=theliberator
```

Notes:

- `ELASTICSEARCH_HOST` is required for both search services.
- `VIEWER_ENDPOINT` is required for issue metadata, book pages, and manifest proxying.
- `VIEWER_COLLECTION_CODE` affects both viewer API collection lookups and Elasticsearch index naming unless `ELASTICSEARCH_SITE_KEY` is set explicitly.

### DDEV setup

This repository includes DDEV configuration and an Elasticsearch sidecar container.

Typical flow:

```bash
ddev start
ddev composer install
ddev npm install
ddev php artisan key:generate --no-interaction
ddev php artisan migrate --no-interaction
ddev php artisan storage:link
ddev npm run build
```

The DDEV Elasticsearch container is defined in `.ddev/docker-compose.elasticsearch.yaml` and exposes Elasticsearch 9.3 as a single-node instance.

In DDEV, set:

```dotenv
ELASTICSEARCH_HOST=http://elasticsearch:9200
```

## Data and Asset Requirements

The application is not fully functional with code alone. It also needs source content and generated assets.

### Required files and directories

- `storage/app/public/ocr/{identifier}`:
  - `.txt` OCR text files for collection search enrichment
  - `.hocr` files for issue-level OCR search indexing
- `storage/app/public/mirador`:
  - Mirador JS bundle
  - Mirador CSS bundle

### Storage symlink

Run:

```bash
php artisan storage:link --no-interaction
```

Without that symlink, Mirador assets and other public storage files will not be served from `/storage/...`.

## Deployment

### 1. Provision runtime services

Required services:

- PHP 8.2
- Composer
- Node.js/npm for asset builds
- a web server pointing to `public/`
- Elasticsearch 9.x reachable from the application
- writable Laravel `storage/` and `bootstrap/cache/`

### 2. Set environment variables

Copy `.env.example` to `.env` and set the required variables.

### 3. Install PHP and JS dependencies

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

### 4. Prepare Laravel

```bash
php artisan key:generate --no-interaction
php artisan migrate --force --no-interaction
php artisan storage:link --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 5. Deploy data and static assets

Make sure the server has:

- the OCR source files under `storage/app/public/ocr`
- Mirador compiled assets under `storage/app/public/mirador`

If those files are missing, the UI may render but search and viewing will be incomplete.

### 6. Build the Elasticsearch indexes

Run both indexers after deployment and whenever source data changes:

```bash
php artisan books:index --recreate --no-interaction
php artisan ocr:index --recreate --no-interaction
```

Recommended order:

1. Deploy code and assets.
2. Confirm `APP_URL`, `VIEWER_ENDPOINT`, and `ELASTICSEARCH_HOST`.
3. Run `books:index`.
4. Run `ocr:index`.
5. Smoke-test `/search`, `/book/{identifier}/1`, and `/mirador/{identifier}/1`.

### 7. Web server expectations

- Document root must be `public/`.
- Laravel must be able to write to `storage/` and `bootstrap/cache/`.
- `/storage` must resolve through Laravel's storage symlink.
- Outbound HTTP access is required for the viewer API and any indexing operations.

## Deployment Caveats

### OCR search needs source HOCR files

`/api/search/{identifier}` does not work unless `ocr:index` has been run successfully against HOCR files stored under `storage/app/public/ocr`.

### Mirador needs prebuilt assets

The Mirador page looks for already-built files in `storage/app/public/mirador`. This repository does not build those assets as part of the standard Vite pipeline, so deployment must include them explicitly.

## Testing

Run the test suite with:

```bash
php artisan test
```

Core tests:

- `tests/Feature/BookControllerTest.php`
- `tests/Feature/SearchControllerTest.php`
- `tests/Feature/OCRSearchControllerTest.php`
- `tests/Feature/IndexBooksCommandTest.php`
- `tests/Unit/ElasticsearchServiceTest.php`
- `tests/Unit/ElasticsearchOCRServiceTest.php`
- `tests/Unit/PublicationIssueTitleParserTest.php`

Search-focused regression suite:

```bash
php artisan test --filter='(SearchControllerTest|OCRSearchControllerTest|ElasticsearchServiceTest|ElasticsearchOCRServiceTest)'
```

## Suggested Smoke Checks

- Open `/` and confirm issue cards render.
- Open `/search?q=history` and confirm results link into issue pages.
- Open `/book/{identifier}/1` for a known issue and confirm issue metadata renders.
- Open `/mirador/{identifier}/1?q=history` for a known issue and confirm Mirador loads.
- Request `/api/search/{identifier}?q=history` for a known issue and confirm IIIF-style JSON is returned.

## Future Improvements

- Small opt-in integration suite with a seeded Elasticsearch test index and real expected top-N results for a handful of canonical queries.
- Hybrid search results that combine collection and OCR matches in a single ranked list on the `/search` page.
