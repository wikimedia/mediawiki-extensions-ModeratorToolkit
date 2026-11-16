<?php

declare( strict_types = 1 );
namespace MediaWiki\Extension\ModeratorToolkit\Tests\Unit;

use MediaWiki\Extension\ModeratorToolkit\Experiments;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ModeratorToolkit\Experiments
 */
class ExperimentsTest extends MediaWikiUnitTestCase {

	public function testAllPinsTheRegisteredExperiment() {
		$this->assertSame(
			[ 'review-changes-home' => [ 'treatment' => 'review-changes-home' ] ],
			Experiments::all()
		);
	}

	public function testAllKeysAreNonEmptyStrings() {
		foreach ( array_keys( Experiments::all() ) as $experimentName ) {
			$this->assertIsString( $experimentName );
			$this->assertNotSame( '', $experimentName );
		}
	}

	public function testAllValuesAreVariantMaps() {
		foreach ( Experiments::all() as $variantMap ) {
			$this->assertIsArray( $variantMap );
			foreach ( $variantMap as $variant => $moduleGroup ) {
				$this->assertIsString( $variant );
				$this->assertIsString( $moduleGroup );
				$this->assertNotSame( '', $moduleGroup );
			}
		}
	}

	public function testNoVariantMapsToTheBaselineGroup() {
		// A variant absent from the map falls through to the baseline; naming
		// 'moderators' explicitly would measure the wrong contrast once a
		// baseline other than 'moderators' exists for some eligibility slice.
		foreach ( Experiments::all() as $experimentName => $variantMap ) {
			foreach ( $variantMap as $variant => $moduleGroup ) {
				$this->assertNotSame(
					'moderators',
					$moduleGroup,
					"$experimentName's '$variant' variant must not map to 'moderators'"
				);
			}
		}
	}

	public function testTagOnlyEntryIsLegal() {
		$manifest = Experiments::all() + [ 'tag-only-example' => [] ];
		$this->assertSame( [], $manifest[ 'tag-only-example' ] );
	}
}
