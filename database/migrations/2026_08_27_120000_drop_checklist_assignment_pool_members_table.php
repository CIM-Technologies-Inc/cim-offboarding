<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Superseded: bulk "Assign Checklist" no longer grants a shared visibility
// pool — it now directly assigns each eligible item to a selected employee
// (round-robin when several are selected), reusing the exact same
// `checklist_item_assignments` mechanism "Check This List" already uses.
// See ChecklistDelegationController::assignPool().
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('checklist_assignment_pool_members');
    }

    public function down(): void
    {
        // Intentionally not recreated — the feature this table backed was
        // replaced, not toggled. Restore from
        // 2026_08_27_100000_create_checklist_assignment_pool_members_table.php
        // if this ever needs to be reverted.
    }
};
