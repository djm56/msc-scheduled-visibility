# MSC Scheduled Visibility

![Version](https://img.shields.io/badge/version-1.2.4-blue)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-green)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)
![WordPress](https://img.shields.io/badge/WordPress-5.9%2B-blue)
![Tested up to](https://img.shields.io/badge/tested%20up%20to-7.1-blue)

Schedule posts, pages and products to appear and disappear between specific dates and times. Includes block-level scheduling, recurring schedules, and role-based visibility.

**All features are free. There is no premium version.**

## Index

- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [How Visibility Is Decided](#how-visibility-is-decided)
- [Developer Reference](#developer-reference)
- [Development](#development)
- [Changelog](#changelog)
- [License](#license)

## Features

- **Visibility Windows** — Set "Visible From" and "Visible Until" dates and times on posts and pages
- **Block-Level Scheduling** — Schedule individual blocks through a Gutenberg sidebar panel
- **Recurring Schedules** — Daily, weekly, and monthly patterns with a validity range
- **Role-Based Visibility** — Restrict content to selected user roles, including logged-out visitors
- **WooCommerce Product Scheduling** — Control shop and archive listing, purchasability, and cart behaviour
- **Quick Edit and Bulk Edit** — Set visibility windows without opening the editor
- **Schedule Preview** — View the front end at a chosen time, or as a chosen role
- **Visibility History** — A log of every stored status transition
- **REST API** — Read and write schedules under `mscsv/v1`
- **Admin Surfaces** — Dashboard widget and a Visibility column in the post list

## Installation

### From WordPress Admin

1. Download the plugin zip file
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and click **Install Now**
4. Click **Activate**

### Manual Installation

1. Upload the `msc-scheduled-visibility` folder to `/wp-content/plugins/`
2. Activate via the **Plugins** menu in WordPress

### Post-Activation

1. Go to **Settings → MSC Scheduled Visibility**
2. Choose the post types that support scheduling
3. Confirm WordPress cron is running — the stored-status pass depends on it

## Configuration

The plugin has up to 8 settings tabs. The WooCommerce tab appears only when WooCommerce is active.

### General Tab

| Option | Description | Default |
|--------|-------------|---------|
| Enable Scheduled Visibility | Master toggle for the plugin | Enabled |
| Post Types | The post types that support visibility windows | `post`, `page` |

### Block Scheduling Tab

| Option | Description | Default |
|--------|-------------|---------|
| Enable Block Scheduling | Allow per-block schedules in the block editor | Enabled |

### Recurring Tab

| Option | Description | Default |
|--------|-------------|---------|
| Enable Recurring Schedules | Allow daily, weekly, and monthly patterns | Enabled |

### Role-Based Tab

| Option | Description | Default |
|--------|-------------|---------|
| Enable Role-Based Visibility | Restrict content by user role | Enabled |

### WooCommerce Tab

| Option | Description | Default |
|--------|-------------|---------|
| Enable Product Scheduling | Apply schedules to WooCommerce products | Enabled |

Product scheduling only takes effect once the `product` post type is added on the **General** tab.

### Advanced Tab

| Option | Description | Default |
|--------|-------------|---------|
| Enable Preview | Allow previewing the front end at a chosen time or role | Enabled |
| Enable History | Record stored status transitions | Enabled |
| History Limit | Maximum entries kept in the history log | 200 |
| Enable REST API | Expose the `mscsv/v1` routes | Enabled |

### History Tab

The recorded visibility transitions, newest first. Recording is switched off when **Enable History** is disabled on the Advanced tab.

### Support Tab

Setup instructions, feature explanations, FAQ, and contact info.

## How Visibility Is Decided

Two mechanisms work together:

- **Stored status.** A cron event writes `_mscsv_status` for each scheduled post. List queries filter on it at SQL level, which keeps archives cheap.
- **Live check.** Every page view also evaluates the schedule directly through `Module::is_post_visible()`. This settles role rules — which vary per visitor and cannot be stored — and closes the gap between cron runs.

Users who can `edit_others_posts` bypass both so they can proof upcoming content. The preview feature switches that bypass off deliberately.

## Developer Reference

### Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `MSCSV_PLUGIN_VERSION` | `'1.2.4'` | Current plugin version |
| `MSCSV_PLUGIN_FILE` | `__FILE__` | Absolute path to main plugin file |
| `MSCSV_PLUGIN_DIR` | Plugin directory path | Absolute path to plugin directory |
| `MSCSV_PLUGIN_URL` | Plugin directory URL | URL to plugin directory |

### Plugin Options

All options are stored as a single serialised array under the `mscsv_options` key. Access via:

```php
$options = get_option( 'mscsv_options' );
```

Or via the plugin API:

```php
$plugin = MSC_Scheduled_Visibility\Plugin::instance();
$value  = $plugin->get_option( 'audit_log_limit', 200 );
```

| Option Key | Type | Default | Description |
|------------|------|---------|-------------|
| `module_enabled` | `int` | `1` | Enable/disable scheduled visibility (1/0) |
| `post_types` | `array` | `['post','page']` | Post types that support visibility windows |
| `block_scheduling_enabled` | `int` | `1` | Enable per-block schedules (1/0) |
| `recurring_enabled` | `int` | `1` | Enable recurring schedule patterns (1/0) |
| `roles_enabled` | `int` | `1` | Enable role-based visibility (1/0) |
| `woocommerce_enabled` | `int` | `1` | Apply schedules to WooCommerce products (1/0) |
| `preview_enabled` | `int` | `1` | Enable the schedule preview feature (1/0) |
| `audit_log_enabled` | `int` | `1` | Record visibility history (1/0) |
| `audit_log_limit` | `int` | `200` | Maximum history entries retained |
| `rest_enabled` | `int` | `1` | Expose the `mscsv/v1` REST routes (1/0) |

The history log itself is stored under `mscsv_audit_log`, and the installed version under `mscsv_version`.

### Post Meta

| Meta Key | Description |
|----------|-------------|
| `_mscsv_enabled` | Whether a schedule is active on the post |
| `_mscsv_status` | Cached visibility status written by cron |
| `_mscsv_start_date` / `_mscsv_start_time` | Start of the visibility window |
| `_mscsv_end_date` / `_mscsv_end_time` | End of the visibility window |
| `_mscsv_recurring_enabled` | Whether a recurring pattern applies |
| `_mscsv_recurring_pattern` | `daily`, `weekly`, or `monthly` |
| `_mscsv_recurring_days` | Weekdays for the weekly pattern |
| `_mscsv_recurring_day_of_month` | Day for the monthly pattern |
| `_mscsv_recurring_start_time` / `_mscsv_recurring_end_time` | Daily window for the pattern |
| `_mscsv_recurring_valid_from` / `_mscsv_recurring_valid_until` | Date range the pattern applies to |
| `_mscsv_roles_enabled` | Whether role restrictions apply |
| `_mscsv_roles` | The roles allowed to see the post |
| `_mscsv_roles_logged_out` | Whether logged-out visitors are allowed |
| `_mscsv_block_schedules` | Per-block schedule data |

### Custom Actions

| Action | Parameters | Description |
|--------|------------|-------------|
| `mscsv_status_changed` | Post and status context | Fires on any stored status transition |
| `mscsv_before_hide_post` | Post context | Fires when a post transitions away from visible |
| `mscsv_after_show_post` | Post context | Fires when a post becomes visible |
| `mscsv_metabox_after_fields` | Post context | Append fields to the scheduling metabox |
| `mscsv_visibility_column` | Post context | Append output to the post list Visibility column |

### Plugin Filters

| Filter | Description |
|--------|-------------|
| `mscsv_is_visible` | Final say on whether a post is visible to the current visitor |
| `mscsv_current_time` | The reference timestamp for every date comparison; the preview feature overrides it |
| `mscsv_user_can_bypass` | Whether the current user ignores scheduling |
| `mscsv_hidden_content_message` | Replacement content for a hidden post |

```php
// Never hide a specific post, whatever its schedule says.
add_filter( 'mscsv_is_visible', function( $visible, $post_id ) {
    return ( 99 === (int) $post_id ) ? true : $visible;
}, 10, 2 );
```

### REST API

All routes require an authenticated user with edit rights on the post concerned.

| Method | Route | Purpose |
|--------|-------|---------|
| `GET` | `/wp-json/mscsv/v1/schedule/<id>` | Read a post's schedule |
| `POST` | `/wp-json/mscsv/v1/schedule/<id>` | Create or update a post's schedule |
| `DELETE` | `/wp-json/mscsv/v1/schedule/<id>` | Remove a post's schedule |
| `GET` | `/wp-json/mscsv/v1/scheduled` | List scheduled posts |

The routes are registered only while **Enable REST API** is on.

### Cron Event

| Hook | Recurrence | Purpose |
|------|------------|---------|
| `mscsv_cron_check` | `mscsv_fifteen_minutes` | Refresh the stored `_mscsv_status` for scheduled posts |

Releases up to 1.2.0 used a `mscsv_five_minutes` recurrence; the plugin reschedules the event automatically on upgrade.

### Uninstall Behaviour

On plugin deletion (not deactivation), the plugin removes:

- `mscsv_options`, `mscsv_audit_log`, `mscsv_version` from `wp_options`
- The status-term backfill markers `mscsv_status_terms_backfilled` and `mscsv_status_terms_backfill_page`
- Every `_mscsv_*` post meta key, via `delete_post_meta_by_key()` so object caches are invalidated
- The scheduled `mscsv_cron_check` event

## Development

### Requirements

- PHP 7.4+
- Composer
- Node.js and npm (for the block editor build)
- WP-CLI (for .pot generation)

### Setup

```bash
cd msc-scheduled-visibility
composer install
npm install
```

### Building

```bash
# Build the block editor JavaScript
npm run build

# Rebuild on change
npm run start
```

### Linting

```bash
# Check PHP coding standards (WordPress-Core, WordPress-Docs, WordPress-Extra)
composer lint

# Auto-fix fixable PHP issues
composer lint-fix
```

`npm run lint` and `npm run lint-fix` proxy to the Composer scripts above.

### Translations

The plugin ships with 20 bundled language translations, all at 100% string coverage:

| Locale | Language |
|--------|----------|
| `ar` | Arabic |
| `de_CH` | Swiss German |
| `de_DE` | German |
| `es_ES` | Spanish (Spain) |
| `es_MX` | Spanish (Mexico) |
| `fr_CA` | French (Canada) |
| `fr_FR` | French (France) |
| `id_ID` | Indonesian |
| `it_IT` | Italian |
| `ja` | Japanese |
| `nl_BE` | Dutch (Belgium) |
| `nl_NL` | Dutch (Netherlands) |
| `pl_PL` | Polish |
| `pt_BR` | Portuguese (Brazil) |
| `pt_PT` | Portuguese (Portugal) |
| `ru_RU` | Russian |
| `sv_SE` | Swedish |
| `tr_TR` | Turkish |
| `uk` | Ukrainian |
| `zh_CN` | Chinese (Simplified) |

- Translation files live in `languages/`.
- The POT template is `languages/msc-scheduled-visibility.pot`.
- Regenerate it after string changes:

```bash
composer i18n:pot
```

To contribute a locale, copy the POT to `languages/msc-scheduled-visibility-{locale}.po`, translate it, then compile:

```bash
wp i18n make-mo languages/
```

### Composer Scripts

| Script | Command | Description |
|--------|---------|-------------|
| `lint` | `composer lint` | Run PHP_CodeSniffer |
| `lint-fix` | `composer lint-fix` | Auto-fix coding standard issues |
| `i18n:pot` | `composer i18n:pot` | Regenerate .pot file from source |

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

GPL-2.0+ — see [LICENSE](LICENSE) for details.
