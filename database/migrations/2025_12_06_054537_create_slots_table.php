<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->dateTime('slot_time');
            $table->unsignedBigInteger('booked_by')->nullable();
            $table->string('booked_username')->nullable();
            $table->timestamps();
            
            $table->index('slot_time');
            $table->index('booked_by');
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
