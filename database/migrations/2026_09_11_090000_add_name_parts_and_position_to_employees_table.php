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
        Schema::table('employees', function (Blueprint $table) {
            // Discrete name parts alongside the existing combined `name`
            // column — imported straight from the Employee Master Excel
            // file's own `firstName`/`lastName`/`middleName` columns (see
            // `EmployeeGroupController::import()`). `name` itself keeps
            // being maintained exactly as before (a title-cased
            // "First Middle Last" concatenation), so every existing
            // display/search/picker that reads `name` is unaffected.
            $table->string('firstName')->nullable()->after('name');
            $table->string('lastName')->nullable()->after('firstName');
            $table->string('middleName')->nullable()->after('lastName');

            // A separate column from the pre-existing `designation` (which
            // the Employee Master import has always populated from this
            // same Excel `position` value, and which the rest of the app —
            // the Employee Directory's "Position" column, checklist
            // Department Head eligibility, etc. — already reads). Adding
            // this alongside rather than replacing `designation` keeps
            // every existing reader of `designation` working unchanged.
            $table->string('position')->nullable()->after('head');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['firstName', 'lastName', 'middleName', 'position']);
        });
    }
};
