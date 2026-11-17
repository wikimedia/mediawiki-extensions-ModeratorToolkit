<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\ReengagementEmail;

/**
 * Why a recipient is left out of the experiment, in either group, in the order of the checks.
 */
enum SkipReason: string {

	// The account
	case UserNotFound = 'user not found';
	case UsernameChanged = 'username changed';
	case EmailNotConfirmed = 'email address not confirmed';
	case Locked = 'account locked';
	case Blocked = 'blocked';

	// The preferences
	case AllEmailNotificationsOff = 'all email notifications off';
	case NewcomerEmailsOff = 'newcomer emails off';
	case HomepageOff = 'homepage off';

	// Checked by the script, after RecipientChecker
	case AlreadyInExperiment = 'already in the experiment';
}
