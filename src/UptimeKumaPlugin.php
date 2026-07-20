<?php

namespace UptimeKuma;

use App\Contracts\Plugins\HasPluginSettings;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use UptimeKuma\Models\UptimeKumaSetting;
use UptimeKuma\Services\UptimeKumaClient;
use UptimeKuma\Services\ServerMonitorSynchronizer;

class UptimeKumaPlugin implements HasPluginSettings, Plugin
{
    public function getId(): string
    {
        return 'uptime-kuma';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        $settings = UptimeKumaSetting::current()->toArray();

        // Never send the decrypted password back to the browser. A blank field
        // means that the existing encrypted password should be preserved.
        $settings['password'] = '';

        return $settings;
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make('Connection')->schema([
                TextInput::make('base_url')
                    ->label('Uptime Kuma URL')
                    ->url()
                    ->required()
                    ->default(fn () => UptimeKumaSetting::current()->base_url)
                    ->placeholder('https://status.example.com'),
                TextInput::make('username')
                    ->required()
                    ->default(fn () => UptimeKumaSetting::current()->username),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->placeholder(fn () => filled(UptimeKumaSetting::current()->password)
                        ? 'Saved password (leave blank to keep it)'
                        : 'Enter the Uptime Kuma password')
                    ->helperText(fn () => filled(UptimeKumaSetting::current()->password)
                        ? 'A password is saved. Leave this blank to keep it.'
                        : 'No password is saved yet.'),
                Toggle::make('verify_tls')
                    ->label('Verify TLS certificate')
                    ->default(fn () => UptimeKumaSetting::current()->verify_tls),
                Toggle::make('enabled')
                    ->label('Automatic synchronization')
                    ->default(fn () => UptimeKumaSetting::current()->enabled),
            ])->columns(2),
            Section::make('Status page')->schema([
                TextInput::make('status_page_slug')
                    ->required()
                    ->alphaDash()
                    ->default(fn () => UptimeKumaSetting::current()->status_page_slug),
                TextInput::make('status_page_title')
                    ->required()
                    ->default(fn () => UptimeKumaSetting::current()->status_page_title),
                TextInput::make('group_name')
                    ->required()
                    ->default(fn () => UptimeKumaSetting::current()->group_name),
                TextInput::make('interval')
                    ->numeric()
                    ->minValue(20)
                    ->maxValue(86400)
                    ->required()
                    ->suffix('seconds')
                    ->default(fn () => UptimeKumaSetting::current()->interval),
            ])->columns(2),
            Actions::make([
                Action::make('saveUptimeKumaSettings')
                    ->label('Save settings')
                    ->icon('tabler-device-floppy')
                    ->action(fn (Get $get) => $this->saveSettings($this->settingsFromForm($get))),
                Action::make('testUptimeKumaConnection')
                    ->label('Save and test connection')
                    ->icon('tabler-plug-connected')
                    ->color('gray')
                    ->action(function (Get $get): void {
                        $this->saveSettings($this->settingsFromForm($get));
                        $this->testConnection();
                    }),
                Action::make('syncAllUptimeKumaServers')
                    ->label('Save and sync now')
                    ->icon('tabler-refresh')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('This runs immediately and may take a while when many servers exist.')
                    ->action(function (Get $get): void {
                        $this->saveSettings($this->settingsFromForm($get));
                        $this->syncAllNow();
                    }),
            ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        try {
            DB::transaction(function () use ($data): void {
                $settings = UptimeKumaSetting::current();
                $allowed = collect($data)->only([
                    'base_url', 'username', 'password', 'verify_tls', 'enabled',
                    'status_page_slug', 'status_page_title', 'group_name', 'interval',
                ])->all();

                if (blank($allowed['password'] ?? null)) {
                    unset($allowed['password']);
                }

                if (isset($allowed['status_page_slug'])) {
                    $allowed['status_page_slug'] = strtolower(trim((string) $allowed['status_page_slug']));
                }
                if (isset($allowed['status_page_title'])) {
                    $allowed['status_page_title'] = trim((string) $allowed['status_page_title']);
                }
                if (isset($allowed['group_name'])) {
                    $allowed['group_name'] = trim((string) $allowed['group_name']);
                }

                $settings->fill($allowed)->saveOrFail();
            });

            Notification::make()->title('Uptime Kuma settings saved')->success()->send();
        } catch (Throwable $exception) {
            report($exception);
            Log::error('Uptime Kuma settings could not be saved.', ['exception' => $exception]);
            Notification::make()
                ->title('Could not save Uptime Kuma settings')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    private function testConnection(): void
    {
        $client = new UptimeKumaClient(UptimeKumaSetting::current());
        try {
            $client->connect();
            $settings = UptimeKumaSetting::current();
            $client->ensureStatusPage($settings->status_page_slug, $settings->status_page_title);
            Notification::make()
                ->title('Connected to Uptime Kuma')
                ->body('The configured status page is available. Run Save and sync now to add all servers.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Uptime Kuma connection failed')->body($exception->getMessage())->danger()->send();
        } finally {
            $client->close();
        }
    }

    private function syncAllNow(): void
    {
        if (!UptimeKumaSetting::current()->enabled) {
            Notification::make()
                ->title('Automatic synchronization is disabled')
                ->body('Enable Automatic synchronization, then run Save and sync now again.')
                ->warning()
                ->send();

            return;
        }

        $synchronizer = app(ServerMonitorSynchronizer::class);
        $succeeded = 0;
        $errors = [];

        try {
            $synchronizer->beginBatch();
            Server::query()
                ->whereNotNull('installed_at')
                ->each(function (Server $server) use ($synchronizer, &$succeeded, &$errors): void {
                    try {
                        $synchronizer->sync($server);
                        $succeeded++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $errors[] = "{$server->name}: {$exception->getMessage()}";
                    }
                });
        } catch (Throwable $exception) {
            report($exception);
            $errors[] = 'Uptime Kuma connection: ' . $exception->getMessage();
        } finally {
            $synchronizer->endBatch();
        }

        if ($errors !== []) {
            Notification::make()
                ->title('Uptime Kuma synchronization completed with errors')
                ->body("Synced {$succeeded} server(s). " . implode(' | ', array_slice($errors, 0, 3)))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title('Uptime Kuma synchronization complete')
            ->body("Synced {$succeeded} server(s).")
            ->success()
            ->send();
    }

    /** @return array<string, mixed> */
    private function settingsFromForm(Get $get): array
    {
        return [
            'base_url' => $get('base_url'),
            'username' => $get('username'),
            'password' => $get('password'),
            'verify_tls' => (bool) $get('verify_tls'),
            'enabled' => (bool) $get('enabled'),
            'status_page_slug' => $get('status_page_slug'),
            'status_page_title' => $get('status_page_title'),
            'group_name' => $get('group_name'),
            'interval' => (int) $get('interval'),
        ];
    }
}
