<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255),
                Forms\Components\DateTimePicker::make('email_verified_at'),
                Forms\Components\TextInput::make('password')
                    ->password()
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create')
                    ->maxLength(255),
                Forms\Components\Select::make('role')
                    ->options([
                        User::ROLE_ADMIN => 'Administrador',
                        User::ROLE_USER => 'Usuario',
                    ])
                    ->required()
                    ->default(User::ROLE_USER),
                Forms\Components\Select::make('notify_mode')
                    ->label('Notificaciones de caída')
                    ->options([
                        User::NOTIFY_ALL => 'Todas las webs',
                        User::NOTIFY_SELECTED => 'Sólo las webs seleccionadas',
                        User::NOTIFY_NONE => 'Ninguna',
                    ])
                    ->required()
                    ->live()
                    ->default(User::NOTIFY_ALL),
                Forms\Components\Select::make('notifiedMonitors')
                    ->label('Webs seleccionadas')
                    ->relationship('notifiedMonitors', 'name')
                    ->multiple()
                    ->preload()
                    ->visible(fn (Forms\Get $get): bool => $get('notify_mode') === User::NOTIFY_SELECTED),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        User::ROLE_ADMIN => 'danger',
                        User::ROLE_USER => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('notify_mode')
                    ->label('Notificaciones')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        User::NOTIFY_ALL => 'Todas',
                        User::NOTIFY_SELECTED => 'Seleccionadas',
                        User::NOTIFY_NONE => 'Ninguna',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        User::NOTIFY_ALL => 'success',
                        User::NOTIFY_SELECTED => 'warning',
                        User::NOTIFY_NONE => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('notifiedMonitors.name')
                    ->label('Webs')
                    ->badge()
                    ->separator(',')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
