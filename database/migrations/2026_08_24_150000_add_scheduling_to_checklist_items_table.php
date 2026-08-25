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
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->boolean('notify_enabled')->default(false)->after('signatory_id');
            $table->foreignId('email_template_id')->nullable()->after('notify_enabled')
                ->constrained()->nullOnDelete();
            $table->string('notify_timing')->nullable()->after('email_template_id');
            $table->unsignedInteger('notify_days')->nullable()->after('notify_timing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checklist_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_template_id');
            $table->dropColumn(['notify_enabled', 'notify_timing', 'notify_days']);
        });
    }
};
