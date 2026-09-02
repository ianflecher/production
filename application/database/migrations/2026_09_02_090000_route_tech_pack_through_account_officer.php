<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('officer_approved_by')->nullable()->after('approver_role')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('officer_approved_at')->nullable()->after('officer_approved_by');
        });

        // Open Tech Packs enter the new first review at their account officer.
        // Completed/cancelled history keeps the approver it had at the time.
        DB::table('tasks')
            ->where(function ($query) {
                $query->where('department', 'like', 'Tech pack%')
                    ->orWhere('department', 'like', 'Production template%');
            })
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->update([
                'approver_role' => 'sales',
                'officer_approved_by' => null,
                'officer_approved_at' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('tasks')
            ->where(function ($query) {
                $query->where('department', 'like', 'Tech pack%')
                    ->orWhere('department', 'like', 'Production template%');
            })
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->update(['approver_role' => 'leader']);

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('officer_approved_by');
            $table->dropColumn('officer_approved_at');
        });
    }
};
