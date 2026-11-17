( function () {
	if ( ! window.wp || ! wp.apiFetch || ! wp.data ) {
		return;
	}

	// An upload from the block editor is a POST to the media route; its answer says if the image was converted.
	wp.apiFetch.use( function ( options, next ) {
		const request = next( options );

		if (
			'POST' !== options.method ||
			! /^\/wp\/v2\/media(\?|$)/.test( options.path || '' )
		) {
			return request;
		}

		return request.then( function ( response ) {
			const warning = response && response.lunar_image_converter;

			if ( warning && warning.text ) {
				wp.data
					.dispatch( 'core/notices' )
					.createWarningNotice( warning.text, {
						id: 'lunar-image-converter-' + response.id,
						isDismissible: true,
					} );
			}

			return response;
		} );
	} );
} )();
