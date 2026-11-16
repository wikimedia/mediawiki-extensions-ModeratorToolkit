const { withExperimentTagging } = require( 'ext.personalDashboard.common' );

const CARD_SELECTOR = '.moderator-toolkit-review-changes__card';

// One friendly name per feed source, so clicks are attributed to the
// source the item actually came from. Anything unrecognised falls back
// to the generic diff name rather than being lumped in with a specific
// source and skewing that source's click-through rate.
const FRIENDLY_NAME_DIFF = 'Personal Dashboard diff link';
const FRIENDLY_NAMES_BY_ORIGIN = {
	recentchanges: FRIENDLY_NAME_DIFF,
	watchlist: 'Personal Dashboard watched diff link',
	recentlyedited: 'Personal Dashboard recently edited diff link',
	mostedited: 'Personal Dashboard most edited diff link'
};

/** @type {Promise|null} */
let loading = null;

/*
 * ClickThroughRateInstrument comes from WikimediaEvents, which pulls in the
 * TestKitchen extension's own ext.testKitchen for mw.testKitchen. Both are
 * optional dependencies, so we load them lazily, and only one time.
 *
 * The events go to the same instrument as PersonalDashboard's pageview, so the
 * data stays in one place.
 *
 * @return {Promise}
 */
function loadInstrument() {
	if ( !loading ) {
		loading = mw.loader.using( 'ext.wikimediaEvents.testKitchen' ).then( ( require ) => {
			const { ClickThroughRateInstrument } = require( 'ext.wikimediaEvents.testKitchen' );
			const instrument = withExperimentTagging( mw.testKitchen.getInstrument(
				'personal-dashboard-health-metrics'
			) );
			return { ClickThroughRateInstrument, instrument };
		} );
	}
	return loading;
}

/**
 * Starts click-through tracking on every card on the page. A card that is
 * already tracked keeps its state, so each card can call this when it mounts.
 */
function instrumentCards() {
	loadInstrument().then( ( { ClickThroughRateInstrument, instrument } ) => {
		const links = document.querySelectorAll( CARD_SELECTOR );
		for ( const [ i, link ] of links.entries() ) {
			const origin = link.dataset.feedorigin;
			const friendlyName = FRIENDLY_NAMES_BY_ORIGIN[ origin ] || FRIENDLY_NAME_DIFF;
			ClickThroughRateInstrument.start(
				CARD_SELECTOR + ':nth-of-type(' + ( i + 1 ) + ')',
				friendlyName,
				instrument
			);
		}
	// A rejection handler rather than a trailing .catch(), so we swallow only a
	// failed load (expected without WikimediaEvents), not the instrumentation above.
	}, () => {} );
}

/** Forgets the loaded instrument. For tests only. */
function reset() {
	loading = null;
}

module.exports = { instrumentCards, reset };
