<?php

namespace App\Filament\Resources\EmployeePaySlips\Schemas;

use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmployeePaySlipForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Employee & Period')
                    ->description('The employee and pay period this payslip covers.')
                    ->schema([
                        Select::make('employee_id')->relationship('employee', 'full_name')->required()->disabled(fn (string $operation): bool => $operation === 'edit')->dehydrated()->columnSpanFull(),
                        Forms\Components\DatePicker::make('month')->label('Pay Month')->displayFormat('F Y')->required()->disabled(fn (string $operation): bool => $operation === 'edit')->dehydrated(),
                    ])
                    ->columns(2),
                Section::make('Gross compensation')
                    ->description('Gross compensation is calculated from explicit contract terms and approved timesheets. Statutory deductions, employer contributions, net pay, benefits, and manual payroll inputs are not part of this workflow.')
                    ->schema([
                        Forms\Components\Placeholder::make('gross_calculation_help')
                            ->content('The draft is calculated from contracts and approved timesheets when created. Use Calculate gross compensation in the payslip list to recalculate it; previous versions remain in the audit history.'),
                    ]),
                Section::make('Gross total and due date')
                    ->description('The calculated gross amount and optional due date. Paid status remains unavailable until verified payment evidence is integrated.')
                    ->schema([Forms\Components\TextInput::make('amount')->label('Gross compensation total (RON)')->numeric()->prefix('RON')->disabled()->dehydrated(false), Forms\Components\DatePicker::make('due_on')])
                    ->columns(2),

            ]);
    }
}
