<?php
/**
 * Stands in for the WordPress functions the content classes call.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Content;

use LunarImageConverter\Tests\AdminStub;

/**
 * @param int $id Post ID.
 */
function clean_post_cache( int $id ): void {
	AdminStub::$cleaned_posts[] = $id;
}
