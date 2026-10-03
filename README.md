# Smart Product Search

Search a 1,000-product catalog by exact name ("iPhone 16", "שואב אבק") or by describing a need
("מתנה למישהו שאוהב לבשל", "משהו שיעזור לנקות שערות של כלב מהספה"), in Hebrew, English, or both.

- **Live site:** _TODO: add after deployment_
- **Repository:** _TODO: add GitHub link_

**Stack:** PHP 8.4 (no framework) · MySQL 8.4 · vanilla HTML/CSS/JS · OpenAI `text-embedding-3-large` · Docker

---

## Running locally

Requirements: Docker Desktop.

```bash
cp .env.example .env              # then set DB passwords and OPENAI_API_KEY
docker compose up -d --build      # app on http://localhost:8080, MySQL with a persistent volume
docker compose exec app php /var/www/backend/bin/import.php   # load the catalog + compute embeddings
```

Open http://localhost:8080.

### Loading the catalog

`bin/import.php [path/to/catalog.csv] [--skip-embeddings]` (default path: `data/catalog_no_prices.csv`)

- Creates the schema if needed (`backend/sql/001_schema.sql`).
- Validates the header and every row; malformed rows are reported and skipped, never half-imported.
- Upserts by product id inside a transaction and removes products no longer in the CSV, so it is safe to re-run.
- Embeds only products whose text changed (each row stores a hash of its embedded text), so a
  re-import of an unchanged catalog makes no API calls. The full catalog costs well under $0.01 to embed.
- Bumps a catalog version so every server rebuilds its cached search index.

Without `OPENAI_API_KEY` everything still works, in keyword-only mode.

### Tests

```bash
docker compose exec app php /var/www/backend/tests/run.php            # add --verbose for per-query scores
```

Any search can also be inspected over HTTP with `?debug`, e.g. `/api/search?q=אייפון&debug`, which adds
each result's keyword, semantic and final scores.

---

## How it works

```
Browser (frontend/)                    GET /api/search?q=...
        │
        ▼
public/index.php ─► Router (routes/api.php) ─► SearchController ─► SearchService
                                                                     │
                                      ┌──────────────────────────────┼──────────────────────┐
                                      ▼                              ▼                      ▼
                              CatalogIndexService             EmbeddingService      Support/TextNormalizer
                              (in-memory index, APCu)         (query vector cache)  Support/QueryAliases
                                      │                              │
                                      ▼                              ▼
                              ProductRepository               QueryEmbeddingRepository ─► OpenAI (cache miss only)
                                      │
                                      ▼
                                    MySQL
```

| Layer | Responsibility |
|---|---|
| `routes/api.php`, `Core/Router` | Maps `GET /api/search` to the controller; 404/405 for everything else |
| `Controllers/` | HTTP only: validates `q`/`limit`, calls the service, shapes the JSON |
| `Services/` | All logic: ranking (`SearchService`), index building, embeddings, CSV import, price estimation |
| `Repositories/` | All SQL lives here, nowhere else |
| `Models/` | `Product` (the entity; its JSON form is the API result) and `CatalogIndex` (the in-memory search index) |
| `Clients/` | The raw OpenAI HTTP call, so the provider can be swapped in one file |
| `bootstrap/app.php` | Autoloading and wiring by hand, shared by the web entry point, the import CLI and the tests |

### Search, step by step

1. **Normalize and tokenize** the query: lowercase, strip niqqud, unify ׳/'/״, split `iphone16` → `iphone 16`,
   drop filler words ("משהו", "של", "someone"...). "מתנה"/"gift" counts as filler too: any product can be a gift,
   so the word says why someone buys, not what they buy (the semantic side still sees the whole sentence).
   Request words ("ומוצרים", "דומים", "similar", "products") are set aside too: they describe what to return, not
   the product. Filler words are recognized with Hebrew prefixes attached ("ולמישהו", "ומוצרים").
2. **Expand aliases.** The catalog never says "iPhone"; it lists "סמארטפון חכם 6.1 אינץ'" under brand Apple.
   A small alias table maps `אייפון → iphone → apple + סמארטפון`, Hebrew brand spellings to the English brand
   column (`סמסונג → samsung`), and a few common English words to the catalog's Hebrew wording.
   Multi-word aliases require all their words, so "iPhone" matches Apple *smartphones*, not every Apple product.
