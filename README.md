# Smart Product Search

Search a 1,000-product catalog by exact name ("iPhone 16", "שואב אבק") or by describing a need
("מתנה למישהו שאוהב לבשל", "משהו שיעזור לנקות שערות של כלב מהספה"), in Hebrew, English, or both.

- **Live site:** https://search.signups.me/
- **Repository:** https://github.com/yaronpen/search_products_app/
- **Stack:** PHP 8.4 (no framework) · MySQL 8.4 · vanilla HTML/CSS/JS · OpenAI embeddings · Docker
- **Deep dive:** [docs/search.md](docs/search.md) covers the architecture, the ranking step by step, and every test and bug.
- **Git history:** the core app went into the single `initialize` commit, and later changes are separate commits.
  How the work actually progressed, including what was changed or rejected, is described under "Working with Claude".

## Running and loading the catalog

```bash
cp .env.example .env              # set DB passwords and OPENAI_API_KEY
docker compose up -d --build      # app on http://localhost:8080, MySQL on a persistent volume
docker compose exec app php /var/www/backend/bin/import.php   # load the CSV + compute embeddings
docker compose exec app php /var/www/backend/tests/run.php    # run the tests (--verbose for scores)
```

**The import** creates the schema, validates every row, and upserts by id inside a transaction. It's safe to
re-run, and products missing from the CSV are removed. It only re-embeds products whose text changed: the full
catalog costs about $0.02, and an unchanged re-import costs nothing. Without `OPENAI_API_KEY` the app still works,
keyword-only. Add `?debug` to any API search (`/api/search?q=iphone&debug`) to see each result's scores.

## Key decisions

| Decision | Why |
|---|---|
| **Hybrid search: keywords + embeddings** | Keywords alone failed the need-based queries. Measured: "מתנה למישהו שאוהב לבשל" returned two gift boxes, and the dog-hair query returned nothing. Keywords stay because they make names, brands and models ("ps5", "Air Fryer") land precisely. |
| **The query's precision decides the mix** | Precise queries ("iphone", "מחבת") lean on keywords and return only close matches: "iPhone" gives the three Apple phones, not every smartphone. Descriptive queries lean on meaning and return a broader list. Alternatives only when asked: "אייפון ומוצרים דומים" gives the Apple phones, then the other smartphones. |
| **OpenAI `text-embedding-3-large`, 512 dims** | Anthropic has no embeddings API. `3-small` failed the golden tests on Hebrew and English↔Hebrew queries; `3-large` passed at the same latency. Model, dimensions and timeout are set in `.env`. |
| **Whole catalog in memory (APCu), embeddings stored in MySQL** | 1,000 products need no search server: scoring everything takes about 20 ms, and it runs on any PHP host. Product embeddings are computed once at import; query embeddings are cached, so a repeated search never calls the API. |
| **Hand-curated aliases** | The catalog never says "iPhone": it lists "סמארטפון חכם 6.1 אינץ'" under Apple. A small table maps `אייפון → iphone → Apple + סמארטפון` and Hebrew brand spellings to the English brand names. |
| **Graceful degradation** | If OpenAI is slow or down (3 s timeout), search falls back to keywords with a small notice, never an error. |
| **Plain PHP, thin MVC** (routes → controller → service → repository) | One endpoint doesn't justify Laravel. All SQL lives in repositories and all logic in services, with no Composer dependency to install. |
| **Estimated prices** | The CSV has no prices, but every result needs one. Each product gets a stable price from a realistic ILS range for its subcategory. A `price` column in the CSV would be used instead. |

## How I tested it

- **58 automated tests** (`backend/tests/run.php`):
  - Hebrew normalization, stemming and aliases.
  - API input validation.
  - **23 golden queries** with expected results. Ranking weights were tuned against these, not by eyeballing.
  - The keyword-only fallback passes its 52 (the 6 semantic cases are skipped there).
- **Manual:** each HTTP status (400/404/405, and a clean 500 with the database stopped), plus RTL layout and the
  loading, empty and error states at desktop and mobile widths.
- **Speed:** about 30 ms per search once a query's vector is cached; about 250 ms the first time (the OpenAI call).

| Query | Top results |
|---|---|
| `שואב אבק` | 15 vacuums: upright, wet/dry, car, handheld, robot |
| `iPhone 16` / `iphone` / `אייפון` | exactly the three Apple smartphones |
| `אייפון ומוצרים דומים` | the three Apple phones, then the other 10 smartphones |
| `מתנה למישהו שאוהב לבשל` | multi-cooker, fondue pot, BBQ tool set, soup pot, cast-iron pot, tagine |
| `משהו שיעזור לנקות שערות של כלב מהספה` | pet carpet cleaner, dog bed (off target), grooming clipper, steam cleaner, upholstery cleaner, pet-hair brush |
| `something to help me sleep better` | aromatherapy diffuser, weighted blanket, sleep earphones, white-noise machine |
| `qwxzkj` | the "no results" state |

