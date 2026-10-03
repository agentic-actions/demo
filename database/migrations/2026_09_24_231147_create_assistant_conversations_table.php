<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * laravel/ai's conversation store keeps a participant and no tenant, so this table maps one user in one team to the
     * board assistant's conversation. The package's agentic_conversations replaced it: 2026_09_26_112312 copies its
     * rows there and drops it.
     */
    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 36)->unique();
            $table->timestamps();

            $table->unique(['user_id', 'team_id']);
            $table->foreign('conversation_id')->references('id')->on('agent_conversations')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_conversations');
    }
};
