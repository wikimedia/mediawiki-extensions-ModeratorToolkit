import { test, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import App from '/resources/ext.moderatorToolkit.impact/App.vue';

test( 'mount component', () => {
	mw.config.set( {
		wgModeratorToolkitImpactThanksCount: 1234,
		wgModeratorToolkitImpactReviewCount: 4321
	} );

	const wrapper = mount( App );
	expect( wrapper.element ).toMatchSnapshot();
} );

test( 'counters fallback to 0 if no config values', () => {
	mw.config.delete(
		'wgModeratorToolkitImpactThanksCount',
		'wgModeratorToolkitImpactReviewCount'
	);

	const wrapper = mount( App );
	expect( wrapper.element ).toMatchSnapshot();
} );
