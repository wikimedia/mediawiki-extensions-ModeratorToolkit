<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\Tests\Unit\ReengagementEmail;

use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\ParticipantStore;
use MediaWikiUnitTestCase;
use RuntimeException;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\HashBagOStuff;

/**
 * A user can join the experiment once, and a stash error throws.
 *
 * @covers \MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\ParticipantStore
 */
final class ParticipantStoreTest extends MediaWikiUnitTestCase {

	/**
	 * A second add fails for the same user.
	 */
	public function testAddsEachUserOnce(): void {
		$participantStore = new ParticipantStore( new HashBagOStuff() );
		$this->assertFalse( $participantStore->has( 1 ) );

		$this->assertTrue( $participantStore->add( 1 ) );
		$this->assertTrue( $participantStore->has( 1 ) );
		$this->assertFalse( $participantStore->add( 1 ) );
		$this->assertFalse( $participantStore->has( 2 ) );
	}

	/**
	 * A read error does not look like "not in the experiment".
	 */
	public function testThrowsOnAReadError(): void {
		$this->expectException( RuntimeException::class );
		( new ParticipantStore( $this->newFailingStash() ) )->has( 1 );
	}

	/**
	 * An error while adding the user throws.
	 */
	public function testThrowsOnAnAddError(): void {
		$this->expectException( RuntimeException::class );
		( new ParticipantStore( $this->newFailingStash() ) )->add( 1 );
	}

	/**
	 * Makes a stash that reports an error after every operation.
	 */
	private function newFailingStash(): BagOStuff {
		$stash = $this->createMock( BagOStuff::class );
		$stash->method( 'makeKey' )->willReturn( 'key' );
		$stash->method( 'watchErrors' )->willReturn( 1 );
		$stash->method( 'getLastError' )->willReturn( BagOStuff::ERR_UNREACHABLE );

		return $stash;
	}
}
