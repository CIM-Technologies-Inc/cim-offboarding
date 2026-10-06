<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time backfill, then retirement, of `security_logs` — superseded by
 * the richer `activity_logs` table (see `create_activity_logs_table`).
 * `security_logs.user_id` meant "the account the event happened TO"; the
 * new table's `user_id` means "the actor" — for an admin-performed event
 * (`account_reactivated`) that's `performed_by_user_id`, with the old
 * `user_id` remapped to `subject_id`. For a self-performed auth event
 * (login/logout/blocked-attempt), actor and subject are the same account,
 * so `user_id` carries over directly with no subject needed.
 */
return new class extends Migration
{
    private const SELF_PERFORMED_EVENTS = [
        'successful_login', 'failed_login', 'blocked_login_attempt', 'account_blocked', 'logout',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('security_logs')) {
            return;
        }

        $rows = DB::table('security_logs')->orderBy('id')->get();

        foreach ($rows as $row) {
            $isSelfPerformed = in_array($row->event, self::SELF_PERFORMED_EVENTS, true);
            $isFailure = (bool) preg_match('/fail|blocked|rejected/i', $row->event);

            DB::table('activity_logs')->insert([
                'user_id' => $isSelfPerformed ? $row->user_id : ($row->performed_by_user_id ?? $row->user_id),
                'action' => $row->event,
                'module' => 'Authentication',
                'description' => ucfirst(str_replace('_', ' ', $row->event)).($row->attempted_username ? " (username: {$row->attempted_username})" : ''),
                'subject_type' => (! $isSelfPerformed && $row->user_id) ? 'User' : null,
                'subject_id' => (! $isSelfPerformed) ? $row->user_id : null,
                'new_values' => $row->context,
                'status' => $isFailure ? 'failed' : 'success',
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::dropIfExists('security_logs');
    }

    public function down(): void
    {
        Schema::create('security_logs', function ($table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->string('attempted_username')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users', indexName: 'security_logs_performed_by_user_id_fk')->nullOnDelete();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }
};
