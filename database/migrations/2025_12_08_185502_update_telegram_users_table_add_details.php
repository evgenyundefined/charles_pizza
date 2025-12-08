<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            // username / first_name / last_name
            if (!Schema::hasColumn('telegram_users', 'username')) {
                $table->string('username')->nullable()->after('telegram_id');
            }
            
            if (!Schema::hasColumn('telegram_users', 'first_name')) {
                $table->string('first_name')->nullable()->after('username');
            }
            
            if (!Schema::hasColumn('telegram_users', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }
            
            // телефон
            if (!Schema::hasColumn('telegram_users', 'phone')) {
                $table->string('phone', 32)->nullable()->after('last_name');
            }
            
            // язык / премиум / бот
            if (!Schema::hasColumn('telegram_users', 'language_code')) {
                $table->string('language_code', 8)->nullable()->after('phone');
            }
            
            if (!Schema::hasColumn('telegram_users', 'is_premium')) {
                $table->boolean('is_premium')->default(false)->after('language_code');
            }
            
            if (!Schema::hasColumn('telegram_users', 'is_bot')) {
                $table->boolean('is_bot')->default(false)->after('is_premium');
            }
            
            // последний чат и активность
            if (!Schema::hasColumn('telegram_users', 'last_chat_id')) {
                $table->string('last_chat_id', 64)->nullable()->after('is_bot');
            }
            
            if (!Schema::hasColumn('telegram_users', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('last_chat_id');
            }
        });
    }
    
    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            if (Schema::hasColumn('telegram_users', 'username')) {
                $table->dropColumn('username');
            }
            if (Schema::hasColumn('telegram_users', 'first_name')) {
                $table->dropColumn('first_name');
            }
            if (Schema::hasColumn('telegram_users', 'last_name')) {
                $table->dropColumn('last_name');
            }
            if (Schema::hasColumn('telegram_users', 'phone')) {
                $table->dropColumn('phone');
            }
            if (Schema::hasColumn('telegram_users', 'language_code')) {
                $table->dropColumn('language_code');
            }
            if (Schema::hasColumn('telegram_users', 'is_premium')) {
                $table->dropColumn('is_premium');
            }
            if (Schema::hasColumn('telegram_users', 'is_bot')) {
                $table->dropColumn('is_bot');
            }
            if (Schema::hasColumn('telegram_users', 'last_chat_id')) {
                $table->dropColumn('last_chat_id');
            }
            if (Schema::hasColumn('telegram_users', 'last_seen_at')) {
                $table->dropColumn('last_seen_at');
            }
        });
    }
};
