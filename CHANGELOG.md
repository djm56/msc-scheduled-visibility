# Changelog

All notable changes to MSC Scheduled Visibility are documented in this file.

## [1.2.3] - 2026-08-27

Added 8 new languages (Russian, Simplified Chinese, Turkish, Polish, Indonesian, Swedish, Ukrainian, Arabic) and refreshed all 20 bundled locales to 100% string coverage. Fixed JavaScript translation loading for the block editor sidebar (per-script .json translation files now generated).

## [1.2.2] - 2026-08-27

### Changed

- Confirmed compatibility with WordPress 7.1 — "Tested up to" header updated to 7.1. No functional changes.

## [1.2.1] - 2026-08-19

### Changed

- Preview bar styles moved out of an inline `wp_head` block into an enqueued stylesheet (`assets/css/preview.css`)
- Quick Edit row data now travels in data attributes on the visibility column instead of a JSON script element
- The metabox preview hint takes its spacing from `admin.css` rather than a `style` attribute

### Fixed

- Output of the `mscsv_hidden_content_message` filter is run through `wp_kses_post()` before it is returned into `the_content`

## [1.2.0] - 2026-08-07

### Added

- Quick Edit support — set a visibility window straight from the post list table
- Bulk Edit support — apply one window to many posts, with "no change" defaults so blank fields are left alone
- WooCommerce product scheduling — hides scheduled-out products from the shop, product archives and `[products]` queries, blocks purchase, and clears them from the cart at checkout
- Schedule preview — open the front end as it will appear at a chosen moment, or to a chosen role, from a control in the post metabox
- Preview notice bar on the front end with an exit link; preview requests are marked `noindex`
- Visibility history — a capped, per-transition log of every show/hide, with the actor that caused it, on a new History tab
- REST API under `mscsv/v1`: read, update and delete a post's schedule, and list scheduled content
- Advanced settings tab with toggles for preview, history (and its retention limit), and the REST API
- WooCommerce settings tab (shown only when WooCommerce is active)
- Real diagnostics on the Support tab: versions, site timezone, current site time, next cron run, `DISABLE_WP_CRON` warning, and post counts by status
- `Recurring` and `Roles` indicator badges in the post list Visibility column
- New `mscsv_status_changed` action, and `mscsv_current_time` / `mscsv_user_can_bypass` filters
- Settings link on the plugins screen
- Cron self-heal: a missing check event is rescheduled on load

### Fixed

- **Timezone offset error in every date comparison.** `current_time( 'timestamp' )` returns an offset-shifted value, which was being compared against true Unix timestamps from `wp_timezone()`. Schedules fired wrong by the site's UTC offset — two hours out for SAST. All comparisons now use a real epoch.
- **Block-level scheduling never worked.** The block filter ran on `the_content` at priority 15, but core renders blocks at priority 9, so `parse_blocks()` only ever saw rendered HTML. It now runs at priority 8 and re-serialises the surviving blocks.
- **Block schedules applied to the wrong blocks.** `parse_blocks()` emits nameless nodes for inter-block whitespace while the editor's `getBlocks()` does not, so stored indices drifted. Nameless nodes no longer consume an index.
- **Recurring and role rules were ignored unless date scheduling was also on.** `is_post_visible()` returned early for posts with `_mscsv_enabled` unset, skipping the `mscsv_is_visible` filter entirely — a post restricted only by role was public to everyone.
- **Recurring rules could reveal a post its dates had hidden.** The recurrence check replaced the base verdict instead of narrowing it.
- **Archives ignored recurring windows and role rules.** List queries filtered only on the cron-written status; a `the_posts` pass now settles both.
- End dates with no time now mean "through the end of that day" rather than midnight at its start, matching what the admin column already displayed.
- Recurring time windows that cross midnight (e.g. 22:00–02:00) are handled.
- Monthly recurrence on day 29–31 now fires on the last day of shorter months instead of being skipped.
- `$_POST` and `$_GET` values are unslashed before sanitisation throughout.
- `save_extended_meta()` gained the post-type guard its sibling already had.
- The cron sweep is batched instead of loading every scheduled post at once.
- The dashboard widget ordered by `_mscsv_enabled` (always "1"); it now orders by start date.
- Status labels are translatable rather than `ucfirst()` of the stored key.
- Dates in the admin now render with the site's configured date and time formats.

### Changed

- Bumped version to 1.2.0
- Schedule writing is centralised in `set_base_schedule()`, `set_recurring_schedule()` and `set_role_rules()`, shared by the metabox, quick edit, bulk edit and REST
- Status recomputation is centralised in `refresh_status()`, which is also what fires the transition hooks
- Stored status now accounts for the recurring window, making `hidden` a reachable state
- Per-request visibility caching added to avoid recomputing the same post repeatedly
- Module hooks are skipped entirely when the module is disabled
- Uninstall removes the new `mscsv_audit_log` and `mscsv_version` options

## [1.1.0] - 2026-05-01

### Added

- Block-level scheduling via Gutenberg sidebar panel (schedule individual blocks to appear/disappear)
- Recurring schedules with daily, weekly, and monthly patterns
- Time window support for recurring schedules (start/end times per recurrence)
- Validity date boundaries for recurring schedules (valid from/until)
- Role-based visibility (restrict content to specific user roles)
- Logged-out visitor toggle for role-based visibility
- Tabbed settings page under Settings > MSC Scheduled Visibility (General, Block Scheduling, Recurring, Role-Based, Support)
- Block editor React sidebar for per-block schedule management
- Feature toggles to enable/disable block scheduling, recurring, and role-based features independently
- Extended metabox fields for recurring and role configuration
- Content filtering for individual blocks on the frontend (parse_blocks + render_block)
- Recurring visibility filter hook (`mscsv_is_visible`) with daily/weekly/monthly pattern checks
- Role-based visibility filter hook with admin/editor bypass
- Block schedule meta registered for REST API (`_mscsv_block_schedules`)
- Build system with @wordpress/scripts for block editor assets
- npm build/start scripts for development workflow

### Changed

- Bumped version to 1.1.0
- All features now included in a single unified plugin (no separate add-on or premium version)
- Settings page moved to Settings > MSC Scheduled Visibility (using `add_options_page`)
- Default options now include `block_scheduling_enabled`, `recurring_enabled`, and `roles_enabled`
- Admin CSS updated with extended metabox section styles
- Admin JS updated with recurring toggle, pattern switcher, and roles toggle handlers
- Package script updated to include build directory in distribution zip

### Removed

- Removed separate pro plugin dependency
- Removed upgrade prompts and pro callouts from settings page
- Removed `is_pro_active()` and `has_feature()` methods
- Removed pro-related constants and option keys

## [1.0.0] - 2026-03-26

### Added

- Initial public release
- Date/time scheduling for posts and pages (Visible From / Visible Until)
- Automatic visibility control via WordPress cron (every 5 minutes)
- Admin metabox with enable toggle and date/time pickers
- Visibility status column in post list tables
- Dashboard widget showing scheduled posts and upcoming transitions
- Frontend query filtering (pre_get_posts meta_query)
- Single post visibility filtering (posts_results)
- Content safety net filter (the_content)
- Settings page for post type selection and module toggle
- Cron job registration and status recomputation
- Clean uninstall with full data removal
