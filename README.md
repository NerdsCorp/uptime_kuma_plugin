# Pelican Uptime Kuma Integration

This Pelican plugin automatically adds your game servers to Uptime Kuma and puts them on a public Uptime Kuma status page.

The plugin does not add a status page or menu item inside the user side of Pelican. Users view server status through your public Uptime Kuma page.

## What it does

- Creates a Kuma monitor when a Pelican server is installed.
- Updates the monitor when the server name or allocation changes.
- Adds the monitor to your Kuma status page.
- Deletes the Kuma monitor when the Pelican server is deleted.
- Keeps stopped and failed servers visible as DOWN.

## Monitor selection

The plugin chooses a monitor automatically using the server's egg tags:

| Egg tags | Kuma monitor |
| --- | --- |
| `manual` | Manual |
| A supported game tag, such as `rust`, `palworld`, `minecraft`, or `minecraftbe` | GameDig |
| `steam` or `steamcmd` and no GameDig match | Steam |
| No matching tag | Manual |

The `manual` tag always wins, even when the egg also has GameDig or Steam tags.

Manual monitors follow the Wings container state:

- `running` is UP.
- Every other state is DOWN.

A Pelican queue worker checks and updates Manual monitors using the interval configured in the plugin settings.

### Query ports

For games with more than one allocation, set the Pelican allocation notes to one of these labels:

- `Query Port`
- `Steam Query Port`

Matching is not case-sensitive. Steam monitors prefer `Steam Query Port`. GameDig monitors prefer `Query Port`. If neither label exists, the plugin uses the server's primary allocation.

## Requirements

- Pelican Panel
- PHP 8.3 or newer
- Uptime Kuma 2.x
- A Kuma username and password without required two-factor authentication
- A running Pelican queue worker


## Installation

1. Import `uptime-kuma.zip` from Pelican's plugin page, or copy the plugin to `plugins/uptime-kuma`.
2. Make sure the file is located at `plugins/uptime-kuma/plugin.json`. Do not place it inside another `uptime-kuma` folder.
3. From the Pelican directory, run:

```bash
php artisan p:plugin:install uptime-kuma
php artisan p:plugin:composer
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

4. Open **Admin → Plugins → Uptime Kuma Integration → Settings**.
5. Enter your Kuma URL, username, and password.
6. Enable **Automatic synchronization**.
7. Select **Save and test connection**.
8. Select **Save and sync now** to import existing servers.

The password field is blank after reloading for security. If it says a password is saved, leave the field blank to keep the existing password.

## Troubleshooting

### `Class "ElephantIO\\Client" not found`

Run:

```bash
php artisan p:plugin:composer
php artisan optimize:clear
```

### Manual status does not update

Make sure the Pelican queue worker is running. Manual status updates stop when the queue worker stops.

### Kuma says `Too frequently, try again later`

Wait for Kuma's temporary login limit to expire. Install the latest plugin version and restart old workers. Current bulk synchronization uses one Kuma connection instead of logging in once for every server.

### Kuma says a monitor does not exist

Run **Save and sync now**. The plugin will replace missing Kuma monitors and update its saved monitor IDs.

### Kuma says the status-page slug already exists

Do not delete the existing page. Install the latest plugin version, clear Laravel's cache, restart PHP and queue workers, then synchronize again.

### SQLite says `database is locked`

Run only one queue worker when Pelican uses SQLite. Redis is recommended for the production queue. MySQL, MariaDB, or PostgreSQL is recommended for a larger Pelican installation.

After changing the queue connection, run:

```bash
php artisan queue:restart
```

## License

This plugin is licensed under the GNU Affero General Public License version 3. See [LICENSE](LICENSE).
