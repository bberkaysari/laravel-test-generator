<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('content');
            $table->string('slug')->unique();
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->integer('view_count')->unsigned()->default(0);
            $table->decimal('rating', 3, 2)->nullable();
            $table->timestamps();

            $table->index('published_at');
            $table->index(['user_id', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
