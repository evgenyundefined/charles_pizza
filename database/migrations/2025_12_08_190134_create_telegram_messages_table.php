<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            
            // кто
            $table->unsignedBigInteger('telegram_id')->nullable()->index();
            $table->string('chat_id', 64)->index();
            
            // направление: in / out
            $table->string('direction', 10)->default('in'); // in / out
            
            // тип: message / callback / system и т.п.
            $table->string('type', 20)->nullable();
            
            // ID сообщения в Telegram (если есть)
            $table->unsignedBigInteger('message_id')->nullable();
            
            // «видимый» текст (text или data)
            $table->text('text')->nullable();
            
            // сырой payload от Telegram / params sendMessage
            $table->json('payload')->nullable();
            
            $table->timestamps(); // created_at / updated_at
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
    }
};
