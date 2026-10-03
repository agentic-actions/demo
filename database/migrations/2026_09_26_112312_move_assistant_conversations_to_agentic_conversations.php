<?php

use App\Ai\Agents\BoardAssistant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the board assistant's conversations from the demo's own assistant_conversations table into the package's
 * agentic_conversations, which Actions::conversation() reads since agentic-actions 0.4.1. Every row is copied before
 * the old table is dropped, so each person keeps their chat in each team. down() moves the rows back.
 */
return new class extends Migration
{
    /**
     * Copy each (user, team, conversation) row as (participant, tenant, agent, conversation), then drop the old table.
     */
    public function up(): void
    {
        $participantType = (new User)->getMorphClass();
        $tenantType = (new Team)->getMorphClass();

        DB::table('assistant_conversations')->orderBy('id')->chunk(500, function (Collection $rows) use ($participantType, $tenantType): void {
            DB::table('agentic_conversations')->insert($rows->map(fn (object $row): array => [
                'participant_type' => $participantType,
                'participant_id' => $row->user_id,
                'tenant_type' => $tenantType,
                'tenant_id' => $row->team_id,
                'agent' => BoardAssistant::class,
                'conversation_id' => $row->conversation_id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->all());
        });

        Schema::drop('assistant_conversations');
    }

    /**
     * Recreate assistant_conversations as 2026_09_24_231147 made it, and move the board assistant's rows back.
     */
    public function down(): void
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

        $rows = DB::table('agentic_conversations')
            ->where('agent', BoardAssistant::class)
            ->where('participant_type', (new User)->getMorphClass())
            ->where('tenant_type', (new Team)->getMorphClass());

        (clone $rows)->orderBy('id')->chunk(500, function (Collection $chunk): void {
            DB::table('assistant_conversations')->insert($chunk->map(fn (object $row): array => [
                'user_id' => $row->participant_id,
                'team_id' => $row->tenant_id,
                'conversation_id' => $row->conversation_id,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->all());
        });

        $rows->delete();
    }
};
