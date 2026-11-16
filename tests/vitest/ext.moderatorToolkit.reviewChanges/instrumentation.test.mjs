import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { instrumentCards, reset } from '/resources/ext.moderatorToolkit.reviewChanges/instrumentation.js';

let start;
let instrument;

function addCard( feedorigin ) {
	const card = document.createElement( 'div' );
	card.className = 'moderator-toolkit-review-changes__card';
	card.dataset.feedorigin = feedorigin;
	document.body.appendChild( card );
}

function settle() {
	return new Promise( ( resolve ) => {
		setTimeout( resolve );
	} );
}

beforeEach( () => {
	start = vi.fn();
	instrument = { send: vi.fn() };
	mw.testKitchen = { getInstrument: vi.fn( () => instrument ) };
	mw.loader.using = vi.fn( () => Promise.resolve( () => ( {
		ClickThroughRateInstrument: { start }
	} ) ) );
} );

afterEach( () => {
	reset();
	document.body.innerHTML = '';
	delete mw.testKitchen;
} );

test( 'tracks every card with the friendly name of its feed source', async () => {
	addCard( 'watchlist' );
	addCard( 'recentlyedited' );
	addCard( 'mostedited' );
	addCard( 'recentchanges' );

	instrumentCards();
	await settle();

	expect( mw.loader.using ).toHaveBeenCalledWith( 'ext.wikimediaEvents.testKitchen' );
	expect( mw.testKitchen.getInstrument ).toHaveBeenCalledWith( 'personal-dashboard-health-metrics' );
	expect( start.mock.calls.map( ( [ selector, name ] ) => [ selector, name ] ) ).toStrictEqual( [
		[ '.moderator-toolkit-review-changes__card:nth-of-type(1)', 'Personal Dashboard watched diff link' ],
		[ '.moderator-toolkit-review-changes__card:nth-of-type(2)', 'Personal Dashboard recently edited diff link' ],
		[ '.moderator-toolkit-review-changes__card:nth-of-type(3)', 'Personal Dashboard most edited diff link' ],
		[ '.moderator-toolkit-review-changes__card:nth-of-type(4)', 'Personal Dashboard diff link' ]
	] );
} );

test( 'gives a card from an unknown source the generic diff name', async () => {
	addCard( 'somethingnew' );

	instrumentCards();
	await settle();

	expect( start ).toHaveBeenCalledWith(
		'.moderator-toolkit-review-changes__card:nth-of-type(1)',
		'Personal Dashboard diff link',
		expect.anything()
	);
} );

test( 'loads TestKitchen one time for many cards', async () => {
	addCard( 'watchlist' );

	instrumentCards();
	instrumentCards();
	await settle();

	expect( mw.loader.using ).toHaveBeenCalledTimes( 1 );
	expect( mw.testKitchen.getInstrument ).toHaveBeenCalledTimes( 1 );
} );

test( 'does nothing without WikimediaEvents', async () => {
	mw.loader.using = vi.fn( () => Promise.reject( new Error( 'Unknown module' ) ) );
	addCard( 'watchlist' );

	instrumentCards();
	await settle();

	expect( start ).not.toHaveBeenCalled();
} );
