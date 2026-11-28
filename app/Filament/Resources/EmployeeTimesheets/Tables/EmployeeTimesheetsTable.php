<?php

namespace App\Filament\Resources\EmployeeTimesheets\Tables;

use App\Filament\Resources\Tasks\Actions\TasksAction;
use App\Models\EmployeeTimesheet;
use App\Services\EmployeeTimesheetService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables;
use Filament\Tables\Table;
use Relaticle\Comments\Filament\Actions\CommentsAction;

class EmployeeTimesheetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.full_name')
                    ->label('Employee')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('date')
                    ->dateTime(),
                Tables\Columns\TextColumn::make('time_start')
                    ->dateTime(),
                Tables\Columns\TextColumn::make('time_end')
                    ->dateTime(),
                Tables\Columns\TextColumn::make('category'),
                Tables\Columns\TextColumn::make('number_of_hours')->suffix(' h'),
                Tables\Columns\TextColumn::make('approval_status')->badge(),
                Tables\Columns\TextColumn::make('session.identifier')
                    ->label('Session')
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('approveForPayroll')
                    ->label('Approve for payroll')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (EmployeeTimesheet $record): bool => $record->approval_status === EmployeeTimesheet::APPROVAL_PENDING && hexa()->can('employee_timesheet.approve'))
                    ->action(fn (EmployeeTimesheet $record, EmployeeTimesheetService $service) => $service->approve(auth()->user(), $record))
                    ->authorize(fn (): bool => hexa()->can('employee_timesheet.approve')),
                Action::make('rejectForPayroll')
                    ->label('Reject timesheet')
                    ->color('danger')
                    ->schema([Textarea::make('review_note')->required()->maxLength(1000)])
                    ->visible(fn (EmployeeTimesheet $record): bool => $record->approval_status === EmployeeTimesheet::APPROVAL_PENDING && hexa()->can('employee_timesheet.approve'))
                    ->action(fn (EmployeeTimesheet $record, array $data, EmployeeTimesheetService $service) => $service->reject(auth()->user(), $record, $data['review_note']))
                    ->authorize(fn (): bool => hexa()->can('employee_timesheet.approve')),
                EditAction::make()->visible(fn (EmployeeTimesheet $record): bool => $record->approval_status === EmployeeTimesheet::APPROVAL_PENDING),
                DeleteAction::make()->visible(fn (EmployeeTimesheet $record): bool => $record->approval_status === EmployeeTimesheet::APPROVAL_PENDING),
                TasksAction::make(),
                CommentsAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
