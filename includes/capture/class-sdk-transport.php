<?php
/**
 * Apointoo SDK transport (STUB).
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stub transport.
 *
 * The real implementation POSTs to the SDK's `/capture/track` + `/capture/identify`
 * with the tenant server secret. That wire shape is fixed only once the public
 * capture contract lands, so this is a no-op placeholder.
 *
 * @see https://github.com/vizuh/apointoo-sdk — _references/public-capture-contract-proposal.md
 * @see https://github.com/vizuh/apointoo-sdk/issues/116 (contract)
 * @todo Implement once the contract + ADR-021 auth land. Must gate on consent
 *       ({@see Consent::marketing_allowed()}) before forwarding ad identifiers.
 */
class SDK_Transport implements Transport_Interface {

	/**
	 * {@inheritDoc}
	 *
	 * @param Lead $lead The normalised lead.
	 * @return bool Always false — not yet wired.
	 */
	public function capture_lead( Lead $lead ) {
		/**
		 * Fires when a lead would be forwarded. Useful for local observation while
		 * the transport is stubbed.
		 *
		 * @param Lead $lead The normalised lead.
		 */
		do_action( 'apointoo_capture_lead_pending', $lead );

		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_name() {
		return 'apointoo-sdk-stub';
	}
}
