<?php

namespace App\Filament\Resources\EmployeeContracts\Schemas;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class EmployeeContractInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('employee_id'),
                TextEntry::make('start_date')
                    ->dateTime(),
                TextEntry::make('end_date')
                    ->dateTime(),
                TextEntry::make('compensation_basis')->label('Compensation basis'),
                TextEntry::make('contract_type'),
                TextEntry::make('base_salary'),
                TextEntry::make('hourly_rate'),
                KeyValueEntry::make('clauses'),
            ]);
    }
}
