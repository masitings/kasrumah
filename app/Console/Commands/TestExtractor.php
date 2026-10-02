<?php

namespace App\Console\Commands;

use App\Services\ExpenseExtractor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kas:test-extract {--dir=storage/app/test}')]
#[Description('Run the Gemma extractor over sample images and typed expenses, compare against expected.json, then report numbers and speed.')]
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
        $dir = $this->resolveDir((string) $this->option('dir'));

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $expected = $this->expected($dir);
        $images = $this->imageFiles($dir);
        $results = [];
        $rows = [];
        $passed = 0;
        $failed = 0;

        if ($images === []) {
            $this->warn("No .jpg/.jpeg/.png files in {$dir}. Running text samples only.");
        }

        foreach ($images as $path) {
            [$expenses, $seconds, $error] = $this->measure(
                fn () => $extractor->fromImage($path)
            );

            $status = $this->compare($expected[basename($path)] ?? null, $expenses, $error);

            if ($status === 'PASS') {
                $passed++;
            } elseif ($status !== '— (no expectation)') {
                $failed++;
            }

            $results[] = $this->entry('image', basename($path), $expenses, $seconds, $error, $status);
            $rows = [...$rows, ...$this->tableRows(basename($path), $expenses, $seconds, $error, $status)];
        }

        foreach (self::TEXT_SAMPLES as $text) {
            [$expenses, $seconds, $error] = $this->measure(
                fn () => $extractor->fromText($text)
            );

            $status = $this->compare($expected[$text] ?? null, $expenses, $error);

            if ($status === 'PASS') {
                $passed++;
            } elseif ($status !== '— (no expectation)') {
                $failed++;
            }

            $results[] = $this->entry('text', $text, $expenses, $seconds, $error, $status);
            $rows = [...$rows, ...$this->tableRows('"'.$text.'"', $expenses, $seconds, $error, $status)];
        }

        $this->table(
            ['Input', 'Merchant', 'Amount', 'Category', 'Confidence', 'Secs', 'Status'],
            $rows,
        );

        $out = $dir.'/results.json';
        file_put_contents(
            $out,
            json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $this->info("Regression: {$passed} PASS, {$failed} FAIL");
        $this->info('Full results written to '.$out);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function expected(string $dir): array
    {
        $file = $dir.'/expected.json';

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

        if ($expected === null) {
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

            if ((int) $exp['amount'] !== (int) $got['amount']) {
                $fails[] = "#{$i} amount expected {$exp['amount']}, got {$got['amount']}";
            }

            if (($exp['category'] ?? null) !== ($got['category'] ?? null)) {
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
     * @return array<string, mixed>
     */
    private function entry(string $type, string $input, array $expenses, float $seconds, ?string $error, string $status): array
    {
        return [
            'type' => $type,
            'input' => $input,
            'seconds' => $seconds,
            'expenses' => $expenses,
            'error' => $error,
            'status' => $status,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $expenses
     * @return array<int, array<int, string>>
     */
    private function tableRows(string $label, array $expenses, float $seconds, ?string $error, string $status): array
    {
        if ($expenses === []) {
            return [[
                $label,
                $error ? 'ERROR' : '-',
                '-',
                '-',
                '-',
                number_format($seconds, 2),
                $status,
            ]];
        }

        $rows = [];
        $last = count($expenses) - 1;

        foreach ($expenses as $i => $row) {
            $rows[] = [
                $label,
                (string) ($row['merchant'] ?? '-'),
                number_format((int) $row['amount'], 0, ',', '.'),
                (string) $row['category'],
                number_format((float) $row['confidence'], 2),
                number_format($seconds, 2),
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

    private function resolveDir(string $dir): string
    {
        return str_starts_with($dir, DIRECTORY_SEPARATOR) ? $dir : base_path($dir);
    }
}
