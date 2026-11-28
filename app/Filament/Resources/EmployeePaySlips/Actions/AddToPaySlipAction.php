<?php

namespace App\Filament\Resources\EmployeePaySlips\Actions;

use App\Filament\Resources\EmployeeTimesheets\Actions\CreateEmployeeTimesheetAction;
use Filament\Actions\Action;

/** @deprecated The legacy action name now delegates to the timesheet-first workflow. */
class AddToPaySlipAction
{
    public static function make(): Action
    {
        return CreateEmployeeTimesheetAction::make();
    }
}
