<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            if (!Schema::hasColumn('telegram_users', 'language')) {
                $table->string('language', 5)
                    ->default('ru')
                    ->after('phone');
            }
        });
    }
    
    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            if (Schema::hasColumn('telegram_users', 'language')) {
                $table->dropColumn('language');
            }
        });
    }
};