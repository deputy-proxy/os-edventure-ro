<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_export_events', function (Blueprint $table): void {
            $table->unsignedInteger('gross_calculation_version')->nullable()->after('payroll_calculation_run_id');
            $table->foreignId('payroll_calculation_run_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('payroll_export_events')->whereNull('payroll_calculation_run_id')->exists()) {
            throw new RuntimeException('Gross payroll export audit events must be retained and cannot be rolled back automatically.');
        }

        Schema::table('payroll_export_events', function (Blueprint $table): void {
            $table->dropColumn('gross_calculation_version');
            $table->foreignId('payroll_calculation_run_id')->nullable(false)->change();
        });
    }
};
