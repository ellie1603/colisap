<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lookups that read far more rows than they need once the audit log and member list are full-sized
 * (measured on a copy with 17,000 members and 120,000 audit entries).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // Staff Accounts "Last sign-in": the newest sign-in of each user straight from the index
            // (measured 440 ms → under 1 ms; without it every sign-in of every user was read).
            $table->index(['user_id', 'action', 'created_at'], 'audit_logs_user_action_created_index');
        });

        Schema::table('members', function (Blueprint $table) {
            // Branches list: members per branch, archived ones left out, counted from the index alone.
            $table->index(['branch_id', 'deleted_at'], 'members_branch_deleted_index');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex('members_branch_deleted_index');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_user_action_created_index');
        });
    }
};
