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
            $table->string('personal_email')->nullable()->after('email');
            $table->string('sup_one')->nullable()->after('designation');
            $table->string('sup_two')->nullable()->after('sup_one');
            $table->string('head')->nullable()->after('sup_two');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['personal_email', 'sup_one', 'sup_two', 'head']);
        });
    }
};
