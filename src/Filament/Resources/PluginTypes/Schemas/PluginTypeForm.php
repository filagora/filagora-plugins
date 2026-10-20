<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginTypes\Schemas;

use Filagora\Entitlements\Models\EntitlementDefinition;
use Filagora\Entitlements\Registry\EntitlementRegistry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PluginTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(120)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state): void {
                    if (($get('slug') ?? '') === Str::slug((string) $old)) {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('slug')->required()->maxLength(64)->unique(ignoreRecord: true),
            Textarea::make('description')->columnSpanFull(),
            self::scopeField(),
            TextInput::make('position')->required()->numeric()->minValue(0)->default(0),
            Toggle::make('is_active')->default(true),
        ]);
    }

    /**
     * The entitlement a download needs: picked from the definitions when `filagora-entitlements` is
     * installed, typed in otherwise.
     */
    private static function scopeField(): Select|TextInput
    {
        $help = __('filagora-plugins::messages.types.scope_help');

        if (class_exists(EntitlementDefinition::class) && app()->bound(EntitlementRegistry::class)) {
            return Select::make('required_entitlement_scope')
                ->options(fn (): array => EntitlementDefinition::selectorOptions())
                ->searchable()
                ->helperText($help);
        }

        return TextInput::make('required_entitlement_scope')->maxLength(255)->helperText($help);
    }
}
