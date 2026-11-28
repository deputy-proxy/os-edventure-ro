<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table): void {
            $table->string('compensation_basis', 16)->nullable()->after('contract_type')->index();
            $table->decimal('base_salary', 12, 2)->nullable()->change();
            $table->decimal('hourly_rate', 12, 2)->nullable()->change();
        });

        Schema::table('employee_timesheets', function (Blueprint $table): void {
            $table->string('approval_status', 16)->default('pending')->after('number_of_hours')->index();
            $table->foreignId('reviewed_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_note', 1000)->nullable()->after('reviewed_at');
            $table->string('source_type')->nullable()->after('review_note');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->decimal('number_of_hours', 8, 2)->default(0)->change();
            $table->foreignId('session_id')->nullable()->change();
            $table->string('legacy_session_reference')->nullable()->change();
            $table->unique(['source_type', 'source_id'], 'employee_timesheets_source_unique');
        });

        Schema::table('employee_pay_slips', function (Blueprint $table): void {
            $table->unsignedInteger('active_calculation_version')->default(0)->after('manual_amount');
        });

        Schema::table('employee_pay_slip_items', function (Blueprint $table): void {
            $table->dropUnique('employee_pay_slip_items_source_unique');
            $table->unsignedInteger('calculation_version')->default(0)->after('employee_pay_slip_id')->index();
        });
    }

    public function down(): void
    {
        if (DB::table('employee_contracts')->whereNotNull('compensation_basis')->exists()) {
            throw new RuntimeException('Explicit contract compensation bases are payroll source data and cannot be discarded automatically.');
        }
        if (DB::table('employee_contracts')->whereNull('base_salary')->orWhereNull('hourly_rate')->exists()) {
            throw new RuntimeException('Contract rate columns contain null values and cannot be made required again automatically.');
        }
        if (DB::table('employee_timesheets')->whereNotNull('source_type')->orWhereNotNull('source_id')->orWhereNotNull('reviewed_by')->orWhere('approval_status', '!=', 'pending')->orWhereNull('session_id')->orWhereNull('legacy_session_reference')->exists()) {
            throw new RuntimeException('Timesheet source links, approvals, or nullable session references must be retained and cannot be rolled back automatically.');
        }
        if (DB::table('employee_pay_slips')->where('active_calculation_version', '>', 0)->exists()) {
            throw new RuntimeException('Gross calculation versions are payroll audit data and cannot be rolled back automatically.');
        }

        if (Schema::hasTable('employee_pay_slip_items')) {
            if (DB::table('employee_pay_slip_items')->where('calculation_version', '>', 0)->exists()) {
                throw new RuntimeException('Gross payroll calculation versions contain auditable financial history and cannot be rolled back automatically.');
            }

            Schema::table('employee_pay_slip_items', function (Blueprint $table): void {
                $table->dropColumn('calculation_version');
                $table->unique(['employee_pay_slip_id', 'source_type', 'source_id'], 'employee_pay_slip_items_source_unique');
            });
        }

        Schema::table('employee_pay_slips', function (Blueprint $table): void {
            $table->dropColumn('active_calculation_version');
        });

        Schema::table('employee_timesheets', function (Blueprint $table): void {
            $table->dropUnique('employee_timesheets_source_unique');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['approval_status', 'reviewed_at', 'review_note', 'source_type', 'source_id']);
            $table->foreignId('session_id')->nullable(false)->change();
            $table->string('legacy_session_reference')->nullable(false)->change();
        });

        Schema::table('employee_contracts', function (Blueprint $table): void {
            $table->decimal('base_salary', 12, 2)->nullable(false)->change();
            $table->decimal('hourly_rate', 12, 2)->nullable(false)->change();
            $table->dropColumn('compensation_basis');
        });
    }
};