3. **Keyword score (0–1):** the idf-weighted fraction of query terms a product matches. A hit in the name counts
   (or subcategory, which names the product type just as reliably) counts more than in the brand, description or
   attributes. Numbers are a bonus when they match but never count against a product: the catalog has no model
   numbers, so "iPhone 16" is as precise as "iPhone". Hebrew prefixes and plurals are handled with
   light stemming (`לכלבים → כלב`), and prefixes of words also match (`שוא → שואב`), which suits search-as-you-type.
4. **Semantic score (0–1):** cosine similarity between the query's embedding and each product's embedding
   (name, brand, category, description, attributes). This is what connects "loves to cook" to pots and pans,
   "dog hair on the sofa" to upholstery cleaners and a pet-hair brush, and English queries to Hebrew products.
5. **Decide how precise the query is.** *Keyword confidence* (0–1) combines how well the best product matched the
   query's words with the share of content words in the query. "ps5", "iphone" and "מחבת" score 1: they name
   something. "מתנה למישהו שאוהב לבשל" scores low: it's phrased as a sentence and describes a need.
6. **Blend:** keywords get up to 40% of the score, in proportion to that confidence. For descriptive queries the
   words that do match mislead ("כלב" pulls in dog beds), so meaning decides.
7. **Cut off,** also driven by confidence. Every result must pass an absolute minimum (this filters gibberish).
   - **Descriptive queries** keep anything within 55% of the best score, because a broad list of ideas is the right
     answer.
   - **Precise queries** keep only results within 85% of the best, plus any product that matches the query's words
     as fully as the best match does. That keeps every pan for "מחבת" even though the semantic side is noisy for
     single Hebrew words. So "iPhone" shows the three Apple phones, not every smartphone, MacBook and Apple Watch.
   
   If nothing passes, the UI shows a "no results" state.
8. **Similar products, only when asked.** If the query asks for alternatives ("אייפון ומוצרים דומים",
   "iphone and similar products"), the rest of the query is searched as above. Then the other products of the same
   type, meaning the same subcategory as the top three matches, are appended in ranking order. "Same type" rather
   than "semantically close": to an embedding, chargers and screen protectors are close to a phone too.

---

## Key decisions

| Decision | Why |
|---|---|
| **Hybrid search (keywords + embeddings)** instead of keywords only | Need-based queries share almost no words with the right products. Measured: in keyword-only mode "מתנה למישהו שאוהב לבשל" returned two unrelated gift boxes, and the dog-hair query returned nothing. Keywords are still kept because they make exact names, brands and models ("ps5", "Air Fryer") land precisely. |
| **OpenAI `text-embedding-3-large` at 512 dims** (configurable in `.env`) | The Anthropic API has no embeddings endpoint. I started with `3-small`, but the golden tests showed it was weak on Hebrew and cross-language queries: "something to help me sleep better" matched almost nothing in the Hebrew catalog. `3-large` fixed that at no extra latency, and embedding the whole catalog still costs about $0.02. 512 dimensions (natively supported) keep storage and in-PHP similarity fast. |
| **Product embeddings computed once, at import** | Each search embeds only the query, and query vectors are cached (APCu, then MySQL), so a repeated search never calls the API. |
| **Whole catalog in memory, cached in APCu** | 1,000 products is small. Scoring everything takes about 20 ms and needs no search server. It works on plain shared PHP hosting, where MySQL has no vector search. Vectors stay packed as binary in the cache: as PHP float arrays the index was 16 MB and cost about 70 ms per request just to load. |
| **Precise queries return only what they name; alternatives only on request** | Measured: "iPhone" first returned 20 results, with Samsung, Xiaomi and Huawei phones, a MacBook and an Apple Watch blended in below the Apple phones. Precise queries now return exact matches only. To see alternatives, the user asks for them ("אייפון ומוצרים דומים"), and gets the exact matches first, then the rest of that product type. The UI has no separate "similar products" section; they appear in the same grid, after the exact matches, without a label. |
| **Graceful degradation** | If the embeddings API is down or slow (`OPENAI_WEB_TIMEOUT_SECONDS`, 3 s by default), search falls back to keyword-only and the UI shows a small notice instead of an error. |
| **MySQL** | Persistent storage of the catalog, the embeddings and the query cache. Common on PHP hosting. |
| **Plain PHP with a thin MVC structure, no framework** | One endpoint doesn't justify Laravel. Routes, controller, services and repositories keep responsibilities clear and changes easy to place, and there is no Composer dependency to install on a host. |
| **Estimated prices** | The supplied CSV has no prices, but every result must show one. `PriceEstimator` assigns a stable, realistic price: an ILS range per subcategory, nudged by wording ("Pro", "מקצועי" up; "בסיסי", "מיני" down), rounded to retail endings. If the CSV gains a `price` column, the importer uses it instead. |
| **Search-as-you-type** (350 ms debounce, stale requests aborted) plus explicit submit | Feels fast. Query-vector caching keeps the extra requests cheap. |
| **Vanilla JS frontend** | One page with one interaction; no build step needed. |

