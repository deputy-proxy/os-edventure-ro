<?php

namespace App\Filament\Resources\EmployeePaySlips\Tables;

use App\Filament\Resources\EmployeePaySlips\Actions\RecordPaySlipAdjustmentAction;
use App\Filament\Resources\EmployeePaySlips\Actions\RecordPaySlipReversalAction;
use App\Models\Employee;
use App\Models\EmployeePaySlip;
use App\Services\Payroll\GrossPayrollPayslipService;
use App\Services\Payroll\PayrollPeriodLifecycleService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class EmployeePaySlipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.full_name')
                    ->label('Employee')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('month'),
                Tables\Columns\TextColumn::make('amount')->money('RON')->label('Gross total'),
                Tables\Columns\TextColumn::make('due_on')
                    ->dateTime(),
                Tables\Columns\TextColumn::make('paid_on')
                    ->dateTime(),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('approval_status')->label('Approval')->badge(),
                Tables\Columns\TextColumn::make('approved_at')->dateTime()->label('Approved at'),
                Tables\Columns\TextColumn::make('closed_at')->dateTime()->label('Closed at'),
                Tables\Columns\TextColumn::make('active_calculation_version')->label('Gross calculation version'),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('calculateGrossCompensation')
                    ->label('Calculate gross compensation')
                    ->icon('heroicon-o-calculator')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (EmployeePaySlip $record): bool => $record->isOpen() && hexa()->can('employee_pay_slip.update'))
                    ->action(function (EmployeePaySlip $record, GrossPayrollPayslipService $service): void {
                        $result = $service->calculate(Employee::query()->findOrFail($record->employee_id), Carbon::parse((string) $record->month)->toImmutable());
                        Notification::make()
                            ->title('Gross compensation calculated')
                            ->body(sprintf('Gross total: %s RON. Calculation version: %d.%s', number_format((float) $result['gross_total'], 2, '.', ','), $result['calculation_version'], $result['warnings'] === [] ? '' : ' '.implode(' ', $result['warnings'])))
                            ->success()
                            ->send();
                    })
                    ->authorize(fn (): bool => hexa()->can('employee_pay_slip.update')),
                Action::make('submitForReview')
                    ->label('Submit for review')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (EmployeePaySlip $record): bool => $record->isOpen() && hexa()->can('payroll_period.review'))
                    ->form([Textarea::make('note')->label('Reviewer note')->maxLength(2000)])
                    ->action(fn (EmployeePaySlip $record, array $data, PayrollPeriodLifecycleService $service) => $service->submitForReview(auth()->user(), $record, $data['note'] ?? null))
                    ->authorize(fn (): bool => hexa()->can('payroll_period.review')),
                Action::make('approvePeriod')
                    ->label('Approve period')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (EmployeePaySlip $record): bool => $record->approval_status === 'in_review' && hexa()->can('payroll_period.approve'))
                    ->requiresConfirmation()
                    ->form([Textarea::make('note')->label('Approval rationale')->required()->maxLength(2000)])
                    ->action(fn (EmployeePaySlip $record, array $data, PayrollPeriodLifecycleService $service) => $service->approve(auth()->user(), $record, $data['note']))
                    ->authorize(fn (): bool => hexa()->can('payroll_period.approve')),
                Action::make('closePeriod')
                    ->label('Close period')
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->visible(fn (EmployeePaySlip $record): bool => $record->approval_status === 'approved' && $record->status === 'Open' && hexa()->can('payroll_period.close'))
                    ->requiresConfirmation()
                    ->form([Textarea::make('note')->label('Close note')->required()->maxLength(2000)])
                    ->action(fn (EmployeePaySlip $record, array $data, PayrollPeriodLifecycleService $service) => $service->close(auth()->user(), $record, $data['note']))
                    ->authorize(fn (): bool => hexa()->can('payroll_period.close')),
                Action::make('exportPayrollReport')
                    ->label('Export payroll report')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (EmployeePaySlip $record): bool => in_array($record->approval_status, ['approved', 'closed'], true) && hexa()->can('payroll_period.export'))
                    ->form([
                        Select::make('run_id')
                            ->label('Approved calculation version')
                            ->options(fn (EmployeePaySlip $record): array => collect((array) (($record->getAttribute('approved_snapshot')['calculation_runs'] ?? [])))
                                ->mapWithKeys(static function (array $run): array {
                                    $id = (int) ($run['id'] ?? 0);
                                    if ($id <= 0) {
                                        return [];
                                    }

                                    return [$id => sprintf('Legacy run #%d · %s · %s', $id, (string) ($run['calculation_version'] ?? 'unknown'), (string) ($run['run_status'] ?? 'unknown'))];
                                })->all() + (isset($record->getAttribute('approved_snapshot')['gross_calculation']['version'])
                                    ? [(int) $record->getAttribute('approved_snapshot')['gross_calculation']['version'] => 'Gross calculation v'.(int) $record->getAttribute('approved_snapshot')['gross_calculation']['version']]
                                    : []))
                            ->required(),
                        Select::make('type')->label('Report')->options([
                            'summary' => 'Internal period summary',
                            'line_items' => 'Calculation line-item detail',
                            'exceptions' => 'Calculation exception list',
                        ])->required(),
                        Select::make('format')->label('Format')->options(['csv' => 'CSV', 'json' => 'JSON'])->default('csv')->required(),
                    ])
                    ->action(fn (EmployeePaySlip $record, array $data) => redirect()->route('payroll.period.exports.download', [
                        'paySlip' => $record->getKey(),
                        'type' => $data['type'],
                        'format' => $data['format'],
                        'run' => $data['run_id'],
                    ]))
                    ->authorize(fn (): bool => hexa()->can('payroll_period.export')),
                EditAction::make()->visible(fn (EmployeePaySlip $record): bool => $record->isOpen() && hexa()->can('employee_pay_slip.update')),
                RecordPaySlipAdjustmentAction::make(),
                RecordPaySlipReversalAction::make(),
                DeleteAction::make()->visible(fn (EmployeePaySlip $record): bool => $record->isOpen() && (int) $record->active_calculation_version === 0 && ! $record->allLineItems()->exists()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
