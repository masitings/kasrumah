<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('spent_on');
            $table->string('merchant')->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('amount'); // rupiah, no decimals
            $table->string('category'); // see App\Support\Categories
            $table->enum('source', ['receipt', 'transfer', 'order', 'text', 'voice']);
            $table->enum('status', ['draft', 'confirmed'])->default('draft');
            $table->string('image_path')->nullable();
            $table->json('raw_extraction')->nullable(); // keep model output for debugging + the write-up
            $table->timestamps();

            $table->index(['user_id', 'spent_on']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->unsignedBigInteger('monthly_limit');
            $table->timestamps();
            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('expenses');
    }
};
