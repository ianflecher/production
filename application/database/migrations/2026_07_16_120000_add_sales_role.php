<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','leader','sales','agent') NOT NULL DEFAULT 'agent'");
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET role = 'agent' WHERE role = 'sales'");
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','leader','agent') NOT NULL DEFAULT 'agent'");
    }
};
