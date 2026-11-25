<?php

namespace App\Filament\Resources\OpportunityScoringProfiles\Schemas;

use Filament\Forms;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class OpportunityScoringProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')->required()->maxLength(120),
            Forms\Components\TextInput::make('version')->numeric()->integer()->minValue(1)->required()->default(1),
            Forms\Components\Select::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'retired' => 'Retired'])->required()->default('draft'),
            Forms\Components\Toggle::make('is_provisional')->default(true)->helperText('Provisional profiles produce heuristic scores, not validated market conclusions.'),
            Forms\Components\Textarea::make('description')->columnSpanFull(),
            Forms\Components\Repeater::make('factors')->schema([
                Forms\Components\TextInput::make('key')->required()->regex('/^[a-z][a-z0-9_]{0,59}$/')->maxLength(60),
                Forms\Components\TextInput::make('label')->required()->maxLength(120),
                Forms\Components\Select::make('source')->options(['market_trend' => 'Market trend observation'])->required()->live(),
                Forms\Components\TextInput::make('weight')->numeric()->integer()->minValue(1)->maxValue(1000)->required()->default(1),
                Forms\Components\Select::make('direction')->options(['higher' => 'Higher values preferred', 'lower' => 'Lower values preferred'])->required()->default('higher'),
                Forms\Components\TextInput::make('minimum_value')->numeric()->required(),
                Forms\Components\TextInput::make('maximum_value')->numeric()->required(),
                Forms\Components\TextInput::make('metric_key')->maxLength(100)->visible(fn (Get $get): bool => $get('source') === 'market_trend')->required(fn (Get $get): bool => $get('source') === 'market_trend'),
                Forms\Components\TextInput::make('market')->required(fn (Get $get): bool => $get('source') === 'market_trend')->maxLength(100)->visible(fn (Get $get): bool => $get('source') === 'market_trend'),
                Forms\Components\TextInput::make('segment')->maxLength(100)->visible(fn (Get $get): bool => $get('source') === 'market_trend'),
                Forms\Components\TextInput::make('max_age_days')->numeric()->integer()->minValue(1)->maxValue(3650)->nullable()->helperText('Optional maximum age for evidence. Older observations are treated as unknown, not zero.'),
            ])->columns(2)->minItems(1)->defaultItems(1)->columnSpanFull()->addActionLabel('Add scoring factor')
                ->helperText('Each factor must identify an evidence source, weight, preferred direction, and explicit numeric scoring range. Missing or stale evidence is excluded from the weighted average.'),
        ]);
    }
}
