<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\Specials;

use MediaWiki\Extension\ModeratorToolkit\Experiments;
use MediaWiki\Extension\PersonalDashboard\Specials\AbstractSpecialDashboard;
use MediaWiki\Message\Message;

/**
 * The Moderator Tools dashboard, at Special:PersonalDashboard.
 *
 * A subclass of PersonalDashboard's AbstractSpecialDashboard. It renders
 * through the same code as any other dashboard.
 */
class SpecialPersonalDashboard extends AbstractSpecialDashboard {

	/** @inheritDoc */
	protected function getPageName(): string {
		return 'PersonalDashboard';
	}

	/** @inheritDoc */
	protected function getBaselineModuleGroup(): string {
		return 'moderators';
	}

	/**
	 * The manifest in Experiments is this dashboard's, because it names this
	 * dashboard's module groups.
	 *
	 * @inheritDoc
	 */
	protected function getExperimentManifest(): array {
		return Experiments::all();
	}

	/**
	 * The page is still in beta, so it stays out of Special:SpecialPages. This
	 * is a product decision about this dashboard, not a rule for every one.
	 *
	 * @inheritDoc
	 */
	public function isListed(): bool {
		return false;
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'wiki';
	}

	/**
	 * Overridden in order to inject the current user's name as message parameter
	 *
	 * @inheritDoc
	 */
	public function getDescription(): Message {
		return $this->msg( 'moderator-toolkit-specialpage-title' )
			->params( $this->getUser()->getName() );
	}
}
