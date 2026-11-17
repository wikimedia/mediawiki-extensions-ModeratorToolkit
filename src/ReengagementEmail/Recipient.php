<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\ReengagementEmail;

/**
 * One recipient from a row of the CSV file, in either group. Recipients in the control group get no email.
 */
final readonly class Recipient {

	public function __construct(
		public string $username,
		public int $userId,
		public int $totalViews,
		/** @var non-empty-list<EditedPage> One to three pages, in CSV order */
		public array $pages,
		/** False for users in the control group */
		public bool $sendEmail,
	) {
	}
}
