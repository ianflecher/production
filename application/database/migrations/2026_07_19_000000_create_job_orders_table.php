<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->enum('status', ['draft', 'sent_to_artist', 'completed'])->default('draft');

            // PRODUCTION (yellow)
            $table->string('print_type')->nullable();
            $table->string('printer')->nullable();
            $table->string('fabric')->nullable();
            $table->string('free_logo_sticker')->nullable();

            // SEWING (yellow)
            $table->string('neck')->nullable();
            $table->string('cuff_arm_sleeves')->nullable();
            $table->string('neck_label')->nullable();
            $table->string('bottom_hem')->nullable();
            $table->string('ic_placement')->nullable();

            // QUALITY CHECK (yellow)
            $table->string('packaging')->nullable();

            // SPECIAL INSTRUCTIONS / NOTES FROM AGENT
            $table->longText('special_instructions')->nullable();

            // Sign-offs
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_to_artist_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_to_artist_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_orders');
    }
};
