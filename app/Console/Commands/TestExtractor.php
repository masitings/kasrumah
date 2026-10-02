<?php

namespace App\Console\Commands;

use App\Services\ExpenseExtractor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kas:test-extract {--dir=storage/app/test}')]
#[Description('Run the Gemma extractor over sample receipt images and typed expenses, then report numbers and speed.')]
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

        $images = $this->imageFiles($dir);
        $results = [];
        $rows = [];

        if ($images === []) {
            $this->warn("No .jpg/.jpeg/.png files in {$dir}. Running text samples only.");
        }

        foreach ($images as $path) {
            [$expenses, $seconds, $error] = $this->measure(
                fn () => $extractor->fromImage($path)
            );

            $results[] = $this->entry('image', basename($path), $expenses, $seconds, $error);
            $rows = [...$rows, ...$this->tableRows(basename($path), $expenses, $seconds, $error)];
        }

        foreach (self::TEXT_SAMPLES as $text) {
            [$expenses, $seconds, $error] = $this->measure(
                fn () => $extractor->fromText($text)
            );

            $results[] = $this->entry('text', $text, $expenses, $seconds, $error);
            $rows = [...$rows, ...$this->tableRows('"'.$text.'"', $expenses, $seconds, $error)];
        }

        $this->table(
            ['Input', 'Merchant', 'Amount', 'Category', 'Confidence', 'Seconds'],
            $rows,
        );

        $out = $dir.'/results.json';
        file_put_contents(
            $out,
            json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $this->info('Full results written to '.$out);

        return self::SUCCESS;
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
    private function entry(string $type, string $input, array $expenses, float $seconds, ?string $error): array
    {
        return [
            'type' => $type,
            'input' => $input,
            'seconds' => $seconds,
            'expenses' => $expenses,
            'error' => $error,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $expenses
     * @return array<int, array<int, string>>
     */
    private function tableRows(string $label, array $expenses, float $seconds, ?string $error): array
    {
        if ($expenses === []) {
            return [[
                $label,
                $error ? 'ERROR' : '-',
                '-',
                $error ? mb_strimwidth($error, 0, 40, '...') : '-',
                '-',
                number_format($seconds, 2),
            ]];
        }

        return array_map(fn (array $row) => [
            $label,
            (string) ($row['merchant'] ?? '-'),
            number_format((int) $row['amount'], 0, ',', '.'),
            (string) $row['category'],
            number_format((float) $row['confidence'], 2),
            number_format($seconds, 2),
        ], $expenses);
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
