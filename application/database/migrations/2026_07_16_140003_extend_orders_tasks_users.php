<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('order_number')->constrained('clients')->nullOnDelete();
            $table->json('decoration_methods')->nullable()->after('description');
            $table->string('cutting_type')->nullable()->after('decoration_methods');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('stage')->default(1)->after('sequence');
            $table->string('approver_role')->default('leader')->after('status'); // who approves this task
            $table->boolean('auto_assign')->default(false)->after('approver_role'); // artist steps
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_artist')->default(false)->after('role');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->timestamp('last_auto_assigned_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn(['decoration_methods', 'cutting_type']);
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['stage', 'approver_role', 'auto_assign']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_artist', 'last_login_at', 'last_auto_assigned_at']);
        });
    }
};
