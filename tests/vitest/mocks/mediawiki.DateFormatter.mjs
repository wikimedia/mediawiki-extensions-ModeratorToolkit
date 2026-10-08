export function formatRelativeTimeOrDate( date ) {
	// Making "today's" date fixed because otherwise we will have flaky tests
	const dateNow = new Date( 2026, 1, 1, 1, 0 );
	// from mediawiki/extensions/MobileFrontend/src/mobile.startup/time.js
	const seconds = ( date.getTime() - dateNow ) / 1000;
	const units = [ 'seconds', 'minutes', 'hours', 'days', 'months', 'years' ];
	const limits = [ 1, 60, 3600, 86400, 2592000, 31536000 ];
	let i = 0;
	while ( i < limits.length && Math.abs( seconds ) > limits[ i + 1 ] ) {
		++i;
	}
	const value = Math.round( seconds / limits[ i ] );
	// eslint-disable-next-line compat/compat
	return new Intl.RelativeTimeFormat( 'en' ).format( value, units[ i ] );
}
