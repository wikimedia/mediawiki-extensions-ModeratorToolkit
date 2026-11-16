<template>
	<feed-card
		class="moderator-toolkit-review-changes__card"
		:url="diffUrl"
		open-in-new-tab
		mark-visited
		overlay-link
		:aria-label="diffLinkAriaLabel"
		:data-feedorigin="feedorigin"
	>
		<template #header>
			<span
				v-if="isNarrow"
				class="moderator-toolkit-review-changes__card__title"
			>
				{{ title }}
			</span>

			<a
				v-else
				:href="pageUrl"
				target="_blank"
				class="moderator-toolkit-review-changes__card__title"
			>
				{{ title }}
			</a>

			<span
				v-if="description"
				class="moderator-toolkit-review-changes__card__description"
			>
				{{ description }}
			</span>
		</template>

		<template #meta>
			<span class="moderator-toolkit-review-changes__card__user">
				<span class="moderator-toolkit-review-changes__card__icon">
					<cdx-icon
						v-if="isNarrow || !showUserInfoCard"
						:icon="userIcon"
						size="small"
					></cdx-icon>

					<user-info-button v-else :username="user"></user-info-button>
				</span>

				<span
					v-if="isNarrow"
					class="moderator-toolkit-review-changes__card__username"
				>
					{{ user }}
				</span>

				<a
					v-else
					:href="userUrl"
					target="_blank"
					class="moderator-toolkit-review-changes__card__username"
				>
					{{ user }}
				</a>
			</span>

			<span class="moderator-toolkit-review-changes__card__meta">
				<span>
					&middot;
				</span>

				<span class="moderator-toolkit-review-changes__card__timestamp">
					{{ timestampFormatted }}
				</span>
			</span>
		</template>

		<template #description>
			<span v-if="editSummary" class="moderator-toolkit-review-changes__card__edit-summary">
				{{ editSummary }}
			</span>
			<span v-else class="moderator-toolkit-review-changes__card__edit-summary--missing">
				{{ noEditSummaryText }}
			</span>
		</template>

		<template v-if="flags.length" #supporting-text>
			<span class="moderator-toolkit-review-changes__card__flags">
				<cdx-info-chip
					v-for="flag in flags"
					:key="flag.key"
					:icon="flag.icon"
					:status="flag.status"
				>
					{{ flag.label }}
				</cdx-info-chip>
			</span>
		</template>
	</feed-card>
</template>

<script>
const { defineComponent, defineAsyncComponent } = require( 'vue' );
const { CdxIcon, CdxInfoChip } = require( '../codex.js' );
const { FeedCard, utils } = require( 'ext.personalDashboard.common' );
const { formatTimestamp, stripMarkup } = utils;
const { instrumentCards } = require( '../instrumentation.js' );
const { cdxIconNotice, cdxIconUserAvatar, cdxIconUserTemporary } = require( '../icons.json' );
const MAJOR_CHANGE_DELTA = 1000;
// Start of the origin parameter that the diff link carries. The diff page reads
// the parameter for the diff-health-metrics instrument, to record that the
// reader came from the dashboard and from which feed (T421397). See
// ext.wikimediaEvents.diff, which keeps a known set of values and drops the rest.
const ORIGIN_PREFIX = 'personaldashboard-';

