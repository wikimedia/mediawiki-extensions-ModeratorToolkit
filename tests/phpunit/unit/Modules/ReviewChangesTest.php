<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\Tests\Unit\Modules;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ModeratorToolkit\Modules\ReviewChanges;
use MediaWiki\Extension\PersonalDashboard\Feed\IRevisionScoreLookup;
use MediaWiki\Message\Message;
use MediaWikiUnitTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * The module builds no feed of its own any more; the client reads it from the
 * endpoint. All it still hands the client is the wiki's high-risk threshold,
 * which the card needs to decide whether to flag an edit.
 *
 * Deliberately free of any ORES type, so it runs where ORES is not installed —
 * which includes CI, since PersonalDashboard does not require it.
 *
 * @covers \MediaWiki\Extension\ModeratorToolkit\Modules\ReviewChanges
 */
class ReviewChangesTest extends MediaWikiUnitTestCase {

	private function newModule( ?array $threshold ): ReviewChanges {
		$scoreLookup = $this->createMock( IRevisionScoreLookup::class );
		$scoreLookup->method( 'getHighRiskThreshold' )->willReturn( $threshold );

		$context = $this->createMock( IContextSource::class );
		$context->method( 'msg' )->willReturnCallback( function ( $key ) {
			$message = $this->createMock( Message::class );
			$message->method( 'text' )->willReturn( 'text-' . $key );
			return $message;
		} );

		return new ReviewChanges( $context, $scoreLookup );
	}

	public function testHandsTheCardTheWikisThreshold() {
		$threshold = [
			'model' => 'revertrisklanguageagnostic',
			'class' => 'true',
			'min' => 0.95,
		];

		$this->assertSame(
			[ 'wgModeratorToolkitReviewChangesHighRevertRiskThreshold' => $threshold ],
			$this->newModule( $threshold )->getJsConfigVars()
		);
	}

	public function testSaysNothingWhereTheWikiConfiguredNoThreshold() {
		// The card reads this as "make no check", rather than flagging nothing
		// because every score fell below an invented default.
		$this->assertSame(
			[ 'wgModeratorToolkitReviewChangesHighRevertRiskThreshold' => null ],
			$this->newModule( null )->getJsConfigVars()
		);
	}

	public function testShipsNoFeedItemsToThePage() {
		$configVars = $this->newModule( null )->getJsConfigVars();

		$this->assertSame(
			[ 'wgModeratorToolkitReviewChangesHighRevertRiskThreshold' ],
			array_keys( $configVars )
		);
	}

	public function testNamesItsHeaderMessage() {
		$module = TestingAccessWrapper::newFromObject( $this->newModule( null ) );

		$this->assertSame(
			'text-personal-dashboard-risky-article-edits-header',
			$module->getHeaderText()
		);
	}

	public function testLoadsTheReviewChangesModuleOnly() {
		$module = TestingAccessWrapper::newFromObject( $this->newModule( null ) );

		$this->assertSame( [ 'ext.moderatorToolkit.reviewChanges' ], $module->getModules() );
	}

	public function testDeclaresHeaderMenuSoTheFrameEmitsAHeaderMountSlot() {
		$module = $this->newModule( null );

		$this->assertTrue(
			TestingAccessWrapper::newFromObject( $module )->hasHeaderMenu(),
			'the module needs a header slot for its overflow menu (T433725)'
		);
	}

	public function testCarriesNoSubheaderSinceItsCopyMovedIntoTheHeaderMenu() {
		$module = $this->newModule( null );

		$this->assertSame(
			'',
			TestingAccessWrapper::newFromObject( $module )->getSubheaderText(),
			'the description belongs to the About panel now, not the card (T433725)'
		);
	}
}
