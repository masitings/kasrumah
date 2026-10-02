<?php

use App\Models\Expense;
use App\Models\User;
use App\Services\ExpenseExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Fake the Ollama chat endpoint with a fixed extraction payload.
 */
function fakeOllama(array $rows): void
{
    Http::fake([
        '*/api/chat' => Http::response([
            'message' => [
                'content' => json_encode(['expenses' => $rows]),
            ],
        ], 200),
    ]);
}

function expenseRow(array $overrides = []): array
{
    return array_merge([
        'spent_on' => '2026-10-03',
        'merchant' => 'Indomaret',
        'description' => 'snack',
        'amount' => 17500,
        'category' => 'jajan',
        'source' => 'text',
        'confidence' => 0.95,
    ], $overrides);
}

test('text submit creates draft rows with the faked values', function () {
    fakeOllama([
        expenseRow(['merchant' => null, 'description' => 'sayur', 'amount' => 45000, 'category' => 'dapur']),
        expenseRow(['merchant' => null, 'description' => 'galon', 'amount' => 20000, 'category' => 'rumah']),
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/catat/text', [
        'text' => 'beli sayur 45rb sama galon 20rb',
    ]);

    $response->assertRedirect(route('review.index'));

    expect(Expense::where('user_id', $user->id)->where('status', 'draft')->count())->toBe(2);

    $this->assertDatabaseHas('expenses', [
        'user_id' => $user->id,
        'amount' => 45000,
        'category' => 'dapur',
        'status' => 'draft',
    ]);
});

test('image upload stores the file and creates exactly one draft', function () {
    Storage::fake('local');
    fakeOllama([
        expenseRow(['amount' => 25000, 'category' => 'kesehatan', 'merchant' => 'Apotek Gama']),
        expenseRow(['amount' => 50000, 'category' => 'tagihan', 'merchant' => 'M-BANKING']),
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/catat/image', [
        'image' => UploadedFile::fake()->image('struk.jpg', 800, 1200),
    ]);

    $response->assertRedirect(route('review.index'));

    $expenses = Expense::where('user_id', $user->id)->get();

    expect($expenses)->toHaveCount(1);
    expect($expenses->first()->amount)->toBe(25000);
    expect($expenses->first()->image_path)->not->toBeNull();

    Storage::disk('local')->assertExists($expenses->first()->image_path);
});

test('confirm and delete only work on the owners expenses', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $expense = Expense::create([
        'user_id' => $owner->id,
        'spent_on' => '2026-10-03',
        'amount' => 17500,
        'category' => 'jajan',
        'source' => 'text',
        'status' => 'draft',
    ]);

    $this->actingAs($intruder)->post("/review/{$expense->id}/confirm")->assertNotFound();
    $this->actingAs($intruder)->delete("/review/{$expense->id}")->assertNotFound();

    expect($expense->fresh()->status)->toBe('draft');

    $this->actingAs($owner)->post("/review/{$expense->id}/confirm")->assertRedirect();
    expect($expense->fresh()->status)->toBe('confirmed');

    $this->actingAs($owner)->delete("/review/{$expense->id}");
    $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
});

test('the summary page renders with faked ollama text', function () {
    Http::fake([
        '*/api/chat' => Http::response([
            'message' => ['content' => 'Minggu ini kamu belanja Rp100 rb, masih santai kok.'],
        ], 200),
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)->get('/summary')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('kas/summary')
            ->where('summary.text', 'Minggu ini kamu belanja Rp100 rb, masih santai kok.')
        );
});

test('the extractor maps an unknown category to lainnya and drops zero amounts', function () {
    fakeOllama([
        expenseRow(['amount' => 5000, 'category' => 'kategori-ngawur']),
        expenseRow(['amount' => 0, 'category' => 'dapur']),
    ]);

    $rows = app(ExpenseExtractor::class)->fromText('apa aja');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['category'])->toBe('lainnya');
    expect($rows[0]['amount'])->toBe(5000);
});

test('receipts show endpoint streams image for owner and rejects intruder', function () {
    Storage::fake('local');
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $path = 'receipts/'.$owner->id.'/test-receipt.jpg';
    Storage::disk('local')->put($path, 'fake-jpeg-bytes');

    $expense = Expense::create([
        'user_id' => $owner->id,
        'spent_on' => '2026-10-03',
        'amount' => 25000,
        'category' => 'kesehatan',
        'source' => 'receipt',
        'status' => 'confirmed',
        'image_path' => $path,
    ]);

    $this->actingAs($intruder)
        ->get("/receipts/{$expense->id}")
        ->assertNotFound();

    $response = $this->actingAs($owner)
        ->get("/receipts/{$expense->id}");

    $response->assertOk();
});
