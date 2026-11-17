<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\ReengagementEmail;

use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentityLookup;

/**
 * Checks, at the time of sending, whether a recipient from the CSV file can join the experiment.
 *
 * Some hours pass after the notebook runs, and a user can change preferences, or get blocked or renamed.
 */
final readonly class RecipientChecker {

	public function __construct(
		private UserIdentityLookup $userIdentityLookup,
		private UserFactory $userFactory,
		private UserOptionsLookup $userOptionsLookup,
	) {
	}

	/**
	 * Gets the reason why the recipient cannot join the experiment.
	 *
	 * This ignores the "Send email" column, so that the analysis can remove the same users from both groups.
	 *
	 * @return SkipReason|null Null if the recipient can join the experiment
	 */
	public function getSkipReason( Recipient $recipient ): ?SkipReason {
		$userIdentity = $this->userIdentityLookup->getUserIdentityByUserId( $recipient->userId );

		if ( $userIdentity === null ) {
			return SkipReason::UserNotFound;
		}

		// The email shows the username, and a different name can also mean that the row is from another wiki.
		if ( $userIdentity->getName() !== $recipient->username ) {
			return SkipReason::UsernameChanged;
		}

		$user = $this->userFactory->newFromUserIdentity( $userIdentity );

		if ( !$user->isEmailConfirmed() ) {
			return SkipReason::EmailNotConfirmed;
		}

		if ( $user->isLocked() ) {
			return SkipReason::Locked;
		}

		// The DE1.3.12 product document excludes partial blocks too.
		if ( $user->getBlock() !== null ) {
			return SkipReason::Blocked;
		}

		// The Echo email preferences for Growth notifications (T422003#12408051). -1 means "no emails".
		if ( $this->userOptionsLookup->getIntOption( $user, 'echo-email-frequency' ) < 0 ) {
			return SkipReason::AllEmailNotificationsOff;
		}

		if ( !$this->userOptionsLookup->getBoolOption( $user, 'echo-subscriptions-email-ge-newcomer' ) ) {
			return SkipReason::NewcomerEmailsOff;
		}

		// The button in the email opens Special:Homepage, and the notebook selects only users with the homepage on.
		if ( !$this->userOptionsLookup->getBoolOption( $user, 'growthexperiments-homepage-enable' ) ) {
			return SkipReason::HomepageOff;
		}

		return null;
	}
}
