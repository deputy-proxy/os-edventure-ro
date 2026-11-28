<?php

namespace App\Filament\Resources\EmployeeContracts\Schemas;

use App\Models\EmployeeContract;
use Filament\Forms;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EmployeeContractForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('Contract Parties')
                    ->description('The employee this contract applies to.')
                    ->schema([
                        Select::make('employee_id')
                            ->relationship('employee', 'full_name')
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make('Contract Terms')
                    ->description('Duration and type of employment.')
                    ->schema([
                        Forms\Components\DatePicker::make('start_date')
                            ->required(),
                        Forms\Components\DatePicker::make('end_date')
                            ->required(),
                        Select::make('contract_type')
                            ->required()
                            ->options([
                                'full_time' => 'Full-Time',
                                'part_time' => 'Part-Time',
                                'contractor' => 'Contractor',
                                'intern' => 'Intern',
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Compensation')
                    ->description('Salary and rate details for this contract.')
                    ->schema([
                        Select::make('compensation_basis')
                            ->label('Compensation basis')
                            ->options(EmployeeContract::COMPENSATION_BASES)
                            ->required()
                            ->native(false)
                            ->helperText('Choose the contractual pay basis explicitly. Existing contracts without a basis are blocked from payroll until reviewed.'),
                        Forms\Components\TextInput::make('base_salary')
                            ->label('Monthly base salary')
                            ->minValue(0)
                            ->numeric()
                            ->prefix('RON')
                            ->required(fn (Get $get): bool => $get('compensation_basis') === EmployeeContract::BASIS_MONTHLY)
                            ->visible(fn (Get $get): bool => $get('compensation_basis') === EmployeeContract::BASIS_MONTHLY),
                        Forms\Components\TextInput::make('hourly_rate')
                            ->label('Hourly rate')
                            ->minValue(0)
                            ->numeric()
                            ->prefix('RON')
                            ->required(fn (Get $get): bool => $get('compensation_basis') === EmployeeContract::BASIS_HOURLY)
                            ->visible(fn (Get $get): bool => $get('compensation_basis') === EmployeeContract::BASIS_HOURLY),
                    ])
                    ->columns(2),

                Section::make('Clauses')
                    ->description('Define the legal clauses that apply to this contract.')
                    ->schema([
                        Builder::make('clauses')
                            ->blocks([

                                Block::make('confidentiality')
                                    ->label('Confidentiality & NDA')
                                    ->schema([
                                        Forms\Components\Textarea::make('content')
                                            ->label('Clause Text')
                                            ->required()
                                            ->columnSpanFull(),
                                        Forms\Components\TextInput::make('duration_years')
                                            ->label('Duration After Termination (years)')
                                            ->numeric(),
                                        Forms\Components\CheckboxList::make('scope')
                                            ->label('Scope')
                                            ->options([
                                                'trade_secrets' => 'Trade Secrets',
                                                'client_data' => 'Client Data',
                                                'internal_processes' => 'Internal Processes',
                                                'source_code' => 'Source Code',
                                            ])
                                            ->columns(2),
                                    ])
                                    ->columns(2),

                                Block::make('non_compete')
                                    ->label('Non-Compete')
                                    ->schema([
                                        Forms\Components\Textarea::make('content')
                                            ->label('Clause Text')
                                            ->required()
                                            ->columnSpanFull(),
                                        Forms\Components\TextInput::make('duration_months')
                                            ->label('Duration After Termination (months)')
                                            ->numeric()
                                            ->required(),
                                        Forms\Components\TextInput::make('geographic_scope')
                                            ->label('Geographic Scope')
                                            ->placeholder('e.g. Nationwide, EU, specific cities'),
                                    ])
                                    ->columns(2),

                                Block::make('intellectual_property')
                                    ->label('Intellectual Property Assignment')
                                    ->schema([
                                        Forms\Components\Textarea::make('content')
                                            ->label('Clause Text')
                                            ->required()
                                            ->columnSpanFull(),
                                        Select::make('ownership')
                                            ->label('IP Ownership')
                                            ->options([
                                                'company' => 'Company owns all IP',
                                                'employee' => 'Employee retains IP',
                                                'shared' => 'Shared ownership',
                                            ])
                                            ->required(),
                                        Forms\Components\Toggle::make('includes_prior_work')
                                            ->label('Includes Prior Work'),
                                    ])
                                    ->columns(2),

                                Block::make('termination')
                                    ->label('Termination')
                                    ->schema([
                                        Forms\Components\Textarea::make('content')
                                            ->label('Clause Text')
                                            ->required()
                                            ->columnSpanFull(),
                                        Forms\Components\TextInput::make('notice_period_days')
                                            ->label('Notice Period (days)')
                                            ->numeric()
                                            ->required(),
                                        Select::make('termination_grounds')
                                            ->label('Grounds for Termination')
                                            ->multiple()
                                            ->options([
                                                'performance' => 'Performance',
                                                'misconduct' => 'Misconduct',
                                                'redundancy' => 'Redundancy',
                                                'mutual_agreement' => 'Mutual Agreement',
                                                'end_of_contract' => 'End of Contract',
                                            ]),
                                    ])
                                    ->columns(2),

                                Block::make('remote_work')
                                    ->label('Remote Work Policy')
                                    ->schema([
                                        Forms\Components\Textarea::make('content')
                                            ->label('Clause Text')
                                            ->required()
                                            ->columnSpanFull(),
                                        Select::make('work_mode')
                                            ->label('Work Mode')
                                            ->options([
                                                'fully_remote' => 'Fully Remote',
                                                'hybrid' => 'Hybrid',
                                                'on_site' => 'On-Site',
                                            ])
                                            ->required(),
                                        Forms\Components\TextInput::make('remote_days_per_week')
                                            ->label('Remote Days per Week')
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(5),
                                    ])
                                    ->columns(2),

                                Block::make('probation')
                                    ->label('Probation Period')
                                    ->schema([
                                        Forms\Components\Textarea::make('content')
                                            ->label('Clause Text')
                                            ->required()
                                            ->columnSpanFull(),
                                        Forms\Components\TextInput::make('duration_months')
                                            ->label('Duration (months)')
                                            ->numeric()
                                            ->required(),
                                        Forms\Components\TextInput::make('notice_during_probation_days')
                                            ->label('Notice Period During Probation (days)')
                                            ->numeric(),
                                    ])
                                    ->columns(2),

                                Block::make('use_own_equipment_reimbursement')
                                    ->label('Own Equipment Reimbursement')
                                    ->schema([
                                        Forms\Components\TextInput::make('amount')
                                            ->label('Monthly Reimbursement (RON)')
                                            ->numeric()
                                            ->minValue(0)
                                            ->required(),
                                        Forms\Components\Textarea::make('content')
                                            ->label('Clause Text')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),

                                Block::make('open_clause')
                                    ->label('Custom Clause')
                                    ->schema([
                                        Forms\Components\TextInput::make('title')
                                            ->label('Clause Title')
                                            ->required(),
                                        Forms\Components\Textarea::make('content')
                                            ->label('Clause Text')
                                            ->required()
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),

                            ])
                            ->reorderable()
                            ->collapsible()
                            ->cloneable(),
                    ]),

            ]);
    }
}
