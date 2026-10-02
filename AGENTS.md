# Kas Rumah — Agent Guidelines

Household expense tracker (for the owner's wife). Local Gemma 3 via Ollama reads receipts / transfer screenshots / typed notes into categorized expenses. Zero cloud AI, nothing leaves the laptop.

## Project Rules & Invariants

- **Local AI only**: Gemma via Ollama (`gemma3:4b`) at `http://127.0.0.1:11434`. Never add cloud AI APIs.
- **No test-set leakage in prompts**: Phase 2 passed 7/7 only because the prompt contained the fixtures' answers; Phase 2.1 removed it. Keep `ExpenseExtractor` prompts general — no literal amounts, store names, or product names from `tests/fixtures`.
- **Money**: integer rupiah, no decimals. Format in UI with `resources/js/lib/format.ts` (`Rp17.500`).
- **Language**: UI copy casual Indonesian; code, comments, commits English.
- **Receipts**: stored `storage/app/private/receipts/{user_id}/`; served only via the auth-checked `GET /receipts/{expense}` route. Never expose them publicly.
- **Sensitive fixtures**: `tests/fixtures/extraction/.gitignore` keeps real medicine receipts / bank balances local. Never commit them.
- **Summary cache**: per user per week (`summary:{user_id}:{weekStart}`); call `SummaryCache::forget($user)` on any expense create/update/confirm/delete.
- **All routes behind `auth`** (`routes/web.php`). `/` is the Catat page after login.
- **Testing**: Pest (`php artisan test --compact`). Any test touching Ollama must use `Http::fake()` — never hit the real model.
- **After edits**: `vendor/bin/pint --format agent` for PHP; `npm run build` for frontend.

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel 13 application running on PHP 8.4+. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain — don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.

=== inertia-react/core rules ===

# Inertia + React

- Pages live in `resources/js/pages/`; the layout is resolved in `resources/js/app.tsx`.
- Kas Rumah's mobile shell is `resources/js/layouts/kas-layout.tsx`, applied automatically to every `kas/*` page.
- Reuse `resources/js/components/ui/*` primitives before writing new ones.
- Prefer named routes/Wayfinder for links.

=== pint/core rules ===

# Laravel Pint Code Formatter

- Run `vendor/bin/pint --format agent` on modified PHP files before finalizing.

=== pest/core rules ===

# Pest

- Feature tests in `tests/Feature/`; unit tests in `tests/Unit/`.
- `Http::fake()` is mandatory for any Ollama call.
- Run the narrowest set first (`php artisan test --compact --filter=...`), then the full suite.

</laravel-boost-guidelines>
