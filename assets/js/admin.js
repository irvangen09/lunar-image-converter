( function () {
	const number = document.getElementById( 'lic-quality' );
	const range = document.getElementById( 'lic-quality-range' );

	if ( ! number || ! range ) {
		return;
	}

	// The slider is only a pointer shortcut; the number field stays the accessible control.
	range.hidden = false;
	range.value = number.value;

	range.addEventListener( 'input', function () {
		number.value = range.value;
	} );

	number.addEventListener( 'input', function () {
		range.value = number.value;
	} );
} )();
