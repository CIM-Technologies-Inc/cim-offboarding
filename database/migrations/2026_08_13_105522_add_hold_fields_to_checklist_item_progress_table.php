<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('checklist_item_progress', function (Blueprint $table) {
            $table->string('status')->nullable()->after('remark');
            $table->foreignId('held_by_user_id')->nullable()->after('checked_by_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('held_at')->nullable()->after('checked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checklist_item_progress', function (Blueprint $table) {
            $table->dropConstrainedForeignId('held_by_user_id');
            $table->dropColumn(['status', 'held_at']);
        });
    }
};
