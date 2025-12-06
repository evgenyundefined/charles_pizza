<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('telegram_states', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('step', 32);
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('telegram_states');
    }
};
