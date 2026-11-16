export { default as FeedCard } from '/resources/ext.personalDashboard.common/components/FeedCard.vue';
export { default as FeedPanel } from '/resources/ext.personalDashboard.common/components/FeedPanel.vue';
export { fetchFeed } from '/resources/ext.personalDashboard.common/fetchFeed.js';
export { FULL_LIMIT } from '/resources/ext.personalDashboard.common/constants.js';
export { default as ModuleHeaderMenu } from '/resources/ext.personalDashboard.common/components/ModuleHeaderMenu.vue';
export { default as ModulePanel } from '/resources/ext.personalDashboard.common/components/ModulePanel.vue';
export { default as MultiStepDialog } from '/resources/ext.personalDashboard.common/components/MultiStepDialog.vue';
export { useFeedState } from '/resources/ext.personalDashboard.common/composables/useFeedState.js';
export { useViewport } from '/resources/ext.personalDashboard.common/composables/useViewport.js';
import * as utils from '/resources/ext.personalDashboard.common/utils.js';

export { utils };

// PersonalDashboard tests the tagging itself. The CI copy of PersonalDashboard
// can be older than this repository, so the mock does not import it.
export const withExperimentTagging = ( instrument ) => instrument;
