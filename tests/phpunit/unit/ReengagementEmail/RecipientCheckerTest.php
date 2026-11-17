<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\Tests\Unit\ReengagementEmail;

use Generator;
use MediaWiki\Block\AbstractBlock;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\EditedPage;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\Recipient;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\RecipientChecker;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\SkipReason;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserIdentityValue;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The checker skips a recipient for each reason, and only for that reason.
 *
 * @covers \MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\RecipientChecker
 * @covers \MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\SkipReason
 */
final class RecipientCheckerTest extends MediaWikiUnitTestCase {

	private const int USER_ID = 123;

	private const string USERNAME = 'Example user';

	/**
	 * A user that can join the experiment. Each case changes one thing.
	 */
	private const array ELIGIBLE = [
		'sendEmail' => true,
		'currentName' => self::USERNAME,
		'emailConfirmed' => true,
		'locked' => false,
		'block' => null,
		'emailFrequency' => 0,
		'newcomerEmails' => true,
		'homepage' => true,
	];

	/**
	 * Cases as changes to an eligible user, with the expected skip reason.
	 */
	public static function provideSkipReasons(): Generator {
		yield 'eligible' => [ [], null ];
		yield 'eligible with daily email digests' => [ [ 'emailFrequency' => 1 ], null ];
		yield 'eligible in the control group' => [ [ 'sendEmail' => false ], null ];
		yield 'control group with a block' => [
			[ 'sendEmail' => false, 'block' => 'sitewide' ],
			SkipReason::Blocked,
		];
		yield 'user not found' => [ [ 'currentName' => null ], SkipReason::UserNotFound ];
		yield 'renamed user' => [ [ 'currentName' => 'New name' ], SkipReason::UsernameChanged ];
		yield 'email not confirmed' => [ [ 'emailConfirmed' => false ], SkipReason::EmailNotConfirmed ];
		yield 'locked' => [ [ 'locked' => true ], SkipReason::Locked ];
		yield 'sitewide block' => [ [ 'block' => 'sitewide' ], SkipReason::Blocked ];
		yield 'partial block' => [ [ 'block' => 'partial' ], SkipReason::Blocked ];
		yield 'email notifications off' => [ [ 'emailFrequency' => -1 ], SkipReason::AllEmailNotificationsOff ];
		yield 'newcomer emails off' => [ [ 'newcomerEmails' => false ], SkipReason::NewcomerEmailsOff ];
		yield 'homepage off' => [ [ 'homepage' => false ], SkipReason::HomepageOff ];
	}

	/**
	 * The checker returns the expected reason for a user that differs from an eligible user in one way.
	 *
	 * @dataProvider provideSkipReasons
	 * @param array $changes Changes to self::ELIGIBLE
	 * @phan-param array{
	 *     sendEmail?: bool,
	 *     currentName?: ?string,
	 *     emailConfirmed?: bool,
	 *     locked?: bool,
	 *     block?: 'partial'|'sitewide'|null,
	 *     emailFrequency?: int,
	 *     newcomerEmails?: bool,
	 *     homepage?: bool
	 * } $changes
	 */
	public function testSkipsForEachReason( array $changes, ?SkipReason $expected ): void {
		$case = $changes + self::ELIGIBLE;

		$userIdentityLookup = $this->createMock( UserIdentityLookup::class );
		$userIdentityLookup->method( 'getUserIdentityByUserId' )->willReturnCallback(
			static function ( int $userId ) use ( $case ): ?UserIdentityValue {
				if ( $userId !== self::USER_ID || $case['currentName'] === null ) {
					return null;
				}

				return new UserIdentityValue( $userId, $case['currentName'] );
			}
		);

		$user = $this->createUserMock();
		$user->method( 'isEmailConfirmed' )->willReturn( $case['emailConfirmed'] );
		$user->method( 'isLocked' )->willReturn( $case['locked'] );
		$block = null;

		if ( $case['block'] !== null ) {
			$block = $this->createMock( AbstractBlock::class );
			$block->method( 'isSitewide' )->willReturn( $case['block'] === 'sitewide' );
		}

		$user->method( 'getBlock' )->willReturn( $block );

		// Return an ineligible user for any other identity, so that the eligible case fails.
		$otherUser = $this->createUserMock();
		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newFromUserIdentity' )->willReturnCallback(
			static function ( UserIdentity $identity ) use ( $user, $otherUser ): User&MockObject {
				$isRecipient = $identity->getId() === self::USER_ID && $identity->getName() === self::USERNAME;

				return $isRecipient ? $user : $otherUser;
			}
		);

		// Return a value that skips the user for any other user or option, so that the eligible case fails.
		$userOptionsLookup = $this->createMock( UserOptionsLookup::class );
		$userOptionsLookup->method( 'getIntOption' )->willReturnCallback(
			static function ( UserIdentity $optionUser, string $option ) use ( $user, $case ): int {
				if ( $optionUser !== $user || $option !== 'echo-email-frequency' ) {
					return -1;
				}

				return $case['emailFrequency'];
			}
		);
		$userOptionsLookup->method( 'getBoolOption' )->willReturnCallback(
			static function ( UserIdentity $optionUser, string $option ) use ( $user, $case ): bool {
				if ( $optionUser !== $user ) {
					return false;
				}

				return match ( $option ) {
					'echo-subscriptions-email-ge-newcomer' => $case['newcomerEmails'],
					'growthexperiments-homepage-enable' => $case['homepage'],
					default => false,
				};
			}
		);

		$recipient = new Recipient(
			self::USERNAME,
			self::USER_ID,
			10,
			[ new EditedPage( 'Bamboo', 0, 0, 10, 2, null ) ],
			$case['sendEmail'],
		);
		$checker = new RecipientChecker( $userIdentityLookup, $userFactory, $userOptionsLookup );

		$this->assertSame( $expected, $checker->getSkipReason( $recipient ) );
	}

	/**
	 * Creates a user mock with the User type, so that phan can compare it with a UserIdentity.
	 */
	private function createUserMock(): User&MockObject {
		return $this->createMock( User::class );
	}
}
