# Kas Rumah

Household expense tracker (for the owner's wife). Snaps receipt / transfer screenshot / types text, local Gemma 3 via Ollama extracts to draft expense. Nothing leaves laptop.

## Stack
- Laravel 13, PHP 8.3+, Inertia v3 + React + TypeScript
- SQLite / MySQL, Fortify auth
- Ollama + `gemma3:4b` at `http://127.0.0.1:11434`
- Money = integer rupiah (no decimals)
- UI copy = casual Indonesian; code/comments/commits = English

## Commands
```bash
composer run dev                          # all-in-one dev
php artisan test --compact                # Pest suite (uses Http::fake)
vendor/bin/pint --format agent            # Pint formatting
npm run build                             # Vite build
php artisan kas:test-extract              # run extraction regression
php artisan kas:test-extract --set=tuning --runs=3
php artisan kas:test-extract --set=holdout --model=gemma3:4b
```

## Hard rules
- Never add cloud AI APIs. Gemma 3 local via Ollama only.
- Never commit test-set answers into prompts (Phase 2.1 lessons).
- Images downscaled via GD before Ollama (`ExpenseExtractor::prepareImage`).
- Struk photos stored `storage/app/private/receipts/{user_id}/`, served via auth route `/receipts/{expense}`.
- Every route behind `auth`.
- UI mobile-first: `resources/js/layouts/kas-layout.tsx` (bottom tab bar).
- Summary cached per user per week: invalidate on expense write (`SummaryCache::forget`).
- Sensitive fixture images gitignored in `tests/fixtures/extraction/.gitignore` (never commit real medicine receipts / bank balances).
