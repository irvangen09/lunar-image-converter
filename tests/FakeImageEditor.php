<?php
/**
 * Stands in for the WordPress image editor, with the quality behavior that matters here.
 *
 * @package LunarImageConverter
 */

namespace LunarImageConverter\Tests;

final class FakeImageEditor {

	/**
	 * Default quality WordPress uses for WebP.
	 */
	private const DEFAULT_QUALITY = 86;

	/**
	 * Quality the editor holds now.
	 */
	public int $quality = self::DEFAULT_QUALITY;

	/**
	 * Quality that was in use when the file was written.
	 */
	public ?int $written_with = null;

	/**
	 * Whether saving throws.
	 */
	public bool $throws = false;

	/**
	 * Like WordPress: without a value the quality comes from the filter, starting at the default of the format.
	 *
	 * @param int|null $quality Quality or null.
	 */
	public function set_quality( ?int $quality = null ): bool {
		$this->quality = null === $quality
			? (int) WpStub::apply_filters( 'wp_editor_set_quality', self::DEFAULT_QUALITY )
			: $quality;

		return true;
	}

	/**
	 * Like WordPress: saving in another format than the one that was read sets the quality again without a value.
	 *
	 * @param string $destination Path to write.
	 * @param string $mime        MIME type to write.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When asked to fail.
	 */
	public function save( string $destination, string $mime ): array {
		if ( $this->throws ) {
			throw new \RuntimeException( 'The encoder crashed.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double; nothing is printed.
		}

		$this->set_quality();
		$this->written_with = $this->quality;

		file_put_contents( $destination, 'data' );

		return array(
			'path'      => $destination,
			'mime-type' => $mime,
		);
	}
}
