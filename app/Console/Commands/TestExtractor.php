<?php

namespace App\Console\Commands;

use App\Services\ExpenseExtractor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kas:test-extract {--set=all : Which fixture set to run (tuning, holdout, all)} {--model= : Override Ollama model for this run} {--runs=1 : Number of runs per case (PASS only if all runs pass)} {--dir= : Custom directory to scan (overrides --set)}')]
#[Description('Run the Gemma extractor against extraction fixtures with tuning/holdout splits and multi-run consensus.')]
class TestExtractor extends Command
{
    /** @var list<string> */
    private const TEXT_SAMPLES = [
        'beli sayur 45rb sama galon 20rb',
        'bayar listrik 350 ribu',
        'grab ke kantor 28rb',
        'kondangan 200rb',
    ];

    public function handle(ExpenseExtractor $extractor): int
    {
        $set = strtolower((string) $this->option('set'));
        $model = $this->option('model') ? (string) $this->option('model') : null;
        $runs = max(1, (int) $this->option('runs'));
        $customDir = $this->option('dir');
        $effectiveModel = $model ?: (string) config('services.ollama.model');

        $this->info("Running extraction suite [set={$set}, model={$effectiveModel}, runs={$runs}]");

        $cases = $customDir
            ? $this->loadCustomDir((string) $customDir)
            : $this->loadFixtureSets($set);

        if ($cases === []) {
            $this->warn('No test cases found.');

            return self::SUCCESS;
        }

        $summary = [
            'tuning' => ['pass' => 0, 'total' => 0],
            'holdout' => ['pass' => 0, 'total' => 0],
            'text' => ['pass' => 0, 'total' => 0],
            'image_seconds' => [],
            'text_seconds' => [],
        ];

        $tableRows = [];
        $failedCases = [];

        foreach ($cases as $case) {
            $runResults = [];
            $runErrors = [];
            $allPassed = true;
            $firstExpenses = [];

            for ($r = 1; $r <= $runs; $r++) {
                [$expenses, $seconds, $error] = $case['type'] === 'image'
                    ? $this->measure(fn () => $extractor->fromImage($case['path'], $model))
                    : $this->measure(fn () => $extractor->fromText($case['input'], $model));

                if ($r === 1) {
                    $firstExpenses = $expenses;
                }

                if ($case['type'] === 'image') {
                    $summary['image_seconds'][] = $seconds;
                } else {
                    $summary['text_seconds'][] = $seconds;
                }

                $status = $this->compare($case['expected'], $expenses, $error);
                $runResults[] = $status;

                if ($status !== 'PASS') {
                    $allPassed = false;
                    $runErrors[] = "run {$r}: {$status}";
                }
            }

            $setKey = $case['set'];
            $summary[$setKey]['total']++;

            if ($allPassed) {
                $summary[$setKey]['pass']++;
            } else {
                $failedCases[] = [
                    'set' => $setKey,
                    'input' => $case['label'],
                    'expected' => $case['expected'],
                    'got' => $firstExpenses,
                    'reasons' => $runErrors,
                ];
            }

            $passCount = count(array_filter($runResults, fn ($s) => $s === 'PASS'));
            $statusLabel = $allPassed
                ? ($runs > 1 ? "PASS ({$runs}/{$runs})" : 'PASS')
                : "FAIL ({$passCount}/{$runs})";

            $tableRows = [...$tableRows, ...$this->formatRows($case['label'], $setKey, $firstExpenses, $statusLabel)];
        }

        $this->table(
            ['Set', 'Input', 'Merchant', 'Amount', 'Category', 'Conf', 'Status'],
            $tableRows,
        );

        $this->newLine();
        $this->info('--- Summary ---');

        foreach (['tuning', 'holdout', 'text'] as $k) {
            $t = $summary[$k]['total'];
            $p = $summary[$k]['pass'];

            if ($t > 0) {
                $pct = number_format(($p / $t) * 100, 1);
                $this->line(sprintf('  %-8s : %d/%d passed (%s%%)', ucfirst($k), $p, $t, $pct));
            }
        }

        $avgImg = count($summary['image_seconds']) > 0
            ? number_format(array_sum($summary['image_seconds']) / count($summary['image_seconds']), 2)
            : '0.00';
        $avgTxt = count($summary['text_seconds']) > 0
            ? number_format(array_sum($summary['text_seconds']) / count($summary['text_seconds']), 2)
            : '0.00';

        $this->line("  Avg speed: {$avgImg}s / image, {$avgTxt}s / text");

        if ($failedCases !== []) {
            $this->newLine();
            $this->warn('--- Failures Detail ---');

            foreach ($failedCases as $f) {
                $this->line("• [{$f['set']}] {$f['input']}");
                $this->line('  Reasons: '.implode(' | ', $f['reasons']));
                $this->line('  Got: '.json_encode($f['got'], JSON_UNESCAPED_SLASHES));
            }
        }

        file_put_contents(
            base_path('storage/app/extraction-results.json'),
            json_encode([
                'model' => $effectiveModel,
                'runs' => $runs,
                'set' => $set,
                'summary' => [
                    'tuning' => $summary['tuning'],
                    'holdout' => $summary['holdout'],
                    'text' => $summary['text'],
                    'avg_image_seconds' => (float) $avgImg,
                    'avg_text_seconds' => (float) $avgTxt,
                ],
                'failures' => $failedCases,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return $failedCases === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{type: string, set: string, input: string, path: string, label: string, expected: array|null}>
     */
    private function loadFixtureSets(string $set): array
    {
        $base = base_path('tests/fixtures/extraction');
        $cases = [];

        if (in_array($set, ['tuning', 'all'], true)) {
            $cases = [...$cases, ...$this->loadFromDir($base.'/tuning', 'tuning')];

            $expectedTuning = $this->readExpected($base.'/tuning/expected.json');

            foreach (self::TEXT_SAMPLES as $text) {
                $cases[] = [
                    'type' => 'text',
                    'set' => 'text',
                    'input' => $text,
                    'path' => '',
                    'label' => '"'.$text.'"',
                    'expected' => $expectedTuning[$text] ?? null,
                ];
            }
        }

        if (in_array($set, ['holdout', 'all'], true)) {
            $cases = [...$cases, ...$this->loadFromDir($base.'/holdout', 'holdout')];
        }

        return $cases;
    }

    /**
     * @return list<array{type: string, set: string, input: string, path: string, label: string, expected: array|null}>
     */
    private function loadFromDir(string $dir, string $setName): array
    {
        if (! is_dir($dir)) {
            $this->warn("Directory not found: {$dir}");

            return [];
        }

        $expected = $this->readExpected($dir.'/expected.json');
        $cases = [];

        foreach ($this->imageFiles($dir) as $path) {
            $filename = basename($path);
            $cases[] = [
                'type' => 'image',
                'set' => $setName,
                'input' => $filename,
                'path' => $path,
                'label' => $filename,
                'expected' => $expected[$filename] ?? null,
            ];
        }

        // Expected images that are absent on disk (e.g. gitignored sensitive photos).
        foreach ($expected as $key => $value) {
            if (! str_contains($key, '.')) {
                continue;
            }

            if (! collect($cases)->contains(fn (array $c) => $c['input'] === $key)) {
                $this->line("<comment>Note: {$key} listed in {$setName}/expected.json but missing on disk (skipped).</comment>");
            }
        }

        return $cases;
    }

    /**
     * @return list<array{type: string, set: string, input: string, path: string, label: string, expected: array|null}>
     */
    private function loadCustomDir(string $dir): array
    {
        $real = str_starts_with($dir, DIRECTORY_SEPARATOR) ? $dir : base_path($dir);
        $cases = $this->loadFromDir($real, 'custom');

        $expected = $this->readExpected($real.'/expected.json');

        foreach (self::TEXT_SAMPLES as $text) {
            $cases[] = [
                'type' => 'text',
                'set' => 'text',
                'input' => $text,
                'path' => '',
                'label' => '"'.$text.'"',
                'expected' => $expected[$text] ?? null,
            ];
        }

        return $cases;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function readExpected(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        return json_decode((string) file_get_contents($file), true) ?? [];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $expected
     * @param  array<int, array<string, mixed>>  $expenses
     */
    private function compare(?array $expected, array $expenses, ?string $error): string
    {
        if ($error !== null) {
            return 'FAIL — '.$error;
        }

        if ($expected === null || $expected === []) {
            return '— (no expectation)';
        }

        $fails = [];

        if (count($expected) !== count($expenses)) {
            $fails[] = 'row count expected '.count($expected).', got '.count($expenses);
        }

        foreach ($expected as $i => $exp) {
            $got = $expenses[$i] ?? null;

            if ($got === null) {
                continue;
            }

            if (isset($exp['amount']) && (int) $exp['amount'] !== (int) $got['amount']) {
                $fails[] = "#{$i} amount expected {$exp['amount']}, got {$got['amount']}";
            }

            if (isset($exp['category']) && $exp['category'] !== ($got['category'] ?? null)) {
                $fails[] = "#{$i} category expected {$exp['category']}, got {$got['category']}";
            }

            if (! empty($exp['merchant'])) {
                $needle = mb_strtolower((string) $exp['merchant']);
                $haystack = mb_strtolower((string) ($got['merchant'] ?? ''));

                if (! str_contains($haystack, $needle)) {
                    $fails[] = "#{$i} merchant expected ~\"{$exp['merchant']}\", got \"{$got['merchant']}\"";
                }
            }
        }

        return $fails === [] ? 'PASS' : 'FAIL — '.implode('; ', $fails);
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: float, 2: string|null}
     */
    private function measure(callable $callback): array
    {
        $start = microtime(true);

        try {
            $expenses = $callback();
            $error = null;
        } catch (\Throwable $e) {
            $expenses = [];
            $error = $e->getMessage();
        }

        return [$expenses, round(microtime(true) - $start, 2), $error];
    }

    /**
     * @param  array<int, array<string, mixed>>  $expenses
     * @return array<int, array<int, string>>
     */
    private function formatRows(string $label, string $set, array $expenses, string $status): array
    {
        if ($expenses === []) {
            return [[$set, $label, '-', '-', '-', '-', $status]];
        }

        $rows = [];
        $last = count($expenses) - 1;

        foreach ($expenses as $i => $row) {
            $rows[] = [
                $i === 0 ? $set : '',
                $i === 0 ? $label : '',
                (string) ($row['merchant'] ?? '-'),
                number_format((int) $row['amount'], 0, ',', '.'),
                (string) $row['category'],
                number_format((float) $row['confidence'], 2),
                $i === $last ? $status : '-',
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function imageFiles(string $dir): array
    {
        $files = [];

        foreach (['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'] as $extension) {
            foreach (glob($dir.'/*.'.$extension) ?: [] as $file) {
                $files[$file] = $file;
            }
        }

        ksort($files);

        return array_values($files);
    }
}
