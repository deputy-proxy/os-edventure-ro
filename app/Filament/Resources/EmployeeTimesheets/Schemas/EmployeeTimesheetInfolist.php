<?php

namespace App\Filament\Resources\EmployeeTimesheets\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class EmployeeTimesheetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('employee_id'),
                TextEntry::make('date')
                    ->dateTime(),
                TextEntry::make('time_start')
                    ->dateTime(),
                TextEntry::make('time_end')
                    ->dateTime(),
                TextEntry::make('category'),
                TextEntry::make('number_of_hours'),
                TextEntry::make('approval_status'),
                TextEntry::make('reviewer.name'),
                TextEntry::make('reviewed_at')->dateTime(),
                TextEntry::make('session.identifier'),
            ]);
    }
}
