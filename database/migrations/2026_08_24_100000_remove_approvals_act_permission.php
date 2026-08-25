<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION_NAME = 'approvals.act';

    /**
     * Run the migrations.
     *
     * Removes the "Act" permission from the Approvals module — it was
     * seeded alongside "View" but never actually enforced anywhere in the
     * app (existing routes stay role-gated, not permission-gated), so it
     * only ever showed up as a checkbox with no real effect. Deleting the
     * `permissions` row cascades to remove it from `role_has_permissions`/
     * `model_has_permissions` automatically (see
     * `2026_08_24_092045_create_permission_tables.php`'s
     * `cascadeOnDelete()` foreign keys).
     */
    public function up(): void
    {
        DB::table('permissions')->where('name', self::PERMISSION_NAME)->where('guard_name', 'web')->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('permissions')->insert([
            'name' => self::PERMISSION_NAME,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
