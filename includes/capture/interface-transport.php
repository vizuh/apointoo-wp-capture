<?php
/**
 * Outbound transport contract.
 *
 * @package Apointoo\Capture
 */

namespace Apointoo\Capture\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forwards normalised leads to the Apointoo capture contract.
 *
 * Implementations run server-side only (Path B — they hold the tenant server
 * secret) and call the SDK's `/capture/track` + `/capture/identify`.
 */
interface Transport_Interface {

	/**
	 * Forward a captured lead (server secret path).
	 *
	 * @param Lead $lead The normalised lead.
	 * @return bool True when accepted for delivery.
	 */
	public function capture_lead( Lead $lead );

	/**
	 * Adapter name, for diagnostics/logs.
	 *
	 * @return string
	 */
	public function get_name();
}
