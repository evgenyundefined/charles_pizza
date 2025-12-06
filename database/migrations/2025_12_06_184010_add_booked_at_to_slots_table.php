<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('slots', function (Blueprint $table) {
            $table->timestamp('booked_at')
                ->nullable()
                ->after('comment');
        });
    }
    
    public function down(): void
    {
        Schema::table('slots', function (Blueprint $table) {
            $table->dropColumn('booked_at');
        });
    }
};
