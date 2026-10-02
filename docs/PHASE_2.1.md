# Task: Kas Rumah Phase 2.1 (remove prompt overfit, measure honestly)

The Phase 2 regression passed 7/7, but only because the image prompt now contains the test answers (`25000`, `500000`, `17500`, "Apotek Gama", "Big Apple", "Indomaret", "KINDER", "GLOBAL MANSION", and store-to-category mappings written for those three receipts). That is a leaked test set, not a working extractor. This phase fixes that and produces honest numbers for the write-up.

Commit after each step. Do not touch the UI.

## Step 1: Generalize the image prompt

Edit `ExpenseExtractor::fromImage()` prompt:

Remove:

- every literal number from the test receipts
- every merchant name or product name that appears in the test receipts (Apotek Gama, Big Apple, Indomaret, Kinder, Global Mansion, etc.)
- any store-specific rule ("struk dengan logo Indomaret → ...")

Keep (these are general and fine):

- amount is the TOTAL / GRAND TOTAL / TOTAL HARGA line, never the cash handed over or the payment method line
- merchant is the short brand name from the logo or header, never the address, branch, or an item name
- generic category hints by store TYPE, written without real brand names, e.g. "apotek/klinik → kesehatan", "minimarket/supermarket: lihat isi belanja, makanan ringan → jajan, bahan masak → dapur, sabun/galon → rumah"
- the field order you found helps (merchant, then category, then amount)

If you want few-shot examples, invent them with made-up stores and numbers that do not appear in any test image (e.g. "Toko Maju Jaya, TOTAL 63.200").

Commit: `Generalize image prompt, remove test-set leakage`.

## Step 2: Split fixtures into tuning and held-out

1. Move fixtures out of the ignored folder to `tests/fixtures/extraction/`:
   - `tuning/` : the 3 Phase 1 receipts + their `expected.json`
   - `holdout/` : every other image (`IMG_1830.PNG` Jago transfer, the ShopeeFood screenshot, and any new ones the owner adds) + their own `expected.json`
2. Ask the owner (stop and print the list) for the correct `amount`, `category`, and a merchant substring for each holdout image that has no expectation yet. Do not guess them.
3. Update `kas:test-extract` with `--set=tuning|holdout|all` and `--model=` to override `OLLAMA_MODEL` for one run. Also add `--runs=3`: run each case N times and report a case as PASS only if all runs pass (Gemma output can vary even at temperature 0 with images).
4. Do NOT commit `IMG_1906.jpg` (pharmacy receipt with a medicine name) or any image showing an account number or balance. Keep those local and list them in `tests/fixtures/extraction/.gitignore`; the command should skip missing files with a note.

Commit: `Move extraction fixtures, add tuning/holdout sets`.

## Step 3: Measure

Rule: you may edit the prompt while looking at `tuning` results only. Run `holdout` exactly once per model at the end and do not change the prompt after seeing it.

Run:

```bash
php artisan kas:test-extract --set=all --runs=3 --model=gemma3:4b
ollama pull gemma3:12b
php artisan kas:test-extract --set=all --runs=3 --model=gemma3:12b

```

## Report back

A table per model: tuning pass rate, holdout pass rate, average seconds per image, average seconds per text, and every failing case with the wrong value. Note the laptop's RAM and chip. Recommend nothing; just report.