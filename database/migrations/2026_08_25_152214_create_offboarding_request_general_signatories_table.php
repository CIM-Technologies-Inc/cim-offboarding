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
        Schema::create('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_request_id')
                ->constrained(indexName: 'ob_request_general_signatory_request_fk')
                ->cascadeOnDelete();
            $table->foreignId('general_signatory_id')
                ->constrained(indexName: 'ob_request_general_signatory_signatory_fk')
                ->cascadeOnDelete();
            $table->timestamps();

            // The row's mere existence is what "already processed for this
            // request" means — see ChecklistApprovalNotifier::notifyGeneralSignatories(),
            // which firstOrCreate()s against this exact pair before sending
            // any account-creation or email work, so the same General
            // Signatory can never be double-notified or double-attached to
            // the same request even if the submission handler somehow ran
            // twice.
            $table->unique(['offboarding_request_id', 'general_signatory_id'], 'ob_request_general_signatory_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offboarding_request_general_signatories');
    }
};
