<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeePaySlip;
use App\Models\EmployeePaySlipItem;
use App\Models\Session;
use App\Models\SessionAssistance;
use App\Models\TestSimulation;
use App\Models\User;
use App\Services\Payroll\GrossPayrollPayslipService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EmployeePaySlipService
{
    /** Create one open payslip per employee with a contract overlapping the calendar month. */
    public function generateForMonth(CarbonImmutable $month): int
    {
        $periodStart = $month->startOfMonth();
        $periodEnd = $periodStart->endOfMonth();
        $employeeIds = EmployeeContract::query()
            ->whereDate('start_date', '<=', $periodEnd->toDateString())
            ->whereDate('end_date', '>=', $periodStart->toDateString())
            ->whereHas('employee')
            ->distinct()
            ->pluck('employee_id');

        $created = 0;
        foreach ($employeeIds as $employeeId) {
            $created += DB::transaction(function () use ($employeeId, $periodStart, $periodEnd): int {
                [$paySlip, $wasCreated] = $this->createOrGetForMonth((int) $employeeId, $periodStart, $periodEnd);
                if ($paySlip->isOpen() && ($wasCreated || (int) $paySlip->active_calculation_version === 0)) {
                    app(GrossPayrollPayslipService::class)->calculate(Employee::query()->findOrFail($employeeId), $periodStart);
                }

                return $wasCreated ? 1 : 0;
            });
        }

        return $created;
    }

    public function calculateGrossCompensation(EmployeePaySlip $paySlip): void
    {
        DB::transaction(function () use ($paySlip): void {
            $locked = EmployeePaySlip::query()->whereKey($paySlip->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->isOpen()) {
                $periodStart = CarbonImmutable::parse($locked->month)->startOfMonth();
                app(GrossPayrollPayslipService::class)->calculate(Employee::query()->findOrFail($locked->employee_id), $periodStart);
            }
        });
    }

    /** Add a completed source record to its employee's open payslip for the source's calendar month. */
    /** @deprecated Activity payroll entry now flows through EmployeeTimesheetService. */
    public function addSession(Session $session): array
    {
        $this->rejectCancelled($session->status);
        $start = CarbonImmutable::parse($session->starts_at);
        $end = CarbonImmutable::parse($session->ends_at);
        $hours = $this->durationHours($start, $end);
        $contract = $this->contractForDate((int) $session->teacher_id, $start);
        $rate = (float) $contract->hourly_rate;
        $description = $session->session_type === Session::TYPE_GROUP
            ? ($session->identifier ?: trim(($session->group?->title ?? 'Group session').' / '.($session->course?->title ?? 'Course')))
            : ($session->identifier ?: trim(($session->course?->title ?? 'Individual session').' / '.($session->customer?->name ?? 'Customer')));

        return $this->addSourceItem(
            (int) $session->teacher_id,
            $start,
            'session',
            (int) $session->getKey(),
            'session',
            $start->toDateString(),
            $description,
            $hours,
            'hour',
            $rate,
            $this->money($hours * $rate),
            [
                'source_model' => Session::class,
                'source_id' => $session->getKey(),
                'session_type' => $session->session_type,
                'starts_at' => $start->toIso8601String(),
                'ends_at' => $end->toIso8601String(),
                'hours' => $hours,
                'contract_id' => $contract->getKey(),
                'contract_type' => $contract->contract_type,
                'rate_field' => 'EmployeeContract.hourly_rate',
                'hourly_rate' => $rate,
                'formula' => 'round(hours * hourly_rate, 2)',
            ],
        );
    }

    /** @deprecated Activity payroll entry now flows through EmployeeTimesheetService. */
    public function addSessionAssistance(SessionAssistance $assistance): array
    {
        if (! in_array(strtolower(trim((string) $assistance->status)), ['complete', 'completed'], true)) {
            throw ValidationException::withMessages(['status' => 'Only completed session assistance can be added to a payslip.']);
        }
        $session = $assistance->session;
        if (! $session) {
            throw ValidationException::withMessages(['session_id' => 'The session assistance record must reference a session.']);
        }

        $this->rejectCancelled($session->status);
        $start = CarbonImmutable::parse($session->starts_at);
        $end = CarbonImmutable::parse($session->ends_at);
        $hours = $this->durationHours($start, $end);
        $occurredOn = CarbonImmutable::parse($assistance->assistance_date);
        $contract = $this->contractForDate((int) $assistance->teacher_id, $occurredOn);
        $rate = (float) $contract->hourly_rate;

        return $this->addSourceItem(
            (int) $assistance->teacher_id,
            $occurredOn,
            'session_assistance',
            (int) $assistance->getKey(),
            'session_assistance',
            $occurredOn->toDateString(),
            'Session Assistance: '.($session->identifier ?: 'Session #'.$session->getKey()),
            $hours,
            'hour',
            $rate,
            $this->money($hours * $rate),
            [
                'source_model' => SessionAssistance::class,
                'source_id' => $assistance->getKey(),
                'session_id' => $session->getKey(),
                'session_starts_at' => $start->toIso8601String(),
                'session_ends_at' => $end->toIso8601String(),
                'assistance_date' => $occurredOn->toDateString(),
                'hours' => $hours,
                'contract_id' => $contract->getKey(),
                'contract_type' => $contract->contract_type,
                'rate_field' => 'EmployeeContract.hourly_rate',
                'hourly_rate' => $rate,
                'formula' => 'round(session duration in hours * hourly_rate, 2)',
            ],
        );
    }

    /** @deprecated Activity payroll entry now flows through EmployeeTimesheetService. */
    public function addTestSimulation(TestSimulation $simulation): array
    {
        if (strtolower((string) $simulation->status) !== 'completed') {
            throw ValidationException::withMessages(['status' => 'Only completed test simulations can be added to a payslip.']);
        }

        $occurredOn = CarbonImmutable::parse($simulation->date);
        $hours = $this->quantity($simulation->number_of_hours);
        if ($hours <= 0) {
            throw ValidationException::withMessages(['number_of_hours' => 'The simulation must have a positive number of hours.']);
        }

        $contract = $this->contractForDate((int) $simulation->teacher_id, $occurredOn);
        $rate = (float) $contract->hourly_rate;

        return $this->addSourceItem(
            (int) $simulation->teacher_id,
            $occurredOn,
            'test_simulation',
            (int) $simulation->getKey(),
            'test_simulation',
            $occurredOn->toDateString(),
            'Test Simulation: '.$simulation->title,
            $hours,
            'hour',
            $rate,
            $this->money($hours * $rate),
            [
                'source_model' => TestSimulation::class,
                'source_id' => $simulation->getKey(),
                'test_id' => $simulation->test_id,
                'group_id' => $simulation->group_id,
                'hours' => $hours,
                'contract_id' => $contract->getKey(),
                'contract_type' => $contract->contract_type,
                'rate_field' => 'EmployeeContract.hourly_rate',
                'hourly_rate' => $rate,
                'formula' => 'round(hours * hourly_rate, 2)',
            ],
        );
    }

    /**
     * Correct a locked historical item by recording a signed adjustment on a later open payslip.
     * The original item is never edited or deleted.
     */
    public function addAdjustment(User $actor, EmployeePaySlip $target, EmployeePaySlipItem $original, float $amount, string $reason): array
    {
        if (! Gate::forUser($actor)->allows('payroll_period.correct')
            && ! (auth()->id() === $actor->getKey() && function_exists('hexa') && hexa()->can('payroll_period.correct'))) {
            throw new AuthorizationException('The payroll correction permission is required.');
        }

        return DB::transaction(function () use ($target, $original, $amount, $reason): array {
            $target = EmployeePaySlip::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            if (! $target->isOpen()) {
                throw ValidationException::withMessages(['employee_pay_slip_id' => 'Adjustments can only be added to an Open payslip.']);
            }
            $originalPaySlip = EmployeePaySlip::query()->whereKey($original->employee_pay_slip_id)->lockForUpdate()->firstOrFail();
            if ((int) $target->employee_id !== (int) $originalPaySlip->employee_id) {
                throw ValidationException::withMessages(['employee_pay_slip_id' => 'The adjustment and original item must belong to the same employee.']);
            }

            $targetMonth = CarbonImmutable::parse($target->month)->startOfMonth();
            $originalMonth = CarbonImmutable::parse($originalPaySlip->month)->startOfMonth();
            if ($targetMonth->lessThan($originalMonth)) {
                throw ValidationException::withMessages(['employee_pay_slip_id' => 'An adjustment cannot be recorded in an earlier pay period.']);
            }
            if ($targetMonth->equalTo($originalMonth) && ((int) $target->getKey() !== (int) $originalPaySlip->getKey() || ! $originalPaySlip->isOpen())) {
                throw ValidationException::withMessages(['employee_pay_slip_id' => 'An adjustment in the same period requires the original payslip to still be Open.']);
            }
            if ($targetMonth->greaterThan($originalMonth) && ! in_array($originalPaySlip->status, ['Closed', 'Paid'], true)) {
                throw ValidationException::withMessages(['employee_pay_slip_id' => 'Close the original period before carrying a correction into a later payslip.']);
            }
            if (trim($reason) === '' || $amount == 0.0) {
                throw ValidationException::withMessages(['amount' => 'A non-zero adjustment amount and a reason are required.']);
            }
            if ((int) $target->active_calculation_version > 0 && $target->allLineItems()->where('source_type', 'employee_pay_slip_item')->where('source_id', $original->getKey())->exists()) {
                throw ValidationException::withMessages(['source_id' => 'This original payslip item already has a correction recorded on the target period.']);
            }

            $activeVersion = (int) $target->active_calculation_version;
            $nextVersion = $activeVersion > 0 ? $activeVersion + 1 : 0;
            $carriedItemIds = [];
            if ($activeVersion > 0) {
                foreach ($target->lineItems()->orderBy('id')->get() as $activeItem) {
                    $carried = EmployeePaySlipItem::query()->create([
                        'employee_pay_slip_id' => $target->getKey(),
                        'calculation_version' => $nextVersion,
                        'item_type' => $activeItem->item_type,
                        'source_type' => $activeItem->source_type,
                        'source_id' => $activeItem->source_id,
                        'period_key' => $activeItem->period_key,
                        'source_key' => sprintf('gross:v%d:carry:%d:%s', $nextVersion, $activeItem->getKey(), $activeItem->period_key),
                        'occurred_on' => $activeItem->occurred_on,
                        'description' => $activeItem->description,
                        'quantity' => $activeItem->quantity,
                        'unit' => $activeItem->unit,
                        'unit_rate' => $activeItem->unit_rate,
                        'amount' => $activeItem->amount,
                        'currency' => $activeItem->currency,
                        'calculation_snapshot' => array_merge($activeItem->calculation_snapshot ?? [], [
                            'carried_from_item_id' => (int) $activeItem->getKey(),
                            'carried_to_calculation_version' => $nextVersion,
                        ]),
                    ]);
                    $carriedItemIds[] = (int) $carried->getKey();
                }
            }

            $adjustment = $this->createItem($target, [
                'calculation_version' => $nextVersion,
                'item_type' => 'adjustment',
                'source_type' => 'employee_pay_slip_item',
                'source_id' => (int) $original->getKey(),
                'occurred_on' => CarbonImmutable::parse($target->month)->startOfMonth()->toDateString(),
                'description' => 'Payroll adjustment: '.$reason,
                'quantity' => 1,
                'unit' => 'adjustment',
                'unit_rate' => $this->money($amount),
                'amount' => $this->money($amount),
                'currency' => 'RON',
                'calculation_snapshot' => [
                    'original_item_id' => $original->getKey(),
                    'original_payslip_id' => $original->employee_pay_slip_id,
                    'original_amount' => (float) $original->amount,
                    'adjustment_reason' => trim($reason),
                    'formula' => 'signed manual correction; original item remains immutable',
                ],
            ]);

            if ($activeVersion > 0 && $adjustment['created']) {
                $history = (array) ($target->getAttribute('gross_calculation_history') ?? []);
                $latest = $history === [] ? [] : (array) $history[array_key_last($history)];
                $latest['gross_total'] = $this->money((float) $target->amount + $amount);
                $latest['formula_version'] = 'gross-v1-with-adjustments';
                $latest['adjustment_item_ids'] = [...($latest['adjustment_item_ids'] ?? []), (int) $adjustment['item']->getKey()];
                $latest['carried_item_ids'] = $carriedItemIds;
                $latest['calculated_at'] = now()->toIso8601String();
                $target->activateGrossCalculationVersion($nextVersion, $latest);
            }

            return $adjustment;
        });
    }

    private function addSourceItem(
        int $employeeId,
        CarbonImmutable $occurredAt,
        string $sourceType,
        int $sourceId,
        string $itemType,
        string $occurredOn,
        string $description,
        float $quantity,
        string $unit,
        float $unitRate,
        float $amount,
        array $snapshot,
    ): array {
        return DB::transaction(function () use ($employeeId, $occurredAt, $sourceType, $sourceId, $itemType, $occurredOn, $description, $quantity, $unit, $unitRate, $amount, $snapshot): array {
            $periodStart = $occurredAt->startOfMonth();
            $periodEnd = $occurredAt->endOfMonth();
            [$paySlip] = $this->createOrGetForMonth($employeeId, $periodStart, $periodEnd);
            if (! $paySlip->isOpen()) {
                throw ValidationException::withMessages(['employee_pay_slip_id' => 'Items can only be added to an Open payslip.']);
            }
            if ((int) $paySlip->active_calculation_version > 0) {
                throw ValidationException::withMessages(['source' => 'Session and simulation compensation must enter through approved timesheets, not direct payslip items.']);
            }
            $snapshot['pay_period'] = $occurredAt->format('Y-m');

            return $this->createItem($paySlip, [
                'item_type' => $itemType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'occurred_on' => $occurredOn,
                'description' => $description,
                'quantity' => $this->quantity($quantity),
                'unit' => $unit,
                'unit_rate' => $this->money($unitRate),
                'amount' => $this->money($amount),
                'currency' => 'RON',
                'calculation_snapshot' => $snapshot,
            ]);
        });
    }

    private function createItem(EmployeePaySlip $paySlip, array $attributes): array
    {
        if (! $paySlip->isOpen()) {
            throw ValidationException::withMessages(['employee_pay_slip_id' => 'Items can only be added to an Open payslip.']);
        }

        $periodKey = CarbonImmutable::parse($attributes['occurred_on'])->format('Y-m');
        $recurringSources = ['employee_contract', 'employee_holiday', 'employee_pay_slip_item'];
        $sourceKey = $attributes['source_type'].':'.$attributes['source_id'];
        if ($attributes['item_type'] === 'equipment_reimbursement') {
            $sourceKey = 'equipment_reimbursement:employee:'.$paySlip->employee_id.':'.$periodKey;
        } elseif (in_array($attributes['source_type'], $recurringSources, true)) {
            $sourceKey .= ':'.$periodKey;
        }
        $attributes['employee_pay_slip_id'] = $paySlip->getKey();
        $calculationVersion = (int) ($attributes['calculation_version'] ?? $paySlip->active_calculation_version ?? 0);
        $attributes['calculation_version'] = $calculationVersion;
        $attributes['period_key'] = $periodKey;
        if ($calculationVersion > 0) {
            $sourceKey .= ':v'.$calculationVersion;
        }
        $attributes['source_key'] = $sourceKey;

        $existing = EmployeePaySlipItem::query()->where('source_key', $sourceKey)->first();
        if ($existing) {
            if ((int) $existing->employee_pay_slip_id !== (int) $paySlip->getKey()) {
                throw ValidationException::withMessages(['source' => 'This source record is already linked to a different payslip and cannot be paid twice.']);
            }

            return ['item' => $existing, 'created' => false];
        }

        $item = EmployeePaySlipItem::query()->firstOrCreate(['source_key' => $sourceKey], $attributes);

        if ((int) $item->employee_pay_slip_id !== (int) $paySlip->getKey()) {
            throw ValidationException::withMessages(['source' => 'This source record is already linked to a different payslip and cannot be paid twice.']);
        }

        if ($item->wasRecentlyCreated) {
            $paySlip->recalculateAmount();
        }

        return ['item' => $item, 'created' => $item->wasRecentlyCreated];
    }

    private function createOrGetForMonth(int $employeeId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $eligible = EmployeeContract::query()
            ->where('employee_id', $employeeId)
            ->whereDate('start_date', '<=', $periodEnd->toDateString())
            ->whereDate('end_date', '>=', $periodStart->toDateString())
            ->exists();

        if (! $eligible || ! Employee::query()->whereKey($employeeId)->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'The employee must have an active contract overlapping the pay period.']);
        }

        $existing = EmployeePaySlip::withTrashed()
            ->where('employee_id', $employeeId)
            ->whereDate('month', $periodStart->toDateString())
            ->lockForUpdate()
            ->first();

        if ($existing?->trashed()) {
            throw ValidationException::withMessages(['month' => 'A deleted payslip already exists for this employee and period. Restore or reconcile it instead of creating a duplicate.']);
        }

        if ($existing) {
            return [$existing, false];
        }

        $now = now();
        $created = DB::table('employee_pay_slips')->insertOrIgnore([
            'employee_id' => $employeeId,
            'month' => $periodStart->toDateString(),
            'items' => '[]',
            'manual_amount' => 0,
            'amount' => 0,
            'due_on' => null,
            'paid_on' => null,
            'status' => 'Open',
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;

        $paySlip = EmployeePaySlip::withTrashed()
            ->where('employee_id', $employeeId)
            ->whereDate('month', $periodStart->toDateString())
            ->lockForUpdate()
            ->firstOrFail();

        if ($paySlip->trashed()) {
            throw ValidationException::withMessages(['month' => 'A deleted payslip already exists for this employee and period. Restore or reconcile it instead of creating a duplicate.']);
        }

        return [$paySlip, $created];
    }

    private function contractForDate(int $employeeId, CarbonImmutable $date): EmployeeContract
    {
        $contract = EmployeeContract::query()
            ->where('employee_id', $employeeId)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();

        if (! $contract) {
            throw ValidationException::withMessages(['employee_id' => 'No active employee contract covers the source activity date.']);
        }
        if ((float) $contract->hourly_rate < 0) {
            throw ValidationException::withMessages(['hourly_rate' => 'The active contract cannot have a negative hourly rate.']);
        }

        return $contract;
    }

    private function durationHours(CarbonImmutable $start, CarbonImmutable $end): float
    {
        if (! $end->greaterThan($start)) {
            throw ValidationException::withMessages(['ends_at' => 'A session must end after it starts before it can be added to payroll.']);
        }

        return $this->quantity($start->diffInSeconds($end) / 3600);
    }

    private function rejectCancelled(?string $status): void
    {
        if (in_array(strtolower(trim((string) $status)), ['cancelled', 'canceled', 'cancel'], true)) {
            throw ValidationException::withMessages(['status' => 'Cancelled sessions cannot be added to a payslip.']);
        }
    }

    private function quantity(float|int|string $value): float
    {
        return round((float) $value, 2, PHP_ROUND_HALF_UP);
    }

    private function money(float|int|string $value): float
    {
        return round((float) $value, 2, PHP_ROUND_HALF_UP);
    }
}
