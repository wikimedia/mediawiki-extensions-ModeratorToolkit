<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\Modules;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\PersonalDashboard\Feed\IRevisionScoreLookup;
use MediaWiki\Extension\PersonalDashboard\Modules\BaseModule;
use MediaWiki\Extension\PersonalDashboard\Modules\Trait\FeedLoadingMarkup;
use MediaWiki\Html\Html;

/**
 * Class for the ReviewChanges module.
 *
 * The feed is not built here: the client reads it from
 * GET /personaldashboard/v0/feed, a page at a time.
 */
class ReviewChanges extends BaseModule {

	use FeedLoadingMarkup;

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
		return $this->msg( 'moderator-toolkit-review-changes-header' )->text();
	}

	/**
	 * The card has no description of its own. The header menu's "About Review
	 * changes" panel shows it instead.
	 * @inheritDoc
	 */
	protected function hasHeaderMenu(): bool {
		return true;
	}

	/**
	 * The no-JS fallback footer: a plain link into recent changes, shown on every
	 * viewport when JS is off. With JS the client renders its own detail-branched
	 * footer inside the body slot, so this whole footer section is hidden under the
	 * .client-js no-js-fallback rule.
	 * @inheritDoc
	 */
	protected function getFooter(): string {
		return Html::rawElement(
			'div',
			[ 'class' => 'personal-dashboard-module-no-js-fallback' ],
			$this->msg( 'moderator-toolkit-review-changes-footer-preamble' )->parse()
		);
	}

	/**
	 * The progress bar of the island while it loads its first page.
	 * @inheritDoc
	 */
	protected function getLoadingMarkup(): string {
		return $this->getFeedLoadingMarkup();
	}

	/** @inheritDoc */
	protected function getModuleStyles(): array {
		return [ 'ext.moderatorToolkit.reviewChanges.styles' ];
	}

	/** @inheritDoc */
	protected function getModules(): array {
		return [ 'ext.moderatorToolkit.reviewChanges' ];
	}
}
