<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\ReengagementEmail;

/**
 * An article that the recipient edited, with its activity since their edit.
 */
final readonly class EditedPage {

	public function __construct(
		public string $name,
		/** Edits since the recipient's edit */
		public int $newEdits,
		/** Other people who edited since the recipient's edit */
		public int $newEditors,
		public int $views,
		public int $latestRevisionId,
		public ?string $thumbnailUrl,
	) {
	}
}
