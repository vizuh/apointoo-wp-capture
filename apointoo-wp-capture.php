<?php
/**
 * Plugin Name:       Apointoo Capture
 * Plugin URI:        https://vizuh.com
 * Description:       Connects existing WordPress forms to the Apointoo capture contract. A capture/wiring adapter — NOT a form builder.
 * Version:           0.0.0-dev
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Vizuh OÜ
 * Author URI:        https://vizuh.com
 * License:           Proprietary
 * Text Domain:       apointoo-capture
 * Update URI:        false
 *
 * @package Apointoo\Capture
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'APOINTOO_CAPTURE_VERSION', '0.0.0-dev' );

/*
 * ───────────────────────────────────────────────────────────────────────────
 * STATUS: SKELETON ONLY — no functional code yet, by design.
 *
 * This plugin targets the Apointoo *public capture contract*
 * (POST /capture/track + /capture/identify), which DOES NOT EXIST YET.
 * It is downstream of two things that must land first:
 *
 *   1. The public capture contract  — vizuh/apointoo-sdk:
 *        _references/public-capture-contract-proposal.md
 *      backed by ADR-021 (capture API tokens):
 *        docs/decisions/adr-021-capture-api-tokens.md
 *   2. H3 Google Data Manager feedback (separate SDK PR, blocked on Google access)
 *
 * Build order + the hard "capture/wiring adapter, NOT a form builder" boundary:
 *   docs/PLAN.md
 *
 * Do NOT add form adapters, the /wp-json proxy, the JS tracker, or any capture
 * logic until the contract + ADR-021 land. Building against an unstable contract
 * is the rework risk this skeleton deliberately avoids.
 * ───────────────────────────────────────────────────────────────────────────
 */
