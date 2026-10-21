<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password;

/**
 * "My Account": every signed-in user (CRS Officers included) edits their own name, email and password here.
 * Role, home branch and active status stay with the Administrator in Staff Accounts.
 */
class EditProfile extends BaseEditProfile
{
    public function getTitle(): string|Htmlable
    {
        return 'My Account';
    }

    public static function getLabel(): string
    {
        return 'My Account';
    }

    public function form(Form $form): Form
    {
        return $form
            ->inlineLabel(false)
            ->schema([
                Section::make('Profile')
                    ->id('profile-section')
                    ->description('Your name as it appears in the system, and the email you sign in with.')
                    ->columns(2)
                    ->schema([
                        $this->getNameFormComponent()->label('Full name'),
                        $this->getEmailFormComponent()->label('Email (sign-in)'),
                    ]),
                Section::make('Password')
                    ->id('password-section')
                    ->description('Leave blank to keep your current password.')
                    ->columns(2)
                    ->schema([
                        $this->getPasswordFormComponent()
                            ->label('New password')
                            ->rule(Password::defaults())
                            ->helperText('At least 8 characters, with letters and numbers.'),
                        $this->getPasswordConfirmationFormComponent()->label('Confirm new password'),
                    ]),
                Section::make('Confirm it is you')
                    ->id('confirm-section')
                    ->description('Enter your current password to change your email or password.')
                    ->visible(fn (Get $get): bool => filled($get('password')) || $get('email') !== $this->getUser()->email)
                    ->schema([
                        $this->getCurrentPasswordFormComponent(),
                    ]),
            ]);
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return TextInput::make('currentPassword')
            ->label('Current password')
            ->password()
            ->revealable()
            ->required()
            ->currentPassword()
            ->autocomplete('current-password')
            ->dehydrated(false);
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->live(onBlur: true);
    }

    /**
     * Only a Save button: the sidebar and breadcrumbs already lead away from the page.
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
        ];
    }
}
