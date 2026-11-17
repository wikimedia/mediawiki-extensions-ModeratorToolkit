<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\Tests\Unit\ReengagementEmail;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\EditedPage;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\EmailComposer;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\Recipient;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Language\Language;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Wikimedia\Message\ParamType;
use Wikimedia\Message\ScalarParam;
use Wikimedia\ObjectCache\EmptyBagOStuff;

/**
 * The composer follows the T422003 design: links with the tracking parameter, a visual diff link, and no
 * "new edits" line for a page that nobody edited after the user. Messages show as "key: param; param".
 *
 * @covers \MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\EmailComposer
 */
final class EmailComposerTest extends MediaWikiUnitTestCase {

	private const string PREFIX = 'moderator-toolkit-reengagement-email-';

	/** One page with new edits, one without, and one with a name that needs escaping. */
	private Recipient $recipient;

	protected function setUp(): void {
		parent::setUp();
		$this->recipient = new Recipient( 'Example <user>', 123, 11343, [
			new EditedPage( 'Bamboo', 14, 4, 233, 1001, 'https://upload.example/Bamboo.jpg' ),
			new EditedPage( 'Raccoon', 0, 0, 200, 1002, null ),
			new EditedPage( 'The "Quoted" & page', 1, 1, 12, 1003, null ),
		], true );
	}

	/**
	 * The subject has the total views as a number parameter, and the username for GENDER.
	 */
	public function testPutsTheTotalViewsInTheSubject(): void {
		$email = $this->newComposer()->compose( $this->recipient );

		$this->assertSame( self::PREFIX . 'subject: 11343; Example <user>', $email['subject'] );
	}

	/**
	 * The HTML has every link with the tracking parameter, escapes the CSV text, and leaves out the "new edits"
	 * line for a page without new edits.
	 */
	public function testBuildsTheHtml(): void {
		$html = $this->newComposer()->compose( $this->recipient )['html'];
		$xpath = self::parse( $html );

		$this->assertStringStartsWith( '<!DOCTYPE html>', $html );
		$this->assertSame( [ 'en' ], self::query( $xpath, '/html/@lang' ) );
		$this->assertSame( [ 'ltr' ], self::query( $xpath, '/html/@dir' ) );

		$this->assertSame(
			[ 'https://upload.wikimedia.org/wikipedia/donate/9/9c/Logo-wikipedia-landscape-wkfrds.png' ],
			self::query( $xpath, '//img[@alt="Wikipedia"]/@src' )
		);
		$this->assertContains( '11,343', self::query( $xpath, '//h1/span' ) );
		$this->assertSame(
			[ self::PREFIX . 'cta-heading', self::PREFIX . 'pages-heading: Example <user>' ],
			self::query( $xpath, '//h2' )
		);
		$this->assertContains( self::PREFIX . 'intro: Example <user>; 225000', self::query( $xpath, '//td' ) );

		$this->assertContains( 'The "Quoted" & page', self::query( $xpath, '//a' ) );
		$this->assertSame( [ 'https://upload.example/Bamboo.jpg' ], self::query( $xpath, '//img[@alt=""]/@src' ) );

		// Every link is tracked (T422003). Bamboo's thumbnail links to it too, and Raccoon has no diff link.
		$this->assertSame( [
			'https://wiki.example/wiki/Special:Homepage?wprov=reew1',
			'https://wiki.example/wiki/Bamboo?wprov=reew1',
			'https://wiki.example/wiki/Bamboo?wprov=reew1',
			'https://wiki.example/wiki/Bamboo?diff=1001&diffmode=visual&wprov=reew1',
			'https://wiki.example/wiki/Raccoon?wprov=reew1',
			'https://wiki.example/wiki/The_%22Quoted%22_%26_page?wprov=reew1',
			'https://wiki.example/wiki/The_%22Quoted%22_%26_page?diff=1003&diffmode=visual&wprov=reew1',
			'https://wiki.example/wiki/Special:Preferences?wprov=reew1#mw-input-wpecho-subscriptions-email-ge-newcomer',
		], self::query( $xpath, '//a/@href' ) );

		// The page name is the link for screen readers and the keyboard, so they skip the image link.
		$this->assertSame(
			[ 'https://wiki.example/wiki/Bamboo?wprov=reew1' ],
			self::query( $xpath, '//a[@aria-hidden="true"][@tabindex="-1"][img]/@href' )
		);

		$this->assertSame( [
			self::PREFIX . 'page-new-edits-by: ' . self::PREFIX . 'page-new-edits: 14; 4',
			self::PREFIX . 'page-new-edits-by: ' . self::PREFIX . 'page-new-edits: 1; 1',
		], self::query( $xpath, '//div[a]' ) );
	}

