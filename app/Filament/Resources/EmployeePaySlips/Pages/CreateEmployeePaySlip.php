<?php

namespace App\Filament\Resources\EmployeePaySlips\Pages;

use App\Filament\Resources\EmployeePaySlips\EmployeePaySlipResource;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeePaySlip;
use App\Services\EmployeePaySlipService;
use App\Services\Payroll\GrossPayrollCalculator;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateEmployeePaySlip extends CreateRecord
{
    protected static string $resource = EmployeePaySlipResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $month = CarbonImmutable::parse($data['month'])->startOfMonth();
        $duplicate = EmployeePaySlip::withTrashed()
            ->where('employee_id', $data['employee_id'])
            ->whereDate('month', $month->toDateString())
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['month' => 'A payslip already exists for this employee and month.']);
        }

        $eligible = EmployeeContract::query()
            ->where('employee_id', $data['employee_id'])
            ->whereDate('start_date', '<=', $month->endOfMonth()->toDateString())
            ->whereDate('end_date', '>=', $month->toDateString())
            ->exists();

        if (! $eligible) {
            throw ValidationException::withMessages(['employee_id' => 'The employee must have a contract overlapping the selected pay month.']);
        }

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $calculation = app(GrossPayrollCalculator::class)->calculate($employee, $month);
        if ($calculation['blocking_errors'] !== []) {
            throw ValidationException::withMessages(['month' => $calculation['blocking_errors']]);
        }

        $data['month'] = $month->toDateString();
        $data['status'] = 'Open';
        $data['paid_on'] = null;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): EmployeePaySlip {
            $data['items'] ??= [];
            $data['manual_amount'] ??= 0;
            $paySlip = EmployeePaySlip::query()->create($data);
            app(EmployeePaySlipService::class)->calculateGrossCompensation($paySlip);

            return $paySlip;
        });
    }
}
