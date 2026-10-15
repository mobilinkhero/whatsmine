<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE integration_audit_logs MODIFY COLUMN action ENUM('create', 'update', 'delete', 'enable', 'disable', 'test', 'rotate', 'register_webhooks') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE integration_audit_logs MODIFY COLUMN action ENUM('create', 'update', 'delete', 'enable', 'disable', 'test', 'rotate') NOT NULL");
    }
};
