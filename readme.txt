=== MSC Scheduled Visibility ===
Contributors: djm56
Tags: scheduling,visibility,content,blocks,recurring
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Schedule posts, pages and products to appear and disappear between set dates and times, with recurring windows and role-based visibility.

== Description ==

MSC Scheduled Visibility lets you control when content appears on your site without changing publish status. Features include:

* **Basic scheduling** — Set a "Visible From" and "Visible Until" date/time for any post or page.
* **Block-level scheduling** — Show or hide individual Gutenberg blocks on a schedule via the editor sidebar.
* **Recurring schedules** — Make content appear on a daily, weekly, or monthly repeating pattern.
* **Role-based visibility** — Restrict content to specific user roles or logged-out visitors.
* **WooCommerce products** — Hide scheduled-out products from the shop and block their purchase.
* **Quick and bulk edit** — Set windows from the post list without opening a post.
* **Schedule preview** — View the front end as it will appear at any moment, or to any role.
* **Visibility history** — A log of every show and hide, and what triggered it.
* **REST API** — Read and write schedules programmatically under `mscsv/v1`.
* **Dashboard widget** — See upcoming visibility changes at a glance.
* **Admin column** — View scheduling status directly in the post list.

== Frequently Asked Questions ==

= How do I configure the plugin? =
Navigate to Settings > MSC Scheduled Visibility to configure the plugin settings.

= How do I schedule a post? =
When editing a post, look for the "Scheduled Visibility" metabox in the sidebar. Enable scheduling and set your dates/times.

= How does block-level scheduling work? =
In the block editor, open the "Block Scheduling" sidebar panel (calendar icon). Select a block and configure its visibility schedule.

= Why can I still see a post that should be hidden? =
Users who can edit other people's posts bypass scheduling so they can proof upcoming content. Use the "Preview schedule" control in the post metabox, or view the site logged out, to see what visitors get.

= Does this depend on WP-Cron running? =
No. Cron keeps the stored status current for admin listings, but every page view also checks the schedule live, so visitors never see content past its window even if cron is delayed.

= Why does an archive page sometimes show fewer posts than usual? =
Hidden posts are removed as results are returned rather than excluded in the database query, because whether a post is visible can depend on the current visitor's role. A page set to ten posts will show eight if two of them are outside their window, and the page count can be slightly over-reported. Visibility itself is always correct.

= What timezone do the dates use? =
The site timezone set under Settings > General.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`.
2. Activate in the WordPress plugins screen.
3. Configure from Settings > MSC Scheduled Visibility.

== Changelog ==

= 1.2.2 =
* Tested with WordPress 7.1. No functional changes.

= 1.2.1 =
* Preview bar CSS is now enqueued as a stylesheet instead of printed inline
* Quick Edit row data moved from a JSON script element to data attributes
* Filtered replacement content for hidden posts is escaped with wp_kses_post() before output

= 1.2.0 =
* Added Quick Edit and Bulk Edit support for visibility windows
* Added WooCommerce product scheduling (shop, archives, purchasability and cart)
* Added schedule preview — view the front end at a chosen time or as a chosen role
* Added visibility history log with a History settings tab
* Added a REST API under `mscsv/v1` for reading and writing schedules
* Added an Advanced settings tab with feature toggles and log retention
* Added real diagnostics to the Support tab (cron status, timezone, status counts)
* Fixed a timezone offset error that made every schedule fire wrong by the site's UTC offset
* Fixed block-level scheduling, which never took effect because it ran after blocks were rendered
* Fixed block schedules being applied to the wrong blocks
* Fixed recurring and role rules being ignored unless date scheduling was also enabled
* Fixed archives ignoring recurring windows and role restrictions
* Fixed end dates without a time expiring at the start of the day instead of the end
* Fixed recurring windows that cross midnight, and monthly recurrence on days 29-31
* Batched the cron sweep and added per-request visibility caching

= 1.1.0 =
* Added block-level scheduling via Gutenberg sidebar panel
* Added recurring schedules (daily, weekly, monthly patterns)
* Added role-based visibility (restrict content by user role)
* Added tabbed settings page (General, Block Scheduling, Recurring, Role-Based, Support)
* Added feature toggles for independent enable/disable of each feature
* Added build system with @wordpress/scripts for block editor assets
* All features now in a single unified plugin
* Bumped version to 1.1.0

= 1.0.0 =
* Initial release with full scheduling features.

== Upgrade Notice ==

= 1.2.2 =
Compatibility update: confirmed tested against WordPress 7.1. No functional changes — safe update.

= 1.2.0 =
Important fixes: schedules were firing at the wrong time on any site not set to UTC, and block-level scheduling never worked. Also adds WooCommerce support, quick/bulk edit, preview, history and a REST API.

= 1.1.0 =
Major feature update: block scheduling, recurring patterns, and role-based visibility now included.

= 1.0.0 =
Initial plugin release.
