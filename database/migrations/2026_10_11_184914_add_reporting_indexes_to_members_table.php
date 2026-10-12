<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard, monitoring tabs and member list count members by status, category, segment and savings on
 * every page load. Member rows are wide (they keep the original masterlist values), so each count used to read
 * the whole table. These indexes hold just the counted columns, so the counts are answered from the index alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Totals by status / category / segment, for every branch or one (dashboard, list tabs, charts).
            // The archive flag goes last on purpose: with it first, the database picked this index for every
            // query and then had to read the full rows (measured three times slower for the savings counts).
            $table->index(['status', 'category', 'segment', 'branch_id', 'deleted_at'], 'members_summary_index');

            // Upgrade requests, "can upgrade to 60K" and below-minimum-balance counts (monitoring, alerts).
            $table->index(
                ['status', 'category', 'category_upgrade_requested_at', 'savings_balance', 'deleted_at', 'branch_id'],
                'members_upgrade_index',
            );

            // Waiting members in approval-date order (180-day effectivity view and its "within N days" counts).
            $table->index(['status', 'approval_date'], 'members_status_approval_index');

            // Dormant members by the date their dormancy started (dormancy view, termination alerts).
            $table->index(['status', 'dormant_since'], 'members_status_dormant_index');

            // One-column indexes on status, category and segment only tempted the database into slow plans
            // (measured: it combined them instead of using the indexes above). The new ones replace them.
            $table->dropIndex('members_status_index');
            $table->dropIndex('members_category_index');
            $table->dropIndex('members_segment_index');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            // System Usage report: sign-ins and work per user in a date range.
            $table->index(['user_id', 'created_at'], 'audit_logs_user_created_index');
            $table->index(['action', 'created_at'], 'audit_logs_action_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_action_created_index');
            $table->dropIndex('audit_logs_user_created_index');
        });

        Schema::table('members', function (Blueprint $table) {
            $table->index('status', 'members_status_index');
            $table->index('category', 'members_category_index');
            $table->index('segment', 'members_segment_index');
            $table->dropIndex('members_status_dormant_index');
            $table->dropIndex('members_status_approval_index');
            $table->dropIndex('members_upgrade_index');
            $table->dropIndex('members_summary_index');
        });
    }
};
