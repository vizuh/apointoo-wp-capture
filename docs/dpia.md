# DPIA — Apointoo Capture (WordPress plugin + capture contract)

**Controller:** the **tenant** (the agency's client whose site runs the plugin) is controller of their leads'
data. **Vizuh OÜ** is a **processor** (operates the SDK/ledger on the tenant's behalf). **Google (Ads / Data
Manager)** is an onward processor / independent controller for the conversion data it receives.
**Processor(s):** Vizuh OÜ (SDK + ledger), MongoDB Atlas (ledger store), Google Ads/Data Manager (offline
conversions), the tenant's own CMP (consent), hosting (Vercel/edge — region TBD).
**DPO / privacy lead:** Hugo (Vizuh). The tenant's own DPO if they have one.
**Date:** 2026-05-30
**Status:** draft (pre-implementation — the system does not exist yet; this is a design DPIA)

> **Scope note.** This DPIA covers the capture *system* end-to-end: the WordPress plugin (collection) + the
> SDK public capture contract (processing/storage) + the H3 Google feedback path (onward transfer). It carries
> security-review findings **I3** (erasure) and **I5** (hash reversibility) as the dominant data-protection
> risks. C1/C2 (key-class boundary) were resolved in the design and are recorded in `capture-security-review.md`.

## 1. Screening — does this need a full DPIA?
| Criterion | Applies? | Notes |
|---|---|---|
| Sensitive / special-category data | **Y** | First niches include health providers (dental, clinics, psychologists). A lead contacting such a provider is **health data by inference** (Art 9) even though the plugin only stores contact identifiers. |
| Large-scale processing | **Y** | Designed to run across many agency-client sites simultaneously. |
| Systematic monitoring / tracking | **Y** | First-party attribution tracking of visitor journeys across pages/sessions. |
| New technology (AI, biometrics) | **Y** | Behavioural attribution + `browserIds` (fbp/fbc/ttp/ga_client_id) are fingerprinting-adjacent; feeds Google automated bidding. |
| Children or vulnerable subjects | N | Not targeted by default; flag per-tenant if a client's audience includes them. |
| Automated decisions / profiling | Partial | The plugin makes no decision about the individual; **Google's Smart Bidding** does downstream, fed by this data. |
| Combining datasets | **Y** | Identity resolution links `visitor → subject` across sessions and combines form data with ad-click identifiers. |

**Verdict: 5 × Y → full DPIA required.** (Do not downgrade to `privacy.md`.)

## 2. Processing description
- **What data:**
  - Pseudonymous ids: `vis_`/`ses_` (first-party cookie / minted).
  - Attribution: UTM params, ad click ids (`gclid`/`gbraid`/`wbraid`/`fbclid`/`msclkid`), `browserIds`
    (`fbp`/`fbc`/`ttp`/`ga_client_id`), referrer, landing page, first/last touch.
  - Consent state: Consent Mode v2 signals (`ad_storage`/`ad_user_data`/`ad_personalization`/`analytics_storage`) + provenance.
  - Identity (via `identify`, secret path only): `email_hash`, `phone_hash` (SHA-256 of normalized value),
    `external_id`. **Raw email/phone exist only transiently in PHP for hashing, then discarded (B5).**
  - Form non-PII fields (after PII scrub — see R3).
- **Whose:** website visitors and leads of the tenant's site.
- **Source:** collected directly (the visitor's browser + their form submission).
- **Purpose:** marketing attribution + **offline conversion measurement** (telling Google Ads which ad clicks
  produced real leads/sales, so spend is measured and optimised).
- **Volume / frequency:** leads/pageviews per site per day, multiplied across tenant sites.
- **Retention (B4 classes):** A = 25mo (conversions, identity edges, consent snapshots); B = 13mo (pageviews,
  form steps, sessions, attribution touches); C = 30–90d (rejected/invalid/junk); D = indefinite while tenant
  active (small projections). **Open: 25mo for reversible phone hashes is long — see R5/I5.**

## 3. Lawful basis (Article 6)
- **Consent** for the ad/marketing processing (forwarding ad identifiers + PII to Google) — enforced by the
  Consent Mode v2 gate (E1): ad ids stripped unless `ad_storage` granted; PII linkage + feedback only on
  `ad_user_data` granted.
- **Legitimate interest** is the candidate basis for first-party, non-ad analytics (pageview/form telemetry) —
  **LIA required** (TODO — verify with Hugo / the tenant; for EU the safer default is consent for all of it).
- **Article 9 (special category):** where the tenant is a health provider, the contact-with-provider inference
  needs **explicit consent** as the Art 9 condition. TODO — per-tenant vertical flag + heightened consent.

## 4. Risks to data subjects
(Harm to the **individual**, not the org. Forged-conversion / ad-spend risk is a *security* concern, in
`capture-security-review.md` C2 — excluded here.)

| # | Risk to individual | Likelihood (1-5) | Severity (1-5) | Score | Ref |
|---|---|---|---|---|---|
| R1 | Tenant WP site compromised → server secret in `wp_options` read → attacker pulls the tenant's hashed identifiers | 3 | 4 | 12 | I4 |
| R2 | Ad identifiers forwarded to Google without genuine consent (forged/misconfigured consent block) → unlawful tracking of the individual | 2 | 4 | 8 | I1 |
| R3 | Raw PII lands in free-form `properties`/`traits` (a CF7 message field) → stored **unhashed** in the append-only ledger | 3 | 4 | 12 | I2 |
| R4 | Individual requests erasure but append-only ledger + tombstone-only design can't actually delete their hashes for up to 25mo | 4 | 3 | 12 | I3 |
| R5 | `phone_hash` (unsalted SHA-256, required for Google matching) is brute-forceable → individual re-identified from the ledger | 3 | 3 | 9 | I5 |
| R6 | Health-context lead treated as ordinary data → special-category processed without an Art 9 basis | 3 | 4 | 12 | §3 |

## 5. Mitigations
| Risk | Control (technical / organisational / contractual) | Owner | Residual |
|---|---|---|---|
| R1 | Per-tenant **revocable** secret (blast radius = one tenant); one-click rotation; anomaly detection on `lastUsedAt` + volume baselines; reconsider IP-pinning the secret class; at-rest encryption (obfuscation, documented as such) | Hugo | Medium |
| R2 | Consent **determined server-side** from the tenant CMP (not client-asserted); conversion-eligible events secret-only (C2); ad-id stripping when consent denied (E1 gate 1); `feedback_eligible` only on `ad_user_data` granted (E1 gate 3); consent snapshot stored as immutable provenance | Hugo | Low–Med |
| R3 | **TODO (I2 — not yet built):** server-side PII denylist/pattern scrub over all `properties`/`traits` before ledger append; explicit code-enforced allow-list for `traits` | Hugo | **High until built** |
| R4 | **TODO (I3 — design not finalised):** real erasure path via **crypto-shredding** (per-subject key destroyed → hashes unrecoverable) or genuine delete/anonymise of a subject's PII events; keep audit skeleton if needed | Hugo | **High until defined** |
| R5 | Prefer computing match hashes **transiently in the feedback job** rather than long-term class-A storage; if persisted, shorten the hash-field retention + tighten access; document as governed accepted risk (cannot salt — breaks Google matching) | Hugo | Medium |
| R6 | **TODO:** per-tenant special-category vertical flag → require explicit Art 9 consent + heightened handling/retention; contractual DPA with the health-provider tenant | Hugo | Medium |

## 6. Residual risk assessment
- **Highest residual (pre-implementation): R3 and R4 — both HIGH** until built/defined.
- **Gate:** R3 (PII scrub) and R4 (erasure) MUST be resolved **before any non-sandbox (real) PII flows**
  through the system. Sandbox-only data (no real subjects) is acceptable for M0/M1 dogfooding. In practice:
  rework the design (build I2 + I3) rather than consult the supervisory authority.

## 7. Data subject rights
- **Access (SAR):** query the ledger by `subject_key` / identifier hash → return the subject's events. *TODO — define the read path + who runs it (agency_admin vs Vizuh).*
- **Rectification:** limited (data is hashed/pseudonymous); handled via re-`identify`. *TODO.*
- **Erasure:** **blocked today** — append-only ledger + tombstone only flips `feedback_eligible`. Needs R4 (crypto-shred). **This is the dominant compliance gap.**
- **Portability:** export the subject's events as JSON. *TODO.*
- **Objection / consent withdrawal:** `consent_revoked` event flips `feedback_eligible` off + signals H3 to retract the Google conversion (RETRACTION, 55-day window) → **must also trigger erasure (R4)**, not just suppression.

## 8. International transfers
- **Data leaves EU/EEA? Y** — hashed identifiers + conversion data go to **Google (US)**.
- **Safeguards:** Google is **EU-US Data Privacy Framework**-certified + SCCs (verify current status). The
  ledger store (**MongoDB Atlas**) MUST be pinned to an **EU region**; hosting/edge region TBD — keep EU.
- **Processors with region:** MongoDB Atlas (set EU) · Google Ads/Data Manager (US, DPF) · hosting (TBD — EU).

## 9. Sign-off
- Reviewed by: **TODO — Hugo (Vizuh)**
- Accepted residual risk: **No (R3/R4 HIGH)** — not acceptable for real PII until I2 + I3 are built.
- Next review: **before the first non-sandbox PII flow**, and on any material design change.

---
**LGPD note (BR tenants).** For Brazilian clients (e.g. health-plan brokers like acheseuplano), LGPD mirrors
this structure — consent, purpose limitation, subject rights — under **ANPD**. The Art 9 health-inference and
erasure obligations apply equivalently; flag BR-specific consent wording with the tenant.
