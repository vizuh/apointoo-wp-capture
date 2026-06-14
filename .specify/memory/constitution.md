# apointoo-wp-capture Constitution

## Core Principles

### I. Capture-and-Wire Only (NON-NEGOTIABLE)
This plugin is a capture + wiring adapter, NOT a form product. It adapts to forms that already exist on the site. It never creates, renders, styles, validates, or manages a form, a booking, or any submission UI. Permanent non-goals: no form builder, no booking UI, no visitor-facing shortcodes/blocks/widgets, no CRM, no consent banner, no business logic on the lead. If a proposed feature would make the plugin usable without an existing form, it is out of scope.

### II. PII Stays on Site
Raw PII (email, phone) never leaves the WordPress server unprocessed. The PII hasher (SHA-256, normalized in PHP) must run before any data is forwarded to the SDK. No adapter may forward raw name/email/phone to an external endpoint.

### III. Consent Gate is Load-Bearing
The plugin reads the site's existing CMP (Consent Mode v2 via WP Consent API / Complianz / Cookiebot / CookieYes). Marketing-consent flag must be checked before forwarding ad-click identifiers (GCLID, fbclid) to the SDK. Stripping identifiers without consent is non-negotiable — it's the compliance boundary.

### IV. GPL-Compatible Only
The plugin license is GPLv2 or later — a wp.org distribution requirement. Every dependency must be GPL-compatible. No MIT-incompatible or proprietary transitive dependencies in the plugin's distributed code. If in doubt, consult LICENSE before adding a composer dep.

### V. WordPress Coding Standards
All PHP is PHPCS/WPCS-clean. PHP 8.0+ compatible. Hooks are named under the `apointoo_capture_` prefix namespace. No global function pollution. No direct DB queries outside `wpdb` unless absolutely necessary.

### VI. SDK Contract Dependency
The plugin targets the Apointoo public capture contract (`apointoo-sdk: POST /capture/track` + `/capture/identify`). Do not build transport or visitor proxy logic ahead of the SDK contract landing — stubs are correct until ADR-021 is ratified. Build order: SDK ledger PR → public capture contract → this plugin M0→M3.

## Architecture Constraints

- Form adapters implement the `Form_Adapter` interface (server-side submit hook, emit neutral `lead_captured`).
- Visitor tracking: same-domain `/wp-json` proxy injects tenant secret server-side — the JS tracker never holds the secret.
- Attribution cookie is **server-set** (survives Safari ITP). Do not move this to client-only `document.cookie`.
- Multi-form adapter stubs (WPForms, Gravity, Elementor, Fluent) remain stubs until M3 milestone.
- Distribution: free plugin, paid service (SDK + dashboard). The plugin is the funnel into the Apointoo service.

## Development Workflow

1. `composer install` — PHP deps.
2. `./vendor/bin/phpcs` — WPCS compliance gate before any PR.
3. `./vendor/bin/phpunit` — unit tests.
4. No minified JS shipped without a corresponding source map.
5. Version bump → update plugin header + readme.txt → ZIP for distribution.

## Governance

Principle I (capture-and-wire only) and Principle II (PII stays on site) are hard stops. Any spec that would cross these boundaries requires a formal amendment with Hugo approval and a GDPR/wp.org compliance check. All spec work must align with the SDK contract timeline — do not spec features that depend on an unratified SDK API.

**Version**: 1.0.0 | **Ratified**: 2026-06-14 | **Last Amended**: 2026-06-14
