<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\Modules;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\PersonalDashboard\Feed\IRevisionScoreLookup;
use MediaWiki\Extension\PersonalDashboard\Modules\BaseModule;

/**
 * Class for the ReviewChanges module.
 *
 * The feed is not built here: the client reads it from
 * GET /personaldashboard/v0/feed, a page at a time.
 */
class ReviewChanges extends BaseModule {

	public function __construct(
		IContextSource $context,
		private readonly IRevisionScoreLookup $scoreLookup,
	) {
		parent::__construct( $context, shouldWrapModuleWithLink: true );
	}

	/**
	 * The wiki's high-risk threshold, so the card can flag an edit whose score
	 * reaches it. Wiki-wide configuration rather than anything about the
	 * viewer, so it travels in the page instead of the per-viewer feed.
	 *
	 * @inheritDoc
	 */
	public function getJsConfigVars(): array {
		return [
			'wgModeratorToolkitReviewChangesHighRevertRiskThreshold' =>
				$this->scoreLookup->getHighRiskThreshold(),
		];
	}

	/** @inheritDoc */
	protected function getHeaderText(): string {
		return $this->msg( 'personal-dashboard-risky-article-edits-header' )->text();
	}

	/**
	 * The card has no description of its own. The header menu's "About Review
	 * changes" panel shows it instead.
	 * @inheritDoc
	 */
	protected function hasHeaderMenu(): bool {
		return true;
	}

	/** @inheritDoc */
	protected function getModules(): array {
		return [ 'ext.moderatorToolkit.reviewChanges' ];
	}
}