---

## How I tested it

**Automated (`backend/tests/run.php`):**
- **Unit checks** for the parts where mistakes are silent: Hebrew normalization and stemming, alias expansion,
  and price determinism and ranges.
- **Input validation** of the API, by calling the controller directly: empty, whitespace-only and control-character
  queries, invalid UTF-8, length limits counted in characters (not bytes), and `limit` parsing.
- **A golden set of 23 query cases** with expected outcomes (6 need semantic search and run only when embeddings are
  enabled). For example: "iPhone 16", "iphone" and "אייפון" return *exactly* the three Apple phones, "macbook" only
  MacBooks, "אייפון ומוצרים דומים" the three Apple phones first and then exactly the 13 smartphones, at least 15 results for "שואב אבק" and all of them vacuums (robot vacuums included), "qwxzkj" returns nothing, and for the dog-hair query at least
  three of the top six are vacuums or pet-hair tools, with no hair-removal devices in the top three.
  Ranking weights were tuned against this set rather than by eyeballing single queries.

**Manual:**
- **API edge cases:** empty query (400), unknown route (404), wrong method (405), database stopped (clean 500
  JSON, and the UI shows an error with a retry button).
- **UI:** checked in desktop and mobile widths for RTL layout and the loading, empty and error states.

**Speed:** about 250 ms for a query seen for the first time (the OpenAI call), then about 30 ms end to end over
HTTP once the query vector is cached.

Example searches (top results, hybrid mode):

| Query | Top results |
|---|---|
| `שואב אבק` | 15 vacuums: upright, wet/dry, car, handheld, robot |
| `iPhone 16` / `iphone` / `אייפון` | exactly the three Apple smartphones |
| `סמסונג גלקסי` | exactly the three Samsung smartphones |
| `macbook` | the two MacBooks |
| `אייפון ומוצרים דומים` / `iphone and similar products` | the three Apple phones, then the other 10 smartphones (the same 13 as `סמארטפון`) |
| `מחבת ומוצרים דומים` | the six pans, then the other pots and pans |
| `ps5` | the PS5 console |
| `מתנה למישהו שאוהב לבשל` | multi-cooker, fondue pot, BBQ tool set, soup pot, cast-iron pot, tagine |
| `משהו שיעזור לנקות שערות של כלב מהספה` | pet carpet cleaner, dog bed (off target), pet grooming clipper, handheld steam cleaner, carpet and upholstery cleaner, pet-hair brush |
| `something to help me sleep better` | aromatherapy diffuser, weighted blanket, sleep earphones, white-noise machine, sunrise alarm clock |
| `qwxzkj` | the "no results" state |

Keyword-only fallback, without the API key: the 17 keyword-only cases still pass. The need-based queries degrade,
since that is exactly what embeddings are for. "סמסונג גלקסי" still ranks the phones first but also lists Samsung
appliances, because only the semantic side can tell a Samsung fridge from a Samsung phone.

**Problems the tests found and fixed along the way:**
- The prefix rule was too strict, so `לכלב` didn't match `כלב`.
- The "galaxy" alias was missing "smartphone", so Samsung fridges outranked phones.
- Alias words were first combined with OR, so "iPhone" ranked every Apple product the same as every smartphone.
- `3-small` embeddings failed three need-based queries, so I switched to `3-large`.
- With a fixed 40% keyword weight, "מתנה" and "כלב" dragged gift boxes and dog beds to the top. That led to the
  adaptive keyword weight.
