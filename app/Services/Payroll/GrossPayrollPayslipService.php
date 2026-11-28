<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeePaySlip;
use App\Models\EmployeePaySlipItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrossPayrollPayslipService
{
    public function __construct(private readonly GrossPayrollCalculator $calculator) {}

    /** Calculate and persist a new immutable draft calculation version. */
    public function calculate(Employee $employee, CarbonImmutable $month): array
    {
        return DB::transaction(function () use ($employee, $month): array {
            $result = $this->calculator->calculate($employee, $month);
            if ($result['blocking_errors'] !== []) {
                throw ValidationException::withMessages(['payroll' => $result['blocking_errors']]);
            }

            $periodStart = $month->startOfMonth();
            $paySlip = EmployeePaySlip::withTrashed()
                ->where('employee_id', $employee->getKey())
                ->whereDate('month', $periodStart->toDateString())
                ->lockForUpdate()
                ->first();

            if ($paySlip?->trashed()) {
                throw ValidationException::withMessages(['month' => 'A deleted payslip already exists for this employee and month. Restore or reconcile it instead of creating a duplicate.']);
            }

            if (! $paySlip) {
                $paySlip = EmployeePaySlip::query()->create([
                    'employee_id' => $employee->getKey(),
                    'month' => $periodStart->toDateString(),
                    'manual_amount' => 0,
                    'items' => [],
                    'amount' => 0,
                    'status' => 'Open',
                    'approval_status' => 'draft',
                    'active_calculation_version' => 0,
                ]);
            }

            if (! $paySlip->isOpen()) {
                throw ValidationException::withMessages(['status' => 'Only draft, open payslips can be recalculated. Reviewed, approved, closed, and paid payslips are immutable.']);
            }
            if ((float) $paySlip->manual_amount !== 0.0) {
                throw ValidationException::withMessages(['manual_amount' => 'This draft contains a legacy manual baseline. Reconcile it before switching to the gross-only calculation.']);
            }

            $adjustments = $paySlip->lineItems()->where('item_type', 'adjustment')->get();
            foreach ($adjustments as $adjustment) {
                $result['items'][] = [
                    'item_type' => 'adjustment',
                    'source_type' => $adjustment->source_type,
                    'source_id' => (int) $adjustment->source_id,
                    'occurred_on' => CarbonImmutable::parse($adjustment->occurred_on)->toDateString(),
                    'description' => $adjustment->description,
                    'quantity' => (float) $adjustment->quantity,
                    'unit' => $adjustment->unit,
                    'unit_rate' => (float) $adjustment->unit_rate,
                    'amount' => (float) $adjustment->amount,
                    'currency' => $adjustment->currency,
                    'calculation_snapshot' => $adjustment->calculation_snapshot,
                ];
            }
            $result['gross_total'] = round(array_sum(array_column($result['items'], 'amount')), 2, PHP_ROUND_HALF_UP);
            if ($adjustments->isNotEmpty()) {
                $result['formula_version'] = 'gross-v1-with-adjustments';
            }

            $version = ((int) $paySlip->active_calculation_version) + 1;
            foreach ($result['items'] as $line) {
                $sourceKey = sprintf('gross:v%d:%s:%d:%s', $version, $line['source_type'], $line['source_id'], $result['period']);
                EmployeePaySlipItem::query()->create([
                    'employee_pay_slip_id' => $paySlip->getKey(),
                    'calculation_version' => $version,
                    'item_type' => $line['item_type'],
                    'source_type' => $line['source_type'],
                    'source_id' => $line['source_id'],
                    'period_key' => $result['period'],
                    'source_key' => $sourceKey,
                    'occurred_on' => $line['occurred_on'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'],
                    'unit_rate' => $line['unit_rate'],
                    'amount' => $line['amount'],
                    'currency' => 'RON',
                    'calculation_snapshot' => $line['calculation_snapshot'],
                ]);
            }

            $summarySnapshot = collect($result)->except(['items', 'blocking_errors'])->all();
            $summarySnapshot['calculated_at'] = now()->toIso8601String();
            $paySlip->activateGrossCalculationVersion($version, $summarySnapshot);
            $paySlip->refresh();
            $result['payslip_id'] = (int) $paySlip->getKey();
            $result['calculation_version'] = $version;
            $result['gross_total'] = (float) $paySlip->amount;

            return $result;
        });
    }
}
