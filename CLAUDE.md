# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

This is the **theme directory only** for a WordPress site (`recipes.godin.com`) — a "Divi Recipe Child" child theme built on top of the commercial Divi parent theme. The repo root corresponds to `wp-content/themes/divi-recipe-child/` on the live server, not a full WordPress install. There is no local WordPress environment, no `wp-config.php`, and no database in this repo — everything runs against the live/staging server directly.

The site is a family recipe manager (multi-user recipe collections, sharing, categories, printing) with a newer **Allergen Checker** feature layered on top for a real medical use case (checking recipes/products against a person's allergen profile). See `docs/allergen-checker-spec.md` (v1, approved) and `docs/allergen-checker-v2-spec.md` (draft, not started) for the feature's design intent and non-negotiable safety principles (never claim "safe," no automatic/background checking, always show the disclaimer, always show the exact matched text).

**Live server paths** (Plesk-style layout):
- WP root: `/var/www/vhosts/godin.com/recipes.godin.com`
- This theme: `/var/www/vhosts/godin.com/recipes.godin.com/wp-content/themes/divi-recipe-child`
- **DB table prefix is `ohChPp_`, not the WordPress default `wp_`** — never hardcode `wp_` in SQL examples; code should always go through `$wpdb->prefix`, which every existing function in this codebase already does.

## Commands

There is no build step, no package manager, no linter config, and no automated test suite (no `package.json`, `composer.json`, or `phpunit.xml`).

- **Syntax-check a PHP file before considering an edit done**: `php -l path/to/file.php`
- **Run a one-off CLI utility script** (migrations, data fixes, throwaway verification scripts): these are guarded with
  ```php
  if (!isset($argc) || php_sapi_name() !== 'cli') { return; }
  ```
  and bootstrap WordPress themselves, so they must be run via the CLI, from the theme directory, with the WP root as the first argument:
  ```bash
  php some-script.php /var/www/vhosts/godin.com/recipes.godin.com
  ```
  Existing examples: `migrate-to-custom-categories.php`, `migrate-allergen-checker.php`, `cleanup-orphaned-relationships.php`, `assign-recipe-ids.php`. This is also the pattern to follow for any throwaway diagnostic/verification script (e.g. calling a permission-check function directly to confirm it rejects a non-owner, bypassing HTTP/nonces entirely) — write it, run it on the server, delete it when done.
- **No automated tests exist.** Verification is manual: PHP lint, then functional QA against the live site (there is no staging DB snapshot workflow documented here).

## Architecture

### Flat procedural PHP, no framework

Every file in the theme root is a flat, procedural PHP file — no classes, no namespaces, no autoloading, no dependency injection. Shared logic lives in plain functions in files named `*-functions.php` or `*-permissions.php` (e.g. `custom-category-functions.php`, `collection-permissions.php`, `allergen-functions.php`, `allergen-permissions.php`), and every such file is wired in via an explicit `require_once` in `functions.php`. **A new file is inert until it's required from `functions.php`** — there is no directory-based auto-loading.

### Page templates, not routes

Frontend "pages" are `page-*.php` files using a `Template Name:` header comment. WordPress does **not** auto-map these to URLs — each one must be manually assigned to a real WP Page in wp-admin (Pages → Edit → Template dropdown) after the file is deployed. There is no rewrite-rule registration or programmatic page creation anywhere in this theme. When adding a new `page-*.php` file, always call out that a WP Page needs to be created/assigned — it's the easiest step to forget.

Navigation between these pages is entirely hardcoded `home_url('/slug/')` links (in toolbars, back-links, etc.) — there is no WP menu system in play.

### The `recipe` custom post type is external

The `recipe` post type (and its now-unused `recipe_category` taxonomy) is **registered somewhere outside this repository** — there is no `register_post_type()` call anywhere in this codebase. Treat its existence as a given. Recipe content lives in plain postmeta, not structured fields or ACF:
- `_recipe_ingredients` — HTML (`<ul><li>...</li></ul>`)
- `_recipe_method` — HTML (`<ol><li>...</li></ol>`)
- `_recipe_notes` — HTML (`<p>...</p>`)
- `_recipe_id` — a display ID like `R0001`, assigned lazily on first render (`page-recipe-manager.php`)

### Custom category system replaces WP taxonomies

Recipe categorization is **not** WordPress taxonomies — it was migrated off of them (`migrate-to-custom-categories.php`) into two custom tables: `{prefix}recipe_categories` (with a `category_type` column: `'food'` or `'author'`) and `{prefix}recipe_category_relationships`. All CRUD goes through `custom-category-functions.php` (`get_user_categories()`, `create_user_category()`, `set_recipe_categories()`, etc.) — never touch WP taxonomy functions for recipe categories.

### No schema migration framework

There is no `dbDelta()`, no activation hook, no tracked schema version anywhere. Custom tables are created by standalone CLI scripts that run raw `CREATE TABLE IF NOT EXISTS` once, by hand, on the live DB (see Commands above). If you add a new table, follow that same pattern — it's the only precedent that exists, not an oversight to fix.

### Two independent permission/sharing models — do not conflate them

- **Recipe collections** (`collection-permissions.php`): a "collection" is one author's set of recipes. Sharing is stored as **serialized arrays in usermeta**, keyed on the collection *owner's* user ID: `_collection_editors`, `_collection_viewers`, `_access_requests`. This works because a user owns exactly one collection.
- **Allergen profiles** (`allergen-permissions.php`): a user can own *multiple* profiles, so sharing uses a proper join table (`allergen_profile_shares`, keyed by `profile_id`) instead — reusing the usermeta-array pattern here would leak access to a user's other profiles when sharing just one. Only a profile's creator can ever edit it (`user_can_edit_profile()` is a plain ownership check with **no admin bypass** — the one deliberate place in this codebase where `manage_options` does not grant override access, because this is medical data). Every mutating function in `allergen-functions.php` re-checks ownership itself, not just the UI, since POST handlers are reachable independent of what buttons are rendered.
- **Allergen products** are fully global by design (any logged-in user can view/select any product from the Allergen Checker's picker, `get_all_products()`) — editing/deleting a product stays creator-only, but there is no sharing/copy step at all, unlike recipes or profiles.

### Forms and AJAX conventions

- Forms are **POST-to-self**, no `admin-post.php`, no REST API routes anywhere in this theme. Every mutating form uses `wp_nonce_field('some_action')` + `check_admin_referer('some_action')`, with server-side dispatch via `isset($_POST['action_name'])` checks or a single `bulk_action`/`action` field switched on.
- AJAX uses classic `wp_ajax_{action}` hooks against `admin-ajax.php`, nonces via `check_ajax_referer()`, and client-side `FormData` + `fetch()` (see `recipe-image-upload-handler.php` / `allergen-image-upload-handler.php` and their corresponding page templates' inline `<script>` blocks).

### AI/OCR integration

Photo-to-text extraction (recipes and, separately, allergen product labels) calls the Anthropic Claude API directly via `wp_remote_post()` — no SDK. The model name and API key are `wp-config.php` constants (`ANTHROPIC_MODEL`, `ANTHROPIC_API_KEY`), not present in this repo; every call site guards on `ANTHROPIC_API_KEY !== 'YOUR_KEY_GOES_HERE_WHEN_READY'` before calling out. `recipe-model-health-check.php` runs a weekly cron that pings the API and scrapes Anthropic's deprecation docs, emailing the admin if the configured model looks retired — this exists because of a prior incident where a hardcoded model string went stale. New OCR-style features should follow `allergen-image-upload-handler.php`'s pattern (new sibling extractor/parser functions) rather than parameterizing the existing recipe extractor, which is already tightly coupled to recipe-shaped output across three call sites.

### Auth is fully custom

Login/registration do not use `wp-login.php` (traffic to it is redirected to `/login/`). New users land in a "pending" state (`account_status` usermeta) requiring admin approval before they can log in. Non-administrators are blocked from `/wp-admin` entirely and redirected to `/recipe-manager/`, which is the effective home page/dashboard for every non-admin user.

### The Allergen Checker feature (most recently added, actively evolving)

A self-contained subsystem: `allergen-functions.php` (CRUD for allergen definitions/aliases/profiles/products), `allergen-permissions.php` (profile view/edit checks + active-profile persistence via `_active_allergen_profile_id` usermeta, revalidated on every read), `allergen-matching-engine.php` (the tiered Contains / May-contain / Not-detected report generator, plus a curated compound-ingredient dictionary for "cannot determine from this text alone" flags), `allergen-image-upload-handler.php` (product-label OCR), and three page templates (`page-allergen-profiles.php`, `page-allergen-products.php`, `page-allergen-checker.php`). Matching is deliberately simple and fully auditable — fixed phrase lists and word-boundary substring matching, no fuzzy heuristics or model calls — so that every flag traces back to a literal, human-checkable line of text. See `docs/allergen-checker-spec.md` before changing any matching/tiering logic; there are specific, deliberate trade-offs documented there (e.g. no negation/"-free" detection) that are not obvious from the code alone.