- "iPhone" returned 20 results with other brands blended in. Measuring the real cosine distribution showed the
  semantic range had been tuned for `3-small` and saturated on alias-expanded queries: Apple and non-Apple phones
  both scored 1.0. Recalibrating it and adding the confidence-driven cutoff fixed this, plus the content-word share,
  so that "מתנה למישהו שאוהב לבשל" wasn't mistaken for a precise query.
- "אייפון ומוצרים דומים" returned a translator, a charger and a screen protector but only 4 of the 13
  smartphones. "ומוצרים" and "דומים" were treated as product words. They matched nothing, which made the query look
  vague, so semantic closeness to "phone" decided alone. Fixed by recognizing request words, prefixes included,
  and by defining "similar" as the same product type.
- Measuring over HTTP (about 64 ms) against the CLI (about 9 ms) exposed the cost of loading the cached index, fixed
  by keeping vectors packed.
- A test was wrong, not the code: a car vacuum filed under "רכב" is still a vacuum, so the test now checks for
  vacuums in general.

---

## Known limitations and next steps

- **Prices and images are placeholders.** Prices are estimated (see above) and images are the CSV's placeholder URLs.
- **No typo tolerance** beyond prefix matching: "שואר אבק" relies on the semantic side. Next: trigram or
  edit-distance matching on the keyword side.
- **Hand-curated aliases and stopwords.** They cover common cases, not every product line. Next: learn aliases
  from search logs (queries with no clicks).
- **Brute-force vector scoring** is fine up to tens of thousands of products. Beyond that: an ANN index
  (pgvector, OpenSearch, or a vector DB).
- **Ranking constants are tuned on a small golden set.** Next: a larger labelled set and click data.
- **Need-based queries are good, not perfect.** For the dog-hair query, a dog bed ranks second and the most
  direct answer, the pet-hair brush, only sixth, because the embedding weighs "dog" heavily. Next: for descriptive queries only, have an LLM rewrite
  the need into product types ("handheld vacuum, pet-hair remover") before embedding, with the result cached per
  query. This adds about 0.5–1 s on first use.
- **Alternatives appear only on request and aren't labelled.** "iPhone" shows only Apple phones. Other brands
  appear only if the user asks ("אייפון ומוצרים דומים"), and then in the same grid with no visual separation from
  the exact matches. The words that trigger this are a short hand-written list. Next: a clearly labelled
  "מוצרים דומים" section under every precise search, so users don't need to know to ask.
- **Features left out on purpose to stay focused:** filters (category, price), sorting, pagination, "did you mean",
  highlighting of matched words, and rate limiting on the API.

---

## Working with Claude

I used Claude Code throughout: to explore the CSV, write most of the code, run it in Docker, and test it,
with me reviewing and redirecting the decisions.

**How I used it:**
- **Design discussion before code.** Claude profiled the CSV first (columns, empty brands, category counts)
  and found two facts that shaped the design: the catalog never uses the word "iPhone", and it has no prices.
- **Challenging its proposals.** Claude proposed hybrid search. I asked whether plain keyword tracking
  ("cooking", "gift") would do. It showed concrete failure cases, and the keyword-only run later confirmed them.
- **Tests as the check on its output.** I asked for a golden query set with expected results instead of trusting
  individual answers. The tests caught three ranking bugs in code Claude had written.
- **Verification in the running app:** real HTTP calls for each status code, stopping the database to see the
  error path, and screenshots at desktop and mobile widths.

**Suggestions I changed or rejected:**
- **The DI container, rejected.** When I asked Claude to restructure the backend into routes, controllers,
  services and repositories, it also added a generic dependency-injection container and a `Models/` folder
  holding only the search-index class. I asked whether all of this was overkill. Claude agreed that the layers
  were worth keeping, since all SQL was now in one place and the logic was separate from HTTP, but that the
  container was ceremony for about eight objects that never change. I had it removed; objects are now wired by
  hand in `bootstrap/app.php`.
- **The `Models/` folder, changed.** I kept the folder but gave it its real meaning: a `Product` model that the
  repository returns and the API serializes.
- **Anthropic for embeddings, not possible.** My first preference was the Anthropic API, but it has no
  embeddings endpoint, so I went with OpenAI.
