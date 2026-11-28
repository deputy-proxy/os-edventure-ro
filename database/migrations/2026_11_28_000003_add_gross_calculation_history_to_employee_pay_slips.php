<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_pay_slips', function (Blueprint $table): void {
            $table->json('gross_calculation_history')->nullable()->after('active_calculation_version');
        });
    }

    public function down(): void
    {
        if (DB::table('employee_pay_slips')->whereNotNull('gross_calculation_history')->exists()) {
            throw new RuntimeException('Gross calculation history is immutable payroll audit data and cannot be dropped automatically.');
        }

        Schema::table('employee_pay_slips', function (Blueprint $table): void {
            $table->dropColumn('gross_calculation_history');
        });
    }
};