	/**
	 * The plain text has the same content, with the URLs written out. Messages that address the user get the
	 * username for GENDER.
	 */
	public function testWritesTheLinksOutInThePlainText(): void {
		$text = $this->newComposer()->compose( $this->recipient )['text'];

		$this->assertStringStartsWith( self::PREFIX . 'subject: 11343; Example <user>', $text );
		$this->assertStringContainsString( self::PREFIX . 'intro: Example <user>; 225000', $text );

		$this->assertStringContainsString( self::PREFIX . 'cta-text: Example <user>', $text );
		$this->assertStringContainsString(
			self::PREFIX . 'cta-button: Example <user>: https://wiki.example/wiki/Special:Homepage?wprov=reew1', $text
		);

		$this->assertStringContainsString( self::PREFIX . 'pages-heading: Example <user>', $text );
		$this->assertStringContainsString( 'Bamboo: https://wiki.example/wiki/Bamboo?wprov=reew1', $text );
		$this->assertStringContainsString( self::PREFIX . 'page-new-edits-by: ' . self::PREFIX
			. 'page-new-edits: 14; 4: https://wiki.example/wiki/Bamboo?diff=1001&diffmode=visual&wprov=reew1', $text );
		$this->assertStringNotContainsString( 'diff=1002', $text );

		$this->assertStringEndsWith(
			"-- \n" . self::PREFIX . 'footer: ' . self::PREFIX . 'footer-preferences; Example <user> '
				. 'https://wiki.example/wiki/Special:Preferences?wprov=reew1'
				. '#mw-input-wpecho-subscriptions-email-ge-newcomer',
			$text
		);
	}

	/**
	 * A page name that is not a valid title stops the email.
	 */
	public function testRejectsAnInvalidPageName(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( '"Invalid|title" is not a valid page name' );

		$recipient = new Recipient( 'Example user', 123, 10, [
			new EditedPage( 'Bamboo', 0, 0, 10, 1001, null ),
			new EditedPage( 'Invalid|title', 0, 0, 10, 1002, null ),
		], true );

		$this->newComposer()->compose( $recipient );
	}

	/**
	 * Makes a composer with fake messages, titles, and language.
	 */
	private function newComposer(): EmailComposer {
		$messageLocalizer = $this->createMock( MessageLocalizer::class );
		$messageLocalizer->method( 'msg' )->willReturnCallback( $this->newMessage( ... ) );

		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitle' )->willReturnCallback(
			function ( int $namespace, string $text, string $fragment = '' ): Title&MockObject {
				return $this->newTitle( $namespace === NS_SPECIAL ? "Special:$text" : $text, $fragment );
			}
		);
		$titleFactory->method( 'newFromText' )->willReturnCallback(
			function ( string $text ): (Title&MockObject)|null {
				return $text === 'Invalid|title' ? null : $this->newTitle( $text, '' );
			}
		);

		// On an English wiki, the local name of a special page is its canonical name.
		$specialPageFactory = $this->createMock( SpecialPageFactory::class );
		$specialPageFactory->method( 'getLocalNameFor' )->willReturnArgument( 0 );

		$language = $this->createMock( Language::class );
		$language->method( 'formatNum' )->willReturnCallback(
			static fn ( int $number ): string => number_format( $number )
		);
		$language->method( 'getHtmlCode' )->willReturn( 'en' );
		$language->method( 'getDir' )->willReturn( 'ltr' );

		$templateParser = new TemplateParser( EmailComposer::TEMPLATE_DIR, new EmptyBagOStuff() );

		return new EmailComposer(
			$messageLocalizer,
			$titleFactory,
			$specialPageFactory,
			$language,
			$templateParser,
			'Wikipedia',
		);
	}

	/**
	 * Makes a message that shows its key and parameters, and that keeps raw parameters unescaped in HTML.
	 */
	private function newMessage( string $key, mixed ...$params ): Message&MockObject {
		$format = static function ( bool $html ) use ( $key, $params ): string {
			if ( $params === [] ) {
				return $key;
			}

			$values = array_map( static fn ( mixed $param ): string => self::formatParam( $param, $html ), $params );

			return "$key: " . implode( '; ', $values );
		};

		$message = $this->createMock( Message::class );
		$message->method( 'inLanguage' )->willReturnSelf();
		$message->method( 'text' )->willReturn( $format( false ) );
		$message->method( 'escaped' )->willReturn( $format( true ) );

		return $message;
	}

	/**
	 * Shows a message parameter as text. In HTML, escapes every parameter except a raw one.
	 */
	private static function formatParam( mixed $param, bool $html ): string {
		if ( $param instanceof ScalarParam ) {
			$value = (string)$param->getValue();
			$escape = $html && $param->getType() !== ParamType::RAW;
		} else {
			$value = is_string( $param ) ? $param : '';
			$escape = $html;
		}

		return $escape ? htmlspecialchars( $value ) : $value;
	}

	/**
	 * Makes a title whose canonical URL shows the name, the query, and the fragment. Like a real title, it keeps ":".
	 */
	private function newTitle( string $text, string $fragment ): Title&MockObject {
		$path = str_replace( '%3A', ':', rawurlencode( str_replace( ' ', '_', $text ) ) );
		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedText' )->willReturn( $text );
		$title->method( 'getCanonicalURL' )->willReturnCallback(
			/** @param array<string,string|int> $query */
			static fn ( array $query ): string => "https://wiki.example/wiki/$path?" . http_build_query( $query )
				. ( $fragment === '' ? '' : "#$fragment" )
		);

		return $title;
	}

	/**
	 * Parses the HTML, so that the assertions do not depend on the line breaks in the template.
	 */
	private static function parse( string $html ): DOMXPath {
		$document = new DOMDocument();
		$document->loadHTML( $html, LIBXML_NOERROR );

		return new DOMXPath( $document );
	}

	/**
	 * Gets the text of each node that an XPath query finds, with the white space normalized.
	 *
	 * @return list<string>
	 */
	private static function query( DOMXPath $xpath, string $expression ): array {
		$values = [];

		foreach ( $xpath->query( $expression ) ?: [] as $node ) {
			$values[] = trim( preg_replace( '/\s+/', ' ', $node->textContent ) ?? '' );
		}

		return $values;
	}
}
