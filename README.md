# MSC Scheduled Visibility

Schedule posts, pages and products to appear and disappear between specific dates and times. Includes block-level scheduling, recurring schedules, and role-based visibility.

## Features

- Basic post/page scheduling with "Visible From" and "Visible Until" dates
- Block-level scheduling via Gutenberg sidebar panel
- Recurring schedules (daily, weekly, monthly patterns)
- Role-based visibility (restrict content by user role)
- WooCommerce product scheduling (shop, archives, purchasability, cart)
- Quick Edit and Bulk Edit for visibility windows
- Schedule preview — view the front end at a chosen time or as a chosen role
- Visibility history log
- REST API under `mscsv/v1`
- Dashboard widget and admin column for schedule status

## How visibility is decided

Two mechanisms work together:

- **Stored status.** A cron event every five minutes writes `_mscsv_status` for each
  scheduled post. List queries filter on it at SQL level, which keeps archives cheap.
- **Live check.** Every page view also evaluates the schedule directly through
  `Module::is_post_visible()`. This is what settles role rules (which vary per visitor
  and cannot be stored) and closes the up-to-five-minute gap between cron runs.

Users who can `edit_others_posts` bypass both so they can proof upcoming content. The
preview feature switches that bypass off deliberately.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `mscsv_is_visible` | filter | Final say on whether a post is visible to the current visitor |
| `mscsv_current_time` | filter | The reference timestamp for every date comparison; the preview feature overrides it |
| `mscsv_user_can_bypass` | filter | Whether the current user ignores scheduling |
| `mscsv_hidden_content_message` | filter | Replacement content for a hidden post |
| `mscsv_status_changed` | action | Fires on any stored status transition |
| `mscsv_before_hide_post` | action | Fires when a post transitions away from visible |
| `mscsv_after_show_post` | action | Fires when a post becomes visible |
| `mscsv_metabox_after_fields` | action | Append fields to the scheduling metabox |
| `mscsv_visibility_column` | action | Append output to the post list Visibility column |

## REST API

All routes require an authenticated user with edit rights on the post concerned.

| Method | Route |
|---|---|
| `GET` | `/wp-json/mscsv/v1/schedule/<id>` |
| `POST` | `/wp-json/mscsv/v1/schedule/<id>` |
| `DELETE` | `/wp-json/mscsv/v1/schedule/<id>` |
| `GET` | `/wp-json/mscsv/v1/scheduled` |

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress plugins screen.
3. Configure settings under Settings > MSC Scheduled Visibility.

## Development

- `composer lint`
- `composer lint-fix`
- `composer i18n:pot`
- `npm run build` — Build the block editor JavaScript
- `npm run start` — Start the block editor JavaScript in watch mode
- `npm run lint`
- `npm run lint-fix`
- `npm run i18n:pot`

## Translations

- Translation files live in `languages/`.
- A starter POT file is scaffolded as `languages/msc-scheduled-visibility.pot`.
- Regenerate the POT file after string changes with `composer i18n:pot`.
