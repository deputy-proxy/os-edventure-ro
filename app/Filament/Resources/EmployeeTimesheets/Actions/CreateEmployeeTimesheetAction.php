<?php

namespace App\Filament\Resources\EmployeeTimesheets\Actions;

use App\Models\Session;
use App\Models\SessionAssistance;
use App\Models\TestSimulation;
use App\Services\EmployeeTimesheetService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Kept as the existing table action class name; it now creates an operational timesheet, never a payslip line. */
class CreateEmployeeTimesheetAction
{
    public static function make(): Action
    {
        return Action::make('createTimesheet')
            ->label('Create timesheet')
            ->icon('heroicon-o-clock')
            ->requiresConfirmation()
            ->visible(fn (Model $record): bool => hexa()->can('employee_timesheet.create') && self::supports($record))
            ->action(function (Model $record, EmployeeTimesheetService $service): void {
                abort_unless(hexa()->can('employee_timesheet.create'), 403);

                try {
                    $result = match (true) {
                        $record instanceof Session, $record instanceof SessionAssistance, $record instanceof TestSimulation => $service->createFromActivity($record),
                        default => throw ValidationException::withMessages(['source' => 'This activity cannot create a timesheet.']),
                    };
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Could not create timesheet')
                        ->body(collect($exception->errors())->flatten()->first() ?? 'The activity is not eligible for a timesheet.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($result['created'] ? 'Timesheet created' : 'Timesheet already exists')
                    ->body($result['created'] ? 'The activity was linked to a pending timesheet. It must be approved before payroll can use it.' : 'This activity is already linked to its timesheet.')
                    ->success()
                    ->send();
            });
    }

    private static function supports(Model $record): bool
    {
        if ($record instanceof TestSimulation) {
            return strtolower((string) $record->status) === 'completed';
        }

        if ($record instanceof SessionAssistance) {
            return in_array(strtolower(trim((string) $record->status)), ['complete', 'completed'], true)
                && $record->session !== null;
        }

        if ($record instanceof Session) {
            return in_array(strtolower(trim((string) $record->status)), ['complete', 'completed'], true);
        }

        return false;
    }
}
