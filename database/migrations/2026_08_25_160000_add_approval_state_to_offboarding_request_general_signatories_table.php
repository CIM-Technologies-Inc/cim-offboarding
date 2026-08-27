<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('general_signatory_id');
            $table->timestamp('first_viewed_at')->nullable()->after('status');
            $table->timestamp('approved_at')->nullable()->after('first_viewed_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users', indexName: 'ob_request_general_signatory_approved_by_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->dropForeign('ob_request_general_signatory_approved_by_fk');
            $table->dropColumn(['status', 'first_viewed_at', 'approved_at', 'approved_by']);
        });
    }
};
