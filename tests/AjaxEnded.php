<?php
/**
 * Ends a stand-in AJAX request.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

/**
 * Ends a stand-in AJAX request, the way WordPress ends one with `wp_die()`.
 */
final class AjaxEnded extends \RuntimeException {

	public bool $success;

	/**
	 * @var mixed
	 */
	public $payload;

	public int $status;

	/**
	 * @param bool  $success Whether the answer was a success.
	 * @param mixed $payload Data sent to the browser.
	 * @param int   $status  HTTP status.
	 */
	public function __construct( bool $success, $payload, int $status ) {
		parent::__construct( $success ? 'success' : 'error' );

		$this->success = $success;
		$this->payload = $payload;
		$this->status  = $status;
	}
}
