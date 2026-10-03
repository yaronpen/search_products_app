# Smart Product Search

Hybrid product search (keywords + OpenAI embeddings) over a 1,000-product Hebrew/English catalog.
PHP 8.4 with no framework and no Composer, MySQL 8.4, vanilla JS, Docker. `docs/search.md` explains the ranking.

## Commands

Everything runs inside the `app` container. Paths in commands are container paths.

```bash
docker compose up -d --build                                          # app on http://localhost:8080
docker compose exec app php /var/www/backend/bin/import.php           # load CSV + embeddings (idempotent)
docker compose exec app php /var/www/backend/tests/run.php            # all tests; --verbose shows scores
docker compose exec -e OPENAI_API_KEY= app php /var/www/backend/tests/run.php   # keyword-only fallback mode
curl -s -G localhost:8080/api/search --data-urlencode 'q=אייפון' --data-urlencode debug   # score breakdown
```

## Layout and conventions

- Request flow: `backend/public/index.php` → `routes/api.php` → `Controllers/` → `Services/` → `Repositories/`.
- `bootstrap/app.php` wires everything by hand with `new`. There is no DI container, on purpose; keep it that way.
- **All SQL lives in `Repositories/`.** All logic lives in `Services/`. Controllers handle HTTP only.
- `Models/Product::jsonSerialize()` is the API's result shape.
- Settings come from environment variables via `Core/Config` (Docker loads `.env`). Never commit `.env` or keys.
- Don't add a framework or Composer packages; the app must run on plain PHP hosting.

## Rules for changes

- **Run the tests after every backend change, in both modes** (with and without the API key). All must pass.
- **Every relevance change gets a golden test** in the `$cases` array in `backend/tests/run.php`: the query, whether
  it needs semantic search, and a check on the results. Write the expected outcome first, then make it pass.
- **Ranking constants in `SearchService` are tuned against the golden tests.** Change them only with the tests
  running, and check queries of both kinds: precise ("iphone", "מחבת", "ps5") and descriptive ("מתנה למישהו שאוהב
  לבשל", the dog-hair query). Fixes for one kind have repeatedly broken the other.
- **Measure before explaining ranking behaviour.** Use `?debug` or `--verbose`, or compute raw cosines. Guessed
  explanations have been wrong here before.
- Keep `README.md` short. Detail goes in `docs/search.md`.

## Gotchas

- PHP opcache revalidates every 2 s, so a request made right after an edit may run the old code.
- After changing `.env`, run `docker compose up -d --force-recreate app`; a plain restart won't reload `env_file`.
- After changing the embeddings model or dimensions, re-run the import. Stored vectors are tied to the model
  through a hash, and the import re-embeds everything automatically.
- The search index is cached in APCu per catalog version. Each import bumps the version, so no restart is needed.
- Tests use the real DB and API key. A never-seen query costs one embedding call, then it's cached in MySQL.
- Headless Chrome screenshots have a minimum width of about 500 px; narrower windows clip.
