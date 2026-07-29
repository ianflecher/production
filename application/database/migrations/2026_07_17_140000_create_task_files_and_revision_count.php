<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Files an agent/artist submits with their work (design samples, proofs).
        Schema::create('task_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedTinyInteger('round')->default(1); // 1 = first submission, 2+ = after revisions
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('revision_count')->default(0)->after('revision_note');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_files');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('revision_count');
        });
    }
};
