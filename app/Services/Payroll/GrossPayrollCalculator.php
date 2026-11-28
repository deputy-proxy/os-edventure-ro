<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeHoliday;
use App\Models\EmployeeMedicalLeave;
use App\Models\EmployeeTimesheet;
use Carbon\CarbonImmutable;

/** Deterministic, read-only calculation of monthly gross compensation. */
class GrossPayrollCalculator
{
    /** @return array<string, mixed> */
    public function calculate(Employee $employee, CarbonImmutable $month): array
    {
        $periodStart = $month->startOfMonth();
        $periodEnd = $periodStart->endOfMonth()->startOfDay();
        $daysInMonth = $periodStart->daysInMonth;
        $contracts = EmployeeContract::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('start_date', '<=', $periodEnd->toDateString())
            ->whereDate('end_date', '>=', $periodStart->toDateString())
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $errors = [];
        $warnings = [];
        $segments = [];
        $items = [];
        $coveredThrough = null;
        $previousContractId = null;

        foreach ($contracts as $contract) {
            $start = CarbonImmutable::parse($contract->start_date)->max($periodStart);
            $end = CarbonImmutable::parse($contract->end_date)->min($periodEnd);

            if ($coveredThrough !== null && $start->lte($coveredThrough)) {
                $errors[] = sprintf('Contracts #%d and #%d overlap during %s. Reconcile the contract dates before calculating payroll.', $previousContractId, $contract->getKey(), $start->toDateString());
                $coveredThrough = $coveredThrough->max($end);
                $previousContractId = (int) $contract->getKey();

                continue;
            }
            $coveredThrough = $end;
            $previousContractId = (int) $contract->getKey();

            $basis = (string) ($contract->compensation_basis ?? '');
            if (! in_array($basis, [EmployeeContract::BASIS_MONTHLY, EmployeeContract::BASIS_HOURLY], true)) {
                $errors[] = sprintf('Contract #%d has no supported compensation basis. Set it explicitly to monthly or hourly.', $contract->getKey());

                continue;
            }

            $rate = $basis === EmployeeContract::BASIS_MONTHLY
                ? $contract->base_salary
                : $contract->hourly_rate;
            if ($rate === null || (float) $rate < 0) {
                $field = $basis === EmployeeContract::BASIS_MONTHLY ? 'monthly base salary' : 'hourly rate';
                $errors[] = sprintf('Contract #%d is missing a valid %s.', $contract->getKey(), $field);

                continue;
            }

            $coveredDays = (int) $start->diffInDays($end) + 1;
            $segment = [
                'contract_id' => (int) $contract->getKey(),
                'contract_type' => (string) $contract->contract_type,
                'compensation_basis' => $basis,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'covered_calendar_days' => $coveredDays,
                'rate' => (float) $rate,
                'currency' => 'RON',
            ];
            $segments[] = $segment;

            if ($basis === EmployeeContract::BASIS_MONTHLY) {
                $amount = $this->money(((float) $rate * $coveredDays) / $daysInMonth);
                $items[] = [
                    'item_type' => 'gross_monthly_salary',
                    'source_type' => 'employee_contract',
                    'source_id' => (int) $contract->getKey(),
                    'occurred_on' => $start->toDateString(),
                    'description' => sprintf('Gross monthly salary, contract #%d (%d/%d calendar days)', $contract->getKey(), $coveredDays, $daysInMonth),
                    'quantity' => $coveredDays,
                    'unit' => 'calendar_day',
                    'unit_rate' => $this->money((float) $rate / $daysInMonth),
                    'amount' => $amount,
                    'currency' => 'RON',
                    'calculation_snapshot' => [
                        'formula' => 'round(monthly_base_salary * covered_calendar_days / calendar_days_in_month, 2)',
                        'formula_version' => 'gross-v1',
                        'contract_id' => (int) $contract->getKey(),
                        'contract_type' => (string) $contract->contract_type,
                        'compensation_basis' => $basis,
                        'monthly_base_salary' => (float) $rate,
                        'covered_calendar_days' => $coveredDays,
                        'calendar_days_in_month' => $daysInMonth,
                        'period' => $periodStart->format('Y-m'),
                    ],
                ];
            }
        }

        if ($contracts->isEmpty()) {
            $errors[] = 'No employee contract overlaps the selected calendar month.';
        }

        $holidays = EmployeeHoliday::query()
            ->where('employee_id', $employee->getKey())
            ->whereRaw('LOWER(status) = ?', ['approved'])
            ->whereDate('start_date', '<=', $periodEnd->toDateString())
            ->whereDate('end_date', '>=', $periodStart->toDateString())
            ->get(['id', 'start_date', 'end_date']);
        foreach ($holidays as $holiday) {
            $errors[] = sprintf('Approved holiday #%d overlaps this period (%s to %s), but the record does not identify its gross-pay treatment. Resolve the leave classification before calculating.', $holiday->id, $holiday->start_date, $holiday->end_date);
        }

        $medicalLeaveConflicts = EmployeeMedicalLeave::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('start_date', '<=', $periodEnd->toDateString())
            ->whereDate('end_date', '>=', $periodStart->toDateString())
            ->get(['id', 'start_date', 'end_date'])
            ->map(fn (EmployeeMedicalLeave $leave): array => [
                'start_date' => (string) $leave->start_date,
                'end_date' => (string) $leave->end_date,
            ])->all();
        if ($medicalLeaveConflicts !== []) {
            $warnings[] = 'Medical-leave dates overlap this period. Review separately; no medical details or benefit/deduction amounts are included in this calculation.';
        }

        $pendingTimesheets = EmployeeTimesheet::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('approval_status', [EmployeeTimesheet::APPROVAL_PENDING, EmployeeTimesheet::APPROVAL_REJECTED])
            ->whereDate('date', '>=', $periodStart->toDateString())
            ->whereDate('date', '<=', $periodEnd->toDateString())
            ->count();
        if ($pendingTimesheets > 0) {
            $warnings[] = sprintf('%d pending or rejected timesheet(s) were excluded. Only approved timesheets are eligible for hourly compensation.', $pendingTimesheets);
        }

        $unknownApprovalStates = EmployeeTimesheet::query()
            ->where('employee_id', $employee->getKey())
            ->whereNotIn('approval_status', [EmployeeTimesheet::APPROVAL_PENDING, EmployeeTimesheet::APPROVAL_APPROVED, EmployeeTimesheet::APPROVAL_REJECTED])
            ->whereDate('date', '>=', $periodStart->toDateString())
            ->whereDate('date', '<=', $periodEnd->toDateString())
            ->count();
        if ($unknownApprovalStates > 0) {
            $errors[] = sprintf('%d timesheet(s) have an unsupported approval status. Reconcile their review state before calculating payroll.', $unknownApprovalStates);
        }

        $approvedTimesheets = EmployeeTimesheet::query()
            ->where('employee_id', $employee->getKey())
            ->where('approval_status', EmployeeTimesheet::APPROVAL_APPROVED)
            ->whereDate('date', '>=', $periodStart->toDateString())
            ->whereDate('date', '<=', $periodEnd->toDateString())
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        foreach ($approvedTimesheets as $timesheet) {
            $date = CarbonImmutable::parse($timesheet->date);
            $segment = collect($segments)->first(fn (array $candidate): bool => $date->gte(CarbonImmutable::parse($candidate['start_date']))
                && $date->lte(CarbonImmutable::parse($candidate['end_date'])));
            if ($segment === null) {
                $errors[] = sprintf('Approved timesheet #%d on %s has no supported contract covering its work date.', $timesheet->getKey(), $date->toDateString());

                continue;
            }
            if ($segment['compensation_basis'] === EmployeeContract::BASIS_MONTHLY) {
                continue;
            }

            $hours = (float) $timesheet->number_of_hours;
            if (! is_finite($hours) || $hours <= 0) {
                $errors[] = sprintf('Approved timesheet #%d must contain a positive number of hours.', $timesheet->getKey());

                continue;
            }

            $rate = (float) $segment['rate'];
            $amount = $this->money($hours * $rate);
            $items[] = [
                'item_type' => 'gross_hourly_timesheet',
                'source_type' => 'employee_timesheet',
                'source_id' => (int) $timesheet->getKey(),
                'occurred_on' => $date->toDateString(),
                'description' => sprintf('Gross hourly compensation: %s (%s)', $timesheet->category, $date->toDateString()),
                'quantity' => $hours,
                'unit' => 'hour',
                'unit_rate' => $this->money($rate),
                'amount' => $amount,
                'currency' => 'RON',
                'calculation_snapshot' => [
                    'formula' => 'round(approved_timesheet_hours * contract_hourly_rate, 2)',
                    'formula_version' => 'gross-v1',
                    'contract_id' => $segment['contract_id'],
                    'compensation_basis' => EmployeeContract::BASIS_HOURLY,
                    'timesheet_id' => (int) $timesheet->getKey(),
                    'timesheet_date' => $date->toDateString(),
                    'timesheet_category' => (string) $timesheet->category,
                    'timesheet_source_type' => $timesheet->source_type,
                    'timesheet_source_id' => $timesheet->source_id,
                    'approval_status' => $timesheet->approval_status,
                    'approved_by' => $timesheet->reviewed_by,
                    'approved_at' => $timesheet->reviewed_at === null ? null : CarbonImmutable::parse($timesheet->reviewed_at)->toISOString(),
                    'hours' => $hours,
                    'hourly_rate' => $rate,
                    'period' => $periodStart->format('Y-m'),
                ],
            ];
        }

        $grossTotal = $this->money(array_sum(array_column($items, 'amount')));

        return [
            'employee_id' => (int) $employee->getKey(),
            'period' => $periodStart->format('Y-m'),
            'currency' => 'RON',
            'rounding' => 'Each gross line is rounded half-up to two decimals; the total is the sum of rounded gross lines.',
            'formula_version' => 'gross-v1',
            'contract_segments' => $segments,
            'timesheet_ids' => array_values(array_unique(array_column(array_filter($items, fn (array $item): bool => $item['source_type'] === 'employee_timesheet'), 'source_id'))),
            'medical_leave_date_conflicts' => ['has_overlap' => $medicalLeaveConflicts !== [], 'overlapping_date_ranges' => array_map(fn (array $conflict): array => ['start_date' => $conflict['start_date'], 'end_date' => $conflict['end_date']], $medicalLeaveConflicts)],
            'items' => $items,
            'gross_total' => $grossTotal,
            'blocking_errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function money(float $amount): float
    {
        return round($amount, 2, PHP_ROUND_HALF_UP);
    }
}
