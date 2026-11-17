( function () {
	if (
		! window.wp ||
		! wp.Uploader ||
		! wp.Uploader.queue ||
		! wp.Uploader.errors
	) {
		return;
	}

	// The upload status of the media library and of the media window lists these like the other
	// messages about an upload, with a dismiss button, and WordPress reads them out to screen readers.
	wp.Uploader.queue.on( 'change:uploading', function ( attachment ) {
		const warning = attachment.get( 'lunarImageConverter' );

		if ( attachment.get( 'uploading' ) || ! warning || ! warning.message ) {
			return;
		}

		wp.Uploader.errors.unshift( {
			message: warning.message,
			data: {},
			file: { name: attachment.get( 'filename' ) || '' },
		} );
	} );
} )();
