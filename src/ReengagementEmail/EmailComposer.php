<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\ReengagementEmail;

use InvalidArgumentException;
use MediaWiki\Html\Html;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Language\Language;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;

/**
 * Makes the re-engagement email for one recipient, as in the T422003 design.
 */
final readonly class EmailComposer {

	public const string TEMPLATE_DIR = __DIR__ . '/../../templates';

	private const string TEMPLATE = 'ReengagementEmail';

	/** Every link has this query parameter, so that the analysis can count the clicks (T422003). */
	private const array TRACKING_QUERY = [ 'wprov' => 'reew1' ];

	/** The number of editors in the introduction, from the T422003 design */
	private const int EDITOR_COUNT = 225_000;

	/**
	 * The style of the links in messages. The template has the other styles.
	 *
	 * The underline shows the link without its color only (WCAG 1.4.1), as in the Fundraising team's emails.
	 */
	private const string LINK_STYLE = 'color:#3366cc;text-decoration:underline;';

	public function __construct(
		private MessageLocalizer $messageLocalizer,
		private TitleFactory $titleFactory,
		private SpecialPageFactory $specialPageFactory,
		private Language $language,
		private TemplateParser $templateParser,
		private string $siteName,
	) {
	}

	/**
	 * @return array{subject:string,text:string,html:string}
	 * @throws InvalidArgumentException If a page name in the recipient's row is not a valid title
	 */
	public function compose( Recipient $recipient ): array {
		$data = $this->getData( $recipient );

		return [
			'subject' => $data['subject'],
			'text' => self::getText( $data ),
			'html' => $this->templateParser->processTemplate( self::TEMPLATE, $data ),
		];
	}

	/**
	 * Gets the content of both bodies. The keys follow the order of the design.
	 *
	 * @return array<string,mixed>
	 */
	private function getData( Recipient $recipient ): array {
		$totalViewsParam = Message::numParam( $recipient->totalViews );
		// For GENDER in messages that address the recipient. A username cannot contain wikitext syntax.
		$username = $recipient->username;

		// The fragment is the id of the email checkbox in the "Growth features" row, so the page scrolls to it.
		$preferencesTitle = $this->getSpecialPageTitle(
			'Preferences', 'mw-input-wpecho-subscriptions-email-ge-newcomer'
		);
		$preferencesUrl = self::getUrl( $preferencesTitle );
		$preferencesLinkText = $this->msg( 'moderator-toolkit-reengagement-email-footer-preferences' )->text();
		$preferencesLink = Html::element(
			'a', [ 'href' => $preferencesUrl, 'style' => self::LINK_STYLE ], $preferencesLinkText
		);

		return [
			'lang' => $this->language->getHtmlCode(),
			'dir' => $this->language->getDir(),
			'subject' => $this->msg(
				'moderator-toolkit-reengagement-email-subject', $totalViewsParam, $username
			)->text(),

			'siteName' => $this->siteName,
			'totalViews' => $this->language->formatNum( $recipient->totalViews ),
			'totalViewsLabel' => $this->msg(
				'moderator-toolkit-reengagement-email-total-views-label', $totalViewsParam, $username
			)->text(),
			'intro' => $this->msg(
				'moderator-toolkit-reengagement-email-intro', $username, Message::numParam( self::EDITOR_COUNT )
			)->text(),

			'ctaHeading' => $this->msg( 'moderator-toolkit-reengagement-email-cta-heading' )->text(),
			'ctaText' => $this->msg( 'moderator-toolkit-reengagement-email-cta-text', $username )->text(),
			'ctaButton' => $this->msg( 'moderator-toolkit-reengagement-email-cta-button', $username )->text(),
			'homepageUrl' => self::getUrl( $this->getSpecialPageTitle( 'Homepage' ) ),

			'pagesHeading' => $this->msg( 'moderator-toolkit-reengagement-email-pages-heading', $username )->text(),
			'pages' => array_map( $this->getPageData( ... ), $recipient->pages ),

			'preferencesUrl' => $preferencesUrl,
			'footerText' => $this->msg(
				'moderator-toolkit-reengagement-email-footer', $preferencesLinkText, $username
			)->text(),
			'footerHtml' => $this->msg(
				'moderator-toolkit-reengagement-email-footer', Message::rawParam( $preferencesLink ), $username
			)->escaped(),
		];
	}

	/**
	 * Gets the plain-text body: the same content as the HTML, with the links written out.
	 *
	 * The subject is the first line, because it is the same sentence as the total views and their label.
	 *
	 * @param array<string,mixed> $data
	 */
	private static function getText( array $data ): string {
		$lines = [
			$data['subject'],
			'',
			$data['intro'],
			'',
			$data['ctaHeading'],
			$data['ctaText'],
			"{$data['ctaButton']}: {$data['homepageUrl']}",
			'',
			$data['pagesHeading'],
		];

		foreach ( $data['pages'] as $page ) {
			$lines[] = '';
			$lines[] = "{$page['name']}: {$page['url']}";
			$lines[] = $page['views'];

			if ( $page['newEditsLine'] !== null ) {
				$lines[] = "{$page['newEditsLine']}: {$page['diffUrl']}";
			}
		}

		// RFC 3676 signature separator, with its trailing space
		$lines[] = '';
		$lines[] = '-- ';
		$lines[] = "{$data['footerText']} {$data['preferencesUrl']}";

		return implode( "\n", $lines );
	}

	/**
	 * Gets the text and links of one page, for both bodies.
	 *
	 * The "new edits" line is null when nobody edited the page after the user, because the design leaves it out.
	 *
	 * @return array<string,string|bool|null>
	 * @throws InvalidArgumentException If the page name is not a valid title
	 */
	private function getPageData( EditedPage $page ): array {
		$title = $this->titleFactory->newFromText( $page->name );

		if ( $title === null ) {
			throw new InvalidArgumentException( "\"{$page->name}\" is not a valid page name" );
		}

		$diffUrl = null;
		$newEditsLine = null;
		$newEditsLineHtml = null;

		if ( $page->newEdits > 0 ) {
			// The visual diff of the latest edit, which is easier for newcomers (T422003).
			$diffUrl = self::getUrl( $title, [ 'diff' => $page->latestRevisionId, 'diffmode' => 'visual' ] );

			$linkText = $this->msg(
				'moderator-toolkit-reengagement-email-page-new-edits', Message::numParam( $page->newEdits )
			)->text();
			$link = Html::element( 'a', [ 'href' => $diffUrl, 'style' => self::LINK_STYLE ], $linkText );

			$newEditors = Message::numParam( $page->newEditors );
			$newEditsLine = $this->msg(
				'moderator-toolkit-reengagement-email-page-new-edits-by', $linkText, $newEditors
			)->text();
			$newEditsLineHtml = $this->msg(
				'moderator-toolkit-reengagement-email-page-new-edits-by', Message::rawParam( $link ), $newEditors
			)->escaped();
		}

		return [
			'name' => $title->getPrefixedText(),
			'url' => self::getUrl( $title ),
			'thumbnailUrl' => $page->thumbnailUrl,
			// A section over a string makes the string the context, so the template could not read the page URL.
			'hasThumbnail' => $page->thumbnailUrl !== null,
			'views' => $this->msg(
				'moderator-toolkit-reengagement-email-page-views', Message::numParam( $page->views )
			)->text(),
			'diffUrl' => $diffUrl,
			'newEditsLine' => $newEditsLine,
			'newEditsLineHtml' => $newEditsLineHtml,
		];
	}

	/**
	 * Gets the title of a special page by its local name, as MediaWiki links to special pages.
	 */
	private function getSpecialPageTitle( string $canonicalName, string $fragment = '' ): Title {
		$localName = $this->specialPageFactory->getLocalNameFor( $canonicalName );

		return $this->titleFactory->makeTitle( NS_SPECIAL, $localName, $fragment );
	}

	/**
	 * Gets a message in the language of the email, which also formats the numbers.
	 */
	private function msg( string $key, mixed ...$params ): Message {
		return $this->messageLocalizer->msg( $key, ...$params )->inLanguage( $this->language );
	}

	/**
	 * Gets the canonical URL of a title, with the tracking parameter.
	 *
	 * @param Title $title
	 * @param array<string,string|int> $query
	 */
	private static function getUrl( Title $title, array $query = [] ): string {
		return $title->getCanonicalURL( $query + self::TRACKING_QUERY );
	}
}
