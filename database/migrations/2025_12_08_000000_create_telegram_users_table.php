<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('telegram_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->unique(); // id из Telegram
            $table->string('username')->nullable();              // @username без @
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();          // то, что удобно показывать
            $table->string('language_code', 10)->nullable();     // ru, en и т.п.
            $table->boolean('is_bot')->default(false);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('telegram_users');
    }
};