## Known limitations and next steps

**Search quality**
- **Descriptive queries are good, not perfect.** For the dog-hair query, a dog bed ranks 2nd and the pet-hair brush
  6th, because the embedding weighs "dog" heavily. Next: have an LLM rewrite descriptive queries into product
  types ("handheld vacuum, pet-hair remover") before embedding, cached per query.
- **Single Hebrew words are noise to the embedding model.** For "מחבת" its top match is a board game; keywords carry
  these queries. Hebrew handling is light stemming, not a real morphological analyzer. Next: a Hebrew analyzer
  (e.g. Hspell-based) or a multilingual model fine-tuned on Hebrew product text.
- **No typo tolerance** beyond word prefixes. Next: trigram or edit-distance matching.
- **Hand-written lists:** aliases, filler words and "similar" trigger words. "משהו כמו אייפון" isn't recognized as a
  request for alternatives. Next: learn them from search logs.
- **Ranking constants are tuned on 23 golden queries,** so they may be overfitted to them. Next: a larger labelled
  set, and click data to measure real relevance.
- **Alternatives only on request, unlabelled.** Next: a labelled "מוצרים דומים" section under precise searches.

**Performance and cost**
- **A query's first search waits for OpenAI** (about 0.5–1.5 s on the live server); repeats take about 45 ms.
  Search-as-you-type also embeds partial words ("שוא"), each a first-time call. Next: wait for a longer pause
  before semantic search, and use keywords alone for very short input.
- **Brute-force scoring in PHP memory** is fine for 1,000 products, not for 100,000. Next: an ANN index (pgvector,
  OpenSearch or a vector DB).
- **No rate limiting.** Each new query costs one OpenAI call. Next: rate limiting at the proxy, plus a spending cap.
- **Depends on OpenAI.** Without it the app falls back to keywords, and descriptive queries stop working.

**Operations and code**
- **One EC2 instance** runs the app, MySQL and Caddy, so there are no backups, no failover and no monitoring. It's
  also in a US region, adding about 300 ms per request from Israel. Next: managed MySQL (RDS) with backups,
  monitoring, and the Tel Aviv region.
- **Home-grown test runner, no CI.** Tests hit the real DB and API, so they're not hermetic. Next: PHPUnit with a
  mocked embeddings client, run on every push.
- **Prices are estimated, images are the CSV's placeholders.** Left out on purpose: filters, sorting, pagination.

## Working with Claude

I used Claude Code throughout. It explored the CSV, wrote most of the code, and ran and tested it in Docker. I
reviewed its output, challenged its proposals, and decided what to keep.

**How I checked its output:**
- **Golden tests over individual answers.** I asked for a set of test queries with expected results, and every
  ranking change had to pass them in both modes. They caught regressions that looked fine in isolation: fixing
  "iPhone" first dropped robot vacuums from "שואב אבק", then collapsed the cooking query to a single result.
- **Measuring instead of trusting explanations.** When "iPhone" returned other brands, Claude blamed the model
  switch and said `3-large` produces higher similarity scores. I had it measure first: the scores were actually
  *lower*. The real cause was the alias text added to the query, and the fix followed from the data.
- **Using the app myself.** I found issues the tests didn't cover yet:
  - "iPhone" blended other brands into its results.
  - "אייפון ומוצרים דומים" returned a translator and a charger.

  Each fix came with a new golden test.
- **Asking it to attack its own code.** I asked whether the API validates input. Its probes found three real bugs:
  invalid UTF-8 returned an empty 200, a non-numeric `limit` returned 1 result, and control characters were
  accepted. All three are fixed and covered by tests.

**Suggestions I changed or rejected:**
- **The backend structure: changed, at my insistence.** Claude's first version was flat: a single `search.php`
  endpoint, with SQL spread across the catalog loader, the search service and the import script. I asked for a
  layered structure: routes → controller → services → repositories. Now all SQL lives in repositories, all logic in
  services, and the controller handles HTTP only. That also made later changes easy to place. For example, the
  input validation fixes went into the controller without touching the search logic.
- **The DI container: rejected.** During that restructure Claude also added a generic DI container and a `Models/`
  folder holding only an index class. I asked whether that was overkill. It agreed the layers were worth keeping but
  the container wasn't, for eight objects that never change. Wiring is now plain `new` calls in `bootstrap/app.php`.
- **`Models/`: changed.** I kept the folder but gave it a real `Product` model, which the repository returns and
  the API serializes.
- **Hardcoded settings: changed.** The OpenAI URL, model, dimensions and timeout were constants. I moved them to `.env`,
  and kept the ranking constants in code, because they're tuned against the tests.