module.exports = defineComponent( {
	name: 'ListCard',
	components: {
		CdxIcon,
		CdxInfoChip,
		FeedCard,
		UserInfoButton: defineAsyncComponent( {
			loader: () => new Promise( ( resolve ) => {
				mw.loader.using( 'ext.checkUser.userInfoCard', ( require ) => {
					resolve( require( 'ext.checkUser.userInfoCard' ).UserCardButton );
				} );
			} ),
			onError() {}
		} )
	},
	// The feed item carries fields no card declares. Without this they fall
	// through onto the rendered element as attributes, and every field the
	// endpoint gains would need stripping at the call site.
	inheritAttrs: false,
	props: {
		title: { type: String, required: true },
		// eslint-disable-next-line camelcase, vue/prop-name-casing
		old_revid: { type: Number, default: null },
		revid: { type: Number, required: true },
		user: { type: String, required: true },
		parsedcomment: { type: String, required: true },
		timestamp: { type: String, default: '' },
		newlen: { type: Number, required: true },
		oldlen: { type: Number, required: true },
		description: { type: String, default: '' },
		oresscores: { type: Object, default: () => ( {} ) },
		feedorigin: { type: String, required: true },
		isNarrow: { type: Boolean, default: false }
	},
	setup() {
		return {
			showUserInfoCard: mw.user.options.get( 'checkuser-userinfocard-enable' ),
			noEditSummaryText: mw.msg( 'moderator-toolkit-review-changes-list-card-no-comment-message' )
		};
	},
	computed: {
		diffUrl() {
			const params = { diff: this.revid };
			// The endpoint declares it nullable.
			if ( this.old_revid !== null ) {
				params.oldid = this.old_revid;
			}
			params.origin = ORIGIN_PREFIX + this.feedorigin;
			return new mw.Title( this.title ).getUrl( params );
		},
		diffLinkAriaLabel() {
			return mw.msg( 'moderator-toolkit-review-changes-list-card-aria-label', this.title );
		},
		pageUrl() {
			return new mw.Title( this.title ).getUrl();
		},
		userUrl() {
			return new mw.Title( this.user, mw.config.get( 'wgNamespaceIds' ).user ).getUrl();
		},
		editSummary() {
			return stripMarkup( this.parsedcomment );
		},
		timestampFormatted() {
			return formatTimestamp( this.timestamp );
		},
		userIcon() {
			return mw.util.isTemporaryUser( this.user ) ?
				cdxIconUserTemporary :
				cdxIconUserAvatar;
		},
		isMajorChange() {
			return Math.abs( this.newlen - this.oldlen ) > MAJOR_CHANGE_DELTA;
		},
		// The wiki's own threshold, resolved server-side. Absent where the wiki
		// configured none, and then the card makes no check.
		isHighRevertRisk() {
			const threshold = mw.config.get( 'wgModeratorToolkitReviewChangesHighRevertRiskThreshold' );
			if ( !threshold ) {
				return false;
			}

			const score = ( this.oresscores[ threshold.model ] || {} )[ threshold.class ];

			return typeof score === 'number' && score >= threshold.min;
		},
		flags() {
			const flags = [];

			if ( this.isHighRevertRisk ) {
				flags.push( {
					key: 'high-revert-risk',
					status: 'warning',
					// Codex supplies the icon for every status but notice.
					icon: null,
					label: mw.msg( 'moderator-toolkit-review-changes-high-revert-risk-label' )
				} );
			}

			if ( this.isMajorChange ) {
				flags.push( {
					key: 'major-change',
					status: 'notice',
					icon: cdxIconNotice,
					label: mw.msg( 'moderator-toolkit-review-changes-major-changes-label' )
				} );
			}

			return flags;
		}
	},
	mounted() {
		instrumentCards();
	}
} );
</script>

<style lang="less">
@import 'mediawiki.skin.variables.less';
@import 'mediawiki.mixins';

// FeedCard.vue and feed.less have the card chrome. This file styles only what is
// specific to a Review Changes item.
.moderator-toolkit-review-changes__card {
	// The edit details use the page title color, not the Codex gray.
	.cdx-card__text__description {
		&,
		.cdx-icon {
			color: inherit;
		}
	}

	&__username {
		font-weight: @font-weight-bold;
	}

	&__username,
	&__timestamp {
		overflow: hidden;
		text-overflow: @text-overflow-ellipsis;
		white-space: nowrap;
	}

	// `min-width: 0` lets the group shrink below the width of the username, so
	// the username can show an ellipsis once the timestamp has moved down.
	&__user {
		display: flex;
		align-items: center;
		gap: @spacing-25;
		min-width: @size-0;
	}

	&__meta {
		display: flex;
		flex-shrink: 0;
		align-items: center;
		gap: @spacing-25;
		// Not wider than the card, so a long timestamp gets its ellipsis.
		max-width: @size-full;
	}

	&__icon {
		flex-shrink: 0;
	}

	&__icon .cdx-button:enabled {
		.cdx-mixin-button-layout-flush( 'start', true );
		color: inherit;
	}

	// FeedCard lays the header out as a centred row. The description stays
	// beside the title while both fit, and moves onto its own line when they do
	// not, so this card aligns them on the baseline and lets the row wrap.
	.personal-dashboard-feed__card__header {
		flex-wrap: wrap;
		align-items: baseline;
		// `row-gap column-gap`: the row gap only applies once the description has
		// moved down, so it never indents the description beside the title.
		gap: @spacing-25 @spacing-35;
	}

	// The page description keeps to a single line; a long one is cut short. The
	// weight is reset because the Codex card title is bold.
	&__description {
		font-weight: @font-weight-normal;
		overflow: hidden;
		text-overflow: @text-overflow-ellipsis;
		white-space: nowrap;
	}

	// The edit summary keeps at most two lines, then ends with an ellipsis.
	// -webkit-box is block-level, so this also puts the summary on its own line
	// below the description.
	&__edit-summary,
	&__edit-summary--missing {
		display: -webkit-box;
		line-clamp: 2;
		overflow: hidden;
		-webkit-line-clamp: 2;
		// Non-standard but still implemented by browsers.
		// Without this, line-clamp does not work.
		-webkit-box-orient: vertical;
	}

	&__edit-summary--missing {
		font-style: italic;
	}

	// The visited modifier is FeedCard's, and sits on this same element.
	&.personal-dashboard-feed__card--visited &__username {
		font-weight: @font-weight-normal;
	}

	// The card owns this row rather than styling Codex's supporting-text
	// wrapper, which is not ours to depend on. A span, because that wrapper is
	// one too.
	&__flags {
		display: flex;
		flex-wrap: wrap;
		gap: @spacing-25;
	}
}
</style>
