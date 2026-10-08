<template>
	<module-panel :title>
		<p>{{ body }}</p>
	</module-panel>
</template>

<script>
const { defineComponent } = require( 'vue' );
const { ModulePanel } = require( 'ext.personalDashboard.common' );

module.exports = defineComponent( {
	components: { ModulePanel },
	setup() {
		return {
			// The menu item names the panel it opens, so both read one message.
			title: mw.msg( 'personal-dashboard-review-changes-menu-about' ),
			// The module's description. The card itself has none.
			body: mw.msg( 'personal-dashboard-risky-article-edits-subheader-info' )
		};
	}
} );
</script>
