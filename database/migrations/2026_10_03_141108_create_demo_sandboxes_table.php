<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A hosted visitor's sandbox, and the users and teams stamped with it. The stamp has no foreign key on purpose:
     * teams soft-delete, so the purge finds a sandbox's rows by this column and deletes them itself.
     */
    public function up(): void
    {
        Schema::create('demo_sandboxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('visitor_id')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('demo_sandbox_id')->nullable()->index();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedBigInteger('demo_sandbox_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['demo_sandbox_id']);
            $table->dropColumn('demo_sandbox_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['demo_sandbox_id']);
            $table->dropColumn('demo_sandbox_id');
        });

        Schema::dropIfExists('demo_sandboxes');
    }
};
