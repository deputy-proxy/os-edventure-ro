<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeHoliday;
use App\Models\EmployeeMedicalLeave;
use App\Models\EmployeePaySlip;
use App\Models\EmployeeTimesheet;
use App\Models\PayrollExportEvent;
use App\Models\Session;
use App\Models\User;
use App\Services\EmployeePaySlipService;
use App\Services\EmployeeTimesheetService;
use App\Services\Payroll\GrossPayrollCalculator;
use App\Services\Payroll\GrossPayrollPayslipService;
use App\Services\Payroll\PayrollPeriodExportService;
use App\Services\Payroll\PayrollPeriodLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GrossPayrollWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_salary_is_calculated_for_a_full_calendar_month(): void
    {
        $employee = Employee::factory()->create();
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-01',
            'end_date' => '2024-02-29',
            'compensation_basis' => EmployeeContract::BASIS_MONTHLY,
            'base_salary' => 3100,
        ]);
        $reviewer = User::factory()->create();
        $monthlyActivity = EmployeeTimesheet::factory()->create([
            'employee_id' => $employee->id,
            'date' => '2024-02-10',
            'number_of_hours' => 4,
        ]);
        $monthlyActivity->recordReview(EmployeeTimesheet::APPROVAL_APPROVED, $reviewer->id, null);

        $result = app(GrossPayrollCalculator::class)->calculate($employee, CarbonImmutable::parse('2024-02-01'));

        $this->assertSame([], $result['blocking_errors']);
        $this->assertSame(3100.0, $result['gross_total']);
        $this->assertSame(29, $result['items'][0]['quantity']);
        $this->assertSame('gross_monthly_salary', $result['items'][0]['item_type']);
    }

    public function test_monthly_salary_is_prorated_by_calendar_days_for_partial_contract_coverage(): void
    {
        $employee = Employee::factory()->create();
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-15',
            'end_date' => '2024-02-29',
            'compensation_basis' => EmployeeContract::BASIS_MONTHLY,
            'base_salary' => 3100,
        ]);

        $result = app(GrossPayrollCalculator::class)->calculate($employee, CarbonImmutable::parse('2024-02-01'));

        $this->assertSame(15, $result['items'][0]['quantity']);
        $this->assertSame(1603.45, $result['gross_total']);
    }

    public function test_hourly_compensation_uses_only_approved_timesheet_hours(): void
    {
        $employee = Employee::factory()->create();
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-01',
            'end_date' => '2024-02-29',
            'compensation_basis' => EmployeeContract::BASIS_HOURLY,
            'hourly_rate' => 25,
        ]);
        $reviewer = User::factory()->create();
        $approved = EmployeeTimesheet::factory()->create([
            'employee_id' => $employee->id,
            'date' => '2024-02-10',
            'time_start' => '2024-02-10 09:00:00',
            'time_end' => '2024-02-10 11:30:00',
            'number_of_hours' => 2.5,
        ]);
        $approved->recordReview(EmployeeTimesheet::APPROVAL_APPROVED, $reviewer->id, null);
        EmployeeTimesheet::factory()->create([
            'employee_id' => $employee->id,
            'date' => '2024-02-11',
            'number_of_hours' => 8,
            'approval_status' => EmployeeTimesheet::APPROVAL_PENDING,
        ]);
        $rejected = EmployeeTimesheet::factory()->create([
            'employee_id' => $employee->id,
            'date' => '2024-02-12',
            'number_of_hours' => 8,
        ]);
        $rejected->recordReview(EmployeeTimesheet::APPROVAL_REJECTED, $reviewer->id, 'Incorrect hours');

        $result = app(GrossPayrollCalculator::class)->calculate($employee, CarbonImmutable::parse('2024-02-01'));

        $this->assertSame([], $result['blocking_errors']);
        $this->assertSame(62.5, $result['gross_total']);
        $this->assertCount(1, $result['items']);
        $this->assertCount(1, $result['timesheet_ids']);
        $this->assertCount(1, $result['warnings']);
    }

    public function test_sequential_monthly_contracts_are_prorated_independently(): void
    {
        $employee = Employee::factory()->create();
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-01',
            'end_date' => '2024-02-14',
            'compensation_basis' => EmployeeContract::BASIS_MONTHLY,
            'base_salary' => 2900,
        ]);
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-15',
            'end_date' => '2024-02-29',
            'compensation_basis' => EmployeeContract::BASIS_MONTHLY,
            'base_salary' => 3100,
        ]);

        $result = app(GrossPayrollCalculator::class)->calculate($employee, CarbonImmutable::parse('2024-02-01'));

        $this->assertSame([], $result['blocking_errors']);
        $this->assertCount(2, $result['items']);
        $this->assertSame(3003.45, $result['gross_total']);
    }

    public function test_overlapping_contracts_and_missing_compensation_basis_block_calculation(): void
    {
        $employee = Employee::factory()->create();
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-01',
            'end_date' => '2024-02-20',
            'compensation_basis' => EmployeeContract::BASIS_MONTHLY,
        ]);
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-15',
            'end_date' => '2024-02-29',
            'compensation_basis' => null,
        ]);

        $result = app(GrossPayrollCalculator::class)->calculate($employee, CarbonImmutable::parse('2024-02-01'));

        $this->assertNotEmpty($result['blocking_errors']);
        $this->assertStringContainsString('overlap', implode(' ', $result['blocking_errors']));
    }

    public function test_approved_holiday_without_gross_pay_classification_blocks_calculation(): void
    {
        $employee = Employee::factory()->create();
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-01',
            'end_date' => '2024-02-29',
            'compensation_basis' => EmployeeContract::BASIS_MONTHLY,
            'base_salary' => 3100,
        ]);
        EmployeeHoliday::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-12',
            'end_date' => '2024-02-14',
            'status' => 'Approved',
        ]);

        $result = app(GrossPayrollCalculator::class)->calculate($employee, CarbonImmutable::parse('2024-02-01'));

        $this->assertStringContainsString('gross-pay treatment', implode(' ', $result['blocking_errors']));
    }

    public function test_medical_leave_only_adds_date_warning_and_never_exposes_document_details(): void
    {
        $employee = Employee::factory()->create();
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-01',
            'end_date' => '2024-02-29',
            'compensation_basis' => EmployeeContract::BASIS_MONTHLY,
            'base_salary' => 3100,
        ]);
        EmployeeMedicalLeave::query()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-12',
            'end_date' => '2024-02-14',
            'document' => 'private-medical-document.pdf',
        ]);

        $result = app(GrossPayrollCalculator::class)->calculate($employee, CarbonImmutable::parse('2024-02-01'));
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertSame([], $result['blocking_errors']);
        $this->assertTrue($result['medical_leave_date_conflicts']['has_overlap']);
        $this->assertStringNotContainsString('private-medical-document.pdf', $serialized);
        $this->assertStringNotContainsString('employee_medical_leaves', $serialized);
    }

    public function test_draft_recalculation_keeps_previous_immutable_line_items_as_history(): void
    {
        $employee = Employee::factory()->create();
        $contract = EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-01',
            'end_date' => '2024-02-29',
            'compensation_basis' => EmployeeContract::BASIS_MONTHLY,
            'base_salary' => 2900,
        ]);
        $service = app(GrossPayrollPayslipService::class);

        $first = $service->calculate($employee, CarbonImmutable::parse('2024-02-01'));
        $contract->update(['base_salary' => 3100]);
        $second = $service->calculate($employee, CarbonImmutable::parse('2024-02-01'));
        $paySlip = EmployeePaySlip::query()->findOrFail($first['payslip_id']);

        $this->assertSame(1, $first['calculation_version']);
        $this->assertSame(2, $second['calculation_version']);
        $this->assertSame('3100.00', $paySlip->fresh()->amount);
        $this->assertSame(1, $paySlip->fresh()->lineItems()->count());
        $this->assertSame(2, $paySlip->fresh()->allLineItems()->count());
        $this->assertSame('2900.00', $paySlip->fresh()->allLineItems()->orderBy('calculation_version')->firstOrFail()->amount);
        $this->assertCount(2, $paySlip->fresh()->gross_calculation_history);
        $this->assertEquals(2900, $paySlip->fresh()->gross_calculation_history[0]['gross_total']);
    }

    public function test_reviewed_timesheet_cannot_be_changed_or_deleted(): void
    {
        $employee = Employee::factory()->create();
        $reviewer = User::factory()->create();
        $timesheet = EmployeeTimesheet::factory()->create([
            'employee_id' => $employee->id,
        ]);
        $timesheet->recordReview(EmployeeTimesheet::APPROVAL_APPROVED, $reviewer->id, null);

        try {
            $timesheet->update(['number_of_hours' => 4]);
            $this->fail('Reviewed timesheets must be immutable.');
        } catch (ValidationException) {
            $this->assertSame('approved', $timesheet->fresh()->approval_status);
        }

        try {
            $timesheet->delete();
            $this->fail('Reviewed timesheets must not be deleted.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('employee_timesheets', ['id' => $timesheet->id]);
        }
    }

    public function test_gross_payslip_uses_existing_review_close_and_export_lifecycle(): void
    {
        foreach (['payroll_period.review', 'payroll_period.approve', 'payroll_period.close', 'payroll_period.export'] as $ability) {
            Gate::define($ability, static fn (): bool => true);
        }

        $employee = Employee::factory()->create();
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-01',
            'end_date' => '2024-02-29',
            'compensation_basis' => EmployeeContract::BASIS_MONTHLY,
            'base_salary' => 3100,
        ]);
        $actor = User::factory()->create();
        $reviewer = User::factory()->create();
        $approver = User::factory()->create();
        $result = app(GrossPayrollPayslipService::class)->calculate($employee, CarbonImmutable::parse('2024-02-01'));
        $paySlip = EmployeePaySlip::query()->findOrFail($result['payslip_id']);
        $lifecycle = app(PayrollPeriodLifecycleService::class);

        $lifecycle->submitForReview($reviewer, $paySlip, 'Review gross-only calculation.');
        $lifecycle->approve($approver, $paySlip->fresh(), 'Approve gross-only calculation.');
        $lifecycle->close($actor, $paySlip->fresh(), 'Close gross-only period.');

        $closed = $paySlip->fresh();
        $this->assertSame('closed', $closed->approval_status);
        $this->assertSame(1, $closed->approved_snapshot['gross_calculation']['version']);
        $this->assertSame('3100.00', $closed->approved_snapshot['gross_calculation']['gross_total']);
        $this->assertEquals(3100, $closed->approved_snapshot['gross_calculation']['active_calculation_snapshot']['gross_total']);
        $this->assertArrayNotHasKey('calculation_runs', $closed->approved_snapshot['gross_calculation']);

        $export = app(PayrollPeriodExportService::class)->export($actor, $closed, 1, 'line_items', 'csv');
        $this->assertSame(1, $export['row_count']);
        $this->assertStringContainsString('gross_monthly_salary', $export['content']);
        $this->assertSame(1, PayrollExportEvent::query()->count());
        $this->assertNull(PayrollExportEvent::query()->firstOrFail()->payroll_calculation_run_id);
        $this->assertSame(1, PayrollExportEvent::query()->firstOrFail()->gross_calculation_version);
    }

    public function test_completed_session_creates_one_pending_source_linked_timesheet(): void
    {
        $employee = Employee::factory()->create();
        $session = Session::factory()->individual()->create([
            'teacher_id' => $employee->id,
            'starts_at' => '2024-02-10 09:00:00',
            'ends_at' => '2024-02-10 10:30:00',
            'status' => 'completed',
        ]);

        $service = app(EmployeeTimesheetService::class);
        $first = $service->createFromActivity($session);
        $second = $service->createFromActivity($session->fresh());

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(EmployeeTimesheet::APPROVAL_PENDING, $first['timesheet']->approval_status);
        $this->assertSame(Session::class, $first['timesheet']->source_type);
        $this->assertSame($session->id, $first['timesheet']->source_id);
        $this->assertSame($session->id, $first['timesheet']->session_id);
        $this->assertSame('1.50', $first['timesheet']->number_of_hours);
        $this->assertSame(1, EmployeeTimesheet::query()->count());
    }

    public function test_timesheet_month_membership_uses_its_authoritative_work_date(): void
    {
        $employee = Employee::factory()->create();
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-01',
            'end_date' => '2024-02-29',
            'compensation_basis' => EmployeeContract::BASIS_HOURLY,
            'hourly_rate' => 25,
        ]);
        $reviewer = User::factory()->create();
        $timesheet = EmployeeTimesheet::factory()->create([
            'employee_id' => $employee->id,
            'date' => '2024-02-01',
            'time_start' => '2024-01-31 23:00:00',
            'time_end' => '2024-02-01 01:00:00',
            'number_of_hours' => 2,
        ]);
        $timesheet->recordReview(EmployeeTimesheet::APPROVAL_APPROVED, $reviewer->id, null);

        $result = app(GrossPayrollCalculator::class)->calculate($employee, CarbonImmutable::parse('2024-02-01'));

        $this->assertSame(50.0, $result['gross_total']);
        $this->assertSame('2024-02-01', $result['items'][0]['occurred_on']);
    }

    public function test_legacy_contract_without_explicit_basis_is_blocked(): void
    {
        $employee = Employee::factory()->create();
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-01',
            'end_date' => '2024-02-29',
            'compensation_basis' => null,
        ]);

        $result = app(GrossPayrollCalculator::class)->calculate($employee, CarbonImmutable::parse('2024-02-01'));

        $this->assertStringContainsString('no supported compensation basis', implode(' ', $result['blocking_errors']));
    }

    public function test_gross_calculation_versions_preserve_existing_correction_adjustments(): void
    {
        foreach (['payroll_period.review', 'payroll_period.approve', 'payroll_period.close', 'payroll_period.correct'] as $ability) {
            Gate::define($ability, static fn (): bool => true);
        }

        $employee = Employee::factory()->create();
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-01-01',
            'end_date' => '2024-02-29',
            'compensation_basis' => EmployeeContract::BASIS_MONTHLY,
            'base_salary' => 3100,
        ]);
        $reviewer = User::factory()->create();
        $approver = User::factory()->create();
        $closer = User::factory()->create();
        $grossService = app(GrossPayrollPayslipService::class);
        $lifecycle = app(PayrollPeriodLifecycleService::class);
        $january = $grossService->calculate($employee, CarbonImmutable::parse('2024-01-01'));
        $januaryPaySlip = EmployeePaySlip::query()->findOrFail($january['payslip_id']);
        $originalItem = $januaryPaySlip->lineItems()->firstOrFail();
        $lifecycle->submitForReview($reviewer, $januaryPaySlip, 'January review.');
        $lifecycle->approve($approver, $januaryPaySlip->fresh(), 'January approval.');
        $lifecycle->close($closer, $januaryPaySlip->fresh(), 'January close.');

        $february = $grossService->calculate($employee, CarbonImmutable::parse('2024-02-01'));
        $februaryPaySlip = EmployeePaySlip::query()->findOrFail($february['payslip_id']);
        app(EmployeePaySlipService::class)->addAdjustment($closer, $februaryPaySlip, $originalItem, -100, 'Prior period correction');

        $adjusted = $februaryPaySlip->fresh();
        $this->assertSame(2, $adjusted->active_calculation_version);
        $this->assertSame('3000.00', $adjusted->amount);
        $this->assertSame(2, $adjusted->lineItems()->count());

        $recalculated = $grossService->calculate($employee, CarbonImmutable::parse('2024-02-01'));
        $finalDraft = $februaryPaySlip->fresh();
        $this->assertSame(3, $recalculated['calculation_version']);
        $this->assertSame('3000.00', $finalDraft->amount);
        $this->assertSame(2, $finalDraft->lineItems()->count());
        $this->assertSame('gross-v1-with-adjustments', $finalDraft->gross_calculation_history[2]['formula_version']);
    }

    public function test_unknown_timesheet_approval_status_blocks_calculation(): void
    {
        $employee = Employee::factory()->create();
        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'start_date' => '2024-02-01',
            'end_date' => '2024-02-29',
            'compensation_basis' => EmployeeContract::BASIS_HOURLY,
            'hourly_rate' => 25,
        ]);
        $timesheet = EmployeeTimesheet::factory()->create([
            'employee_id' => $employee->id,
            'date' => '2024-02-10',
        ]);
        DB::table('employee_timesheets')->where('id', $timesheet->id)->update(['approval_status' => 'needs_review']);

        $result = app(GrossPayrollCalculator::class)->calculate($employee, CarbonImmutable::parse('2024-02-01'));

        $this->assertStringContainsString('unsupported approval status', implode(' ', $result['blocking_errors']));
    }
}
