<?php
/**
 * Holds the state behind the stand-ins for the WordPress functions the admin classes call.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

final class AdminStub {

	public static int $user_id = 1;

	public static int $blog_id = 1;

	public static int $now = 0;

	/**
	 * User meta by user ID and key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $user_meta = array();

	/**
	 * Hooks added, as "type:name:callback method:priority".
	 *
	 * @var string[]
	 */
	public static array $hooks = array();

	/**
	 * Fields added to the REST API, by object type and field name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public static array $rest_fields = array();

	/**
	 * Scripts that were loaded, by handle.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public static array $scripts = array();

	/**
	 * Whether the current user may edit posts.
	 */
	public static bool $can_edit = true;

	/**
	 * The only nonce that wp_verify_nonce accepts.
	 */
	public static string $valid_nonce = 'good-nonce';

	/**
	 * Resets the state before a test.
	 */
	public static function reset(): void {
		self::$user_id     = 1;
		self::$blog_id     = 1;
		self::$now         = 0;
		self::$user_meta   = array();
		self::$hooks       = array();
		self::$rest_fields = array();
		self::$scripts     = array();
		self::$can_edit    = true;
		self::$valid_nonce = 'good-nonce';
	}
}
