<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\Tests\Integration;

use MediaWiki\Tests\ExtensionJsonTestBase;

/**
 * @group ModeratorToolkit
 * @coversNothing
 */
class ModeratorToolkitExtensionJsonTest extends ExtensionJsonTestBase {

	/** @inheritDoc */
	protected static bool $requireHookHandlers = true;

	/** @inheritDoc */
	protected static string $extensionJsonPath = __DIR__ . '/../../../extension.json';

}
