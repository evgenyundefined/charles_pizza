<?php
// database/migrations/2025_12_09_000000_add_reviews_to_slots.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('slots', function (Blueprint $table) {
            $table->unsignedTinyInteger('review_rating')->nullable()->after('comment');
            $table->text('review_text')->nullable()->after('review_rating');
            $table->timestamp('reviewed_at')->nullable()->after('review_text');
        });
    }
    
    public function down(): void
    {
        Schema::table('slots', function (Blueprint $table) {
            $table->dropColumn(['review_rating', 'review_text', 'reviewed_at']);
        });
    }
};
