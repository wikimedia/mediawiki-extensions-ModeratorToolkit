<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\ReengagementEmail;

use RuntimeException;
use Wikimedia\ObjectCache\BagOStuff;

/**
 * Remembers in the main object stash which users were in the experiment, in either group, so that a rerun of a
 * file does not email anyone twice (T422003). The stash can lose a key in rare cases.
 */
final readonly class ParticipantStore {

	/** Longer than the experiment and its analysis */
	private const int TTL = 2 * BagOStuff::TTL_MONTH;

	public function __construct(
		private BagOStuff $stash,
	) {
	}

	/**
	 * Checks whether a run already added the user.
	 *
	 * @throws RuntimeException If the stash cannot be read
	 */
	public function has( int $userId ): bool {
		$watchPoint = $this->stash->watchErrors();
		$value = $this->stash->get( $this->makeKey( $userId ) );
		$this->checkErrors( $watchPoint );

		return $value !== false;
	}

	/**
	 * Adds the user, unless the store already has them.
	 *
	 * @return bool False if the user was already in the experiment
	 * @throws RuntimeException If the stash cannot be written
	 */
	public function add( int $userId ): bool {
		$watchPoint = $this->stash->watchErrors();
		$added = $this->stash->add( $this->makeKey( $userId ), 1, self::TTL );
		$this->checkErrors( $watchPoint );

		return $added;
	}

	/**
	 * The key has only the user ID, so that a rerun of a file finds the users of the first run.
	 */
	private function makeKey( int $userId ): string {
		return $this->stash->makeKey( 'moderator-toolkit-reengagement-email-participant', $userId );
	}

	/**
	 * Tells a stash error apart from a missing or existing key.
	 *
	 * @throws RuntimeException If the stash reported an error after the watch point
	 */
	private function checkErrors( int $watchPoint ): void {
		if ( $this->stash->getLastError( $watchPoint ) !== BagOStuff::ERR_NONE ) {
			throw new RuntimeException( 'The main object stash has a problem' );
		}
	}
}
