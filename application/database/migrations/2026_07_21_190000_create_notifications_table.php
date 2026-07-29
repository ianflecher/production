<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Desktop alerts. Targeted at a specific user, or at a whole job role
        // (e.g. every supply-chain account) when any one of them can act.
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('role', 30)->nullable();     // job_role target when user_id is null
            $table->string('title');
            $table->string('body')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('delivered_at')->nullable();  // shown on someone's screen
            $table->timestamps();

            $table->index(['user_id', 'delivered_at']);
            $table->index(['role', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
