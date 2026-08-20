<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('signature_path');
        });

        // Backfill: this app's established convention (see
        // User::findOrCreateApprover()) creates accounts with password =
        // username as a temporary/first-login password. Any existing
        // account still on that unchanged password must be flagged now,
        // not just newly-created ones going forward.
        DB::table('users')->select('id', 'username', 'password')->get()->each(function ($user) {
            if ($user->username && Hash::check($user->username, $user->password)) {
                DB::table('users')->where('id', $user->id)->update(['must_change_password' => true]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
