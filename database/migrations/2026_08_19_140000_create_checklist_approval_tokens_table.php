<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_approval_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_request_approver_id');
            $table->string('token');
            $table->timestamp('expires_at');
            $table->timestamps();

            // Named explicitly — the auto-generated constraint name exceeds
            // MySQL's 64-character identifier limit.
            $table->foreign('offboarding_request_approver_id', 'checklist_approval_tokens_approver_fk')
                ->references('id')->on('offboarding_request_approvers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_approval_tokens');
    }
};
