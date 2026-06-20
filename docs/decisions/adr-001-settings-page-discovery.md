# ADR-001 — Settings page first-run discovery + SMTP integration

**Status:** accepted  
**Date:** 2026-06-20  
**Deciders:** Hugo Carvalho

## Context

First real deployment (feathershouses.com) revealed two problems: (1) after activation
the plugin shows nothing — the settings page at Settings → Apointoo Capture exists in
code but nothing points there; (2) the site's WP Mail SMTP was broken (Gmail OAuth
`invalid_grant`). Both can be solved by the plugin instead of requiring a second plugin.

## Decision

Add an activation redirect to the settings page (standard WP pattern). Extend the
settings page with a Brevo SMTP section that wires into `phpmailer_init`, replacing WP
Mail SMTP on client sites where the Gmail OAuth path breaks.

## Consequences

- **Gain:** one plugin handles attribution capture + SMTP on client WordPress sites; WP
  Mail SMTP can be deactivated.
- **Lose:** the plugin now touches `phpmailer_init` — a hook with broad blast radius;
  must guard on empty credentials so it's a no-op when unconfigured.
- **Watch:** Brevo password stored in `wp_options` with the same write-only UI pattern
  as the server secret (never echoed back). Not encrypted at rest — same risk posture as
  WP Mail SMTP.

## Alternatives considered

- **Keep WP Mail SMTP, just re-auth Gmail** — rejected; Gmail OAuth expires on every
  password change; Brevo is stateless credential, more reliable for client handoffs.
- **Top-level admin menu** — rejected; plugin doesn't own the site's navigation and
  Settings sub-page is the WP convention for credential-only plugins.
- **Do nothing** — rejected; owners won't find the settings page and the plugin appears
  broken.

## Links

- Observed on: feathershouses.com (2026-06-20)
- Related: PLAN.md §3a (settings page scope)
