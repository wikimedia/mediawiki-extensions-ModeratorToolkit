import path from 'node:path';
import { defineConfig } from 'vitest/config';
import mediawiki from 'vitest-plugin-mediawiki';

export default defineConfig( {
	plugins: [ mediawiki() ],
	test: {
		server: {
			deps: {
				inline: [ 'personal-dashboard' ]
			}
		}
	},
	resolve: {
		alias: {
			'mediawiki.DateFormatter': '/tests/vitest/mocks/mediawiki.DateFormatter.mjs',
			'ext.personalDashboard.common': '/tests/vitest/mocks/ext.personalDashboard.common.mjs',
			'/resources/ext.personalDashboard.common': path.resolve( 'node_modules/personal-dashboard/resources/ext.personalDashboard.common' )
		}
	}
} );
