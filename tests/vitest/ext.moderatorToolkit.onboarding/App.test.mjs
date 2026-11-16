import { test, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import App from '/resources/ext.moderatorToolkit.onboarding/App.vue';

test( 'mount component', () => {
	mw.Api.mock( ( params, options ) => {
		if ( options.type === 'POST' &&
			params.action === 'options' &&
			( params.optionname === 'personaldashboard-visited' ||
			params.optionname === 'personaldashboard-eligible' ) &&
			params.optionvalue === '1' ) {
			return {};
		}
	} );

	const wrapper = mount( App, {
		global: {
			mocks: {
				$route: {
					path: '/'
				}
			}
		}
	} );

	expect( wrapper.element ).toMatchSnapshot();
} );

test( 'dialog is suppressed on deep-link route', () => {
	mw.Api.mock( ( params, options ) => {
		if ( options.type === 'POST' &&
			params.action === 'options' &&
			( params.optionname === 'personaldashboard-visited' ||
				params.optionname === 'personaldashboard-eligible' ) &&
			params.optionvalue === '1' ) {
			return {};
		}
	} );

	const wrapper = mount( App, {
		global: {
			mocks: {
				$route: {
					path: '/ext.moderatorToolkit.impact'
				}
			}
		}
	} );

	expect( wrapper.find( '.moderator-toolkit-onboarding' ).exists() ).toBe( false );
} );
