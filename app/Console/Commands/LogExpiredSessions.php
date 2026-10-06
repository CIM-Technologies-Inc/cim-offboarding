<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LogExpiredSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:log-expired-sessions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect sessions that have gone idle past the configured session lifetime and record a "session_expired" activity-log entry for each, distinct from a normal logout.';

    /**
     * A best-effort, periodic sweep — not instant detection. A passive
     * server-side session has no event to hook when it goes idle; this
     * relies on the `sessions` table's own `last_activity` column (only
     * populated when `SESSION_DRIVER=database`, see .env) and deletes
     * each row it logs so the same expiry is never logged twice and the
     * table doesn't grow unbounded (the same end effect Laravel's own
     * session garbage collection would eventually have, just immediate
     * and paired with an audit-log entry).
     */
    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

        $expired = DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '<', $cutoff)
            ->get(['id', 'user_id']);

        if ($expired->isEmpty()) {
            return self::SUCCESS;
        }

        $users = User::whereIn('id', $expired->pluck('user_id')->unique())->get()->keyBy('id');

        foreach ($expired as $session) {
            $user = $users->get($session->user_id);

            if ($user) {
                ActivityLog::record('session_expired', 'Authentication', "{$user->name}'s session expired due to inactivity.", [
                    'user' => $user,
                    'session_id' => $session->id,
                ]);
            }

            DB::table('sessions')->where('id', $session->id)->delete();
        }

        return self::SUCCESS;
    }
}
