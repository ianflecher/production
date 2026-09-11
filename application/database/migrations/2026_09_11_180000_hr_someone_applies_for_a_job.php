<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage one of the HR module: somebody applies.
 *
 * Everything HR owns lives in its own hr_ tables and its own /hr routes, so it
 * can be built and changed without any of it reaching the shop floor system.
 * Nothing here touches an existing table.
 *
 * The applicant is NOT a user account. They are a stranger who filled in a
 * public form; an account is only created if they are hired, which is stage
 * three. Until then they are a name, a way to contact them, and a photo taken
 * at the counter so whoever interviews them knows who walked in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_applicants', function (Blueprint $table) {
            $table->id();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('contact_number');
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->date('birthdate')->nullable();

            // What they are applying for, and anything they want to say.
            $table->string('position')->nullable();
            $table->text('about')->nullable();

            // Taken on the spot with the camera, or uploaded. Private storage:
            // a photo of a person is not something to serve from /public.
            $table->string('photo_path')->nullable();

            // Where they are in the process. Interviews are stage two, so for
            // now an applicant is only new, or put aside.
            $table->string('status')->default('new');

            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_applicants');
    }
};
