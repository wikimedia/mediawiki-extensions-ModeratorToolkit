<?php

declare( strict_types = 1 );

namespace MediaWiki\Extension\ModeratorToolkit\Maintenance;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\EditedPage;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\EmailComposer;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\ParticipantStore;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\Recipient;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\RecipientChecker;
use MediaWiki\Extension\ModeratorToolkit\ReengagementEmail\SkipReason;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Mail\MailAddress;
use MediaWiki\Mail\UserMailer;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use RuntimeException;
use Throwable;
use Wikimedia\Message\MessageSpecifier;
use Wikimedia\Timestamp\ConvertibleTimestamp;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * Sends the re-engagement email to the users in a CSV file from the T444314 notebook (T422003).
 *
 * Without --send, it only checks. Each user joins the experiment once, so a second run of a file sends no second
 * email. The output rows go to stdout, are tab-separated, and start with a UTC time. Notices and the summary go to
 * stderr, so that stdout has only the rows.
 */
class SendReengagementEmails extends Maintenance {

	/** Seconds to wait after each sent email, as in WikimediaMaintenance's sendBulkEmails.php */
	private const int DELAY = 5;

	/** The columns of each page in the CSV file, after "Page 1 " to "Page 3 " */
	private const array PAGE_FIELDS = [
		'name', 'ID', 'edits', 'editors', 'pageviews', 'latest revision ID', 'thumbnail URL',
	];

	/** Failed emails in a row that probably mean a problem with every email, such as a mail server that is down */
	private const int MAX_FAILURES_IN_A_ROW = 5;

	// Values of the "group" and "result" columns of the output

	private const string TREATMENT = 'treatment';

	private const string CONTROL = 'control';

	/** The mail server accepted the email, which does not mean that it was delivered */
	private const string QUEUED = 'queued';

	private const string WOULD_QUEUE = 'would queue';

	private const string FAILED = 'failed';

	private const string ELIGIBLE = 'eligible';

	private const string SKIP = 'skip';

	/** True without --send: makes each treatment email, but sends none and adds nobody to the experiment */
	private bool $dryRun;

	private int $recipientCount;

	/** Checks the recipients at the time of sending, not when the notebook ran */
	private RecipientChecker $checker;

	private EmailComposer $composer;

	private MailAddress $from;

	private MailAddress $replyTo;

	private ParticipantStore $participantStore;

	/** The index of the last processed recipient, for the summary */
	private ?int $lastIndex = null;

	/** @var array<string,int> The number of recipients with each result, for the summary */
	private array $counts = [];

	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'ModeratorToolkit' );
		// RecipientChecker and sendEmail() read their preferences.
		$this->requireExtension( 'Echo' );
		$this->requireExtension( 'GrowthExperiments' );
		$this->addDescription( 'Send the re-engagement email to the users in a CSV file from the T444314 notebook' );

		$this->addArg( 'file', 'The CSV file from the T444314 notebook' );

		$this->addOption( 'send',
			'Send the emails. Without it, the script checks the file and the users, '
				. 'makes each email, and shows who gets one, but sends no email.' );
	}

	/**
	 * Checks the whole file, then processes every recipient and shows a summary.
	 */
	public function execute(): void {
		$this->checkConfig();

		// output() writes nothing with --quiet, but the rows are the record of who was emailed (T422003#12573521).
		if ( $this->isQuiet() ) {
			$this->fatalError( '--quiet would hide the output rows' );
		}

		$this->dryRun = !$this->hasOption( 'send' );

		if ( $this->dryRun ) {
			$this->error( 'Checking only. Use --send to send the emails.' );
		}

		// Read the whole file before any email goes out.
		$recipients = $this->readRecipients( (string)$this->getArg( 'file' ) );
		$this->recipientCount = count( $recipients );

		$this->participantStore = new ParticipantStore( $this->getServiceContainer()->getMainObjectStash() );
		$this->createServices();
		$failureCount = $this->processFile( $recipients );
		$this->outputSummary();

		if ( $failureCount > 0 ) {
			$this->error( "Failed emails: $failureCount. The mail server can accept an email that is reported as "
				. 'failed (T383047), so these users stay in the experiment and get no second try.' );
		}
	}

	/**
	 * Stops the script if the wiki cannot send the email.
	 */
	private function checkConfig(): void {
		$config = $this->getConfig();

		if ( $config->get( MainConfigNames::EnableEmail ) !== true ) {
			$this->fatalError( 'Email is disabled on this wiki ($wgEnableEmail)' );
		}
	}

	/**
	 * Stops the script if the file cannot be read, or if its header or the field count of a row is not as expected.
	 *
	 * The notebook writes and checks the data, so the script checks only the format (T422003#12571561).
	 *
	 * @return list<Recipient>
	 */
	private function readRecipients( string $path ): array {
		// fopen() would also show a PHP warning for a file that it cannot read.
		$handle = is_file( $path ) && is_readable( $path ) ? fopen( $path, 'r' ) : false;

		if ( $handle === false ) {
			$this->fatalError( "Cannot read $path" );
		}

		$header = [ 'Username', 'User ID', 'Total pageviews' ];

		for ( $page = 1; $page <= 3; $page++ ) {
			foreach ( self::PAGE_FIELDS as $field ) {
				$header[] = "Page $page $field";
			}
		}

		$header[] = 'Send email';

		// No escape character, because a page name can end with a backslash (T422003#12571561).
		if ( fgetcsv( $handle, escape: '' ) !== $header ) {
			$this->fatalError( 'The header is not the header of the T444314 notebook' );
		}

		$recipients = [];
		$rowNumber = 1;
		$row = fgetcsv( $handle, escape: '' );

		while ( $row !== false ) {
			$rowNumber++;

			// This also finds a missing closing quote, which makes fgetcsv() read the rest of the file as one field.
			if ( count( $row ) !== count( $header ) ) {
				$this->fatalError( "Row $rowNumber has " . count( $row ) . ' fields instead of '
					. count( $header ) );
			}

			'@phan-var list<string> $row';
			$recipients[] = $this->newRecipient( array_combine( $header, $row ), $rowNumber );
			$row = fgetcsv( $handle, escape: '' );
		}

		fclose( $handle );

		return $recipients;
	}

	/**
	 * @param array<string,string> $fields The fields of one row, by column name
	 */
	private function newRecipient( array $fields, int $rowNumber ): Recipient {
		$pages = [];

		$titleFactory = $this->getServiceContainer()->getTitleFactory();

		for ( $page = 1; $page <= 3; $page++ ) {
			$name = $fields["Page $page name"];

			// A row with fewer pages has empty columns. "0" is a valid page name.
			if ( $name !== '' ) {
				// EmailComposer would throw during the run, after earlier rows got their email.
				if ( $titleFactory->newFromText( $name ) === null ) {
					$this->fatalError( "Row $rowNumber: \"$name\" is not a valid page name" );
				}

				$thumbnailUrl = $fields["Page $page thumbnail URL"];
				$pages[] = new EditedPage(
					$name,
					(int)$fields["Page $page edits"],
					(int)$fields["Page $page editors"],
					(int)$fields["Page $page pageviews"],
					(int)$fields["Page $page latest revision ID"],
					$thumbnailUrl === '' ? null : $thumbnailUrl,
				);
			}
		}

		// Anything else would put the user in the control group without a warning.
		if ( $fields['Send email'] !== '1' && $fields['Send email'] !== '0' ) {
			$this->fatalError( "Row $rowNumber: Send email is not 1 or 0: \"{$fields['Send email']}\"" );
		}

		return new Recipient( $fields['Username'], (int)$fields['User ID'], (int)$fields['Total pageviews'], $pages,
			$fields['Send email'] === '1' );
	}

	/**
	 * Makes the objects that check the recipients and make the emails, in the content language, because the email is
	 * in English only (T422003). The From and Reply-To addresses are the same as in Echo emails.
	 */
	private function createServices(): void {
		$config = $this->getConfig();
		$services = $this->getServiceContainer();
		$language = $services->getContentLanguage();

		$this->checker = new RecipientChecker(
			$services->getUserIdentityLookup(),
			$services->getUserFactory(),
			$services->getUserOptionsLookup(),
		);
		$this->composer = new EmailComposer(
			RequestContext::getMain(),
			$services->getTitleFactory(),
			$services->getSpecialPageFactory(),
			$language,
			new TemplateParser( EmailComposer::TEMPLATE_DIR ),
			(string)$config->get( MainConfigNames::Sitename ),
		);
		$this->from = new MailAddress(
			(string)$config->get( MainConfigNames::PasswordSender ),
			RequestContext::getMain()->msg( 'emailsender' )->inLanguage( $language )->text()
		);
		$this->replyTo = new MailAddress( (string)$config->get( MainConfigNames::NoReplyAddress ) );
	}

	/**
	 * @param list<Recipient> $recipients
	 * @return int The number of failed emails
	 */
	private function processFile( array $recipients ): int {
		// The analysis joins this to the CSV file by user_id (T422003#12573521). recipient_number is the row number
		// in the CSV file minus 1, for the header.
		$this->output( "time\trecipient_number\tuser_id\tgroup\tresult\treason\n" );

		$failureCount = 0;
		$failuresInARow = 0;

		for ( $index = 0; $index < $this->recipientCount; $index++ ) {
			$recipient = $recipients[$index];
			[ $result, $reason ] = $this->processRecipient( $recipient );
			$this->recordResult( $index, $recipient, $result, $reason );

			if ( $this->dryRun ) {
				continue;
			}

			if ( $result === self::QUEUED ) {
				$failuresInARow = 0;

				if ( $index < $this->recipientCount - 1 ) {
					sleep( self::DELAY );
				}
			} elseif ( $result === self::FAILED ) {
				$failureCount++;
				$failuresInARow++;

				if ( $failuresInARow >= self::MAX_FAILURES_IN_A_ROW ) {
					$this->stop( "Stopped after $failuresInARow failed emails in a row. Fix the problem, then run "
						. 'the script again. Users that this run added show as already in the experiment.' );
				}
			}
		}

		return $failureCount;
	}

	/**
	 * @return array{0:string,1:string} The "result" and "reason" columns of the TSV row, such as "skip" and "blocked"
	 */
	private function processRecipient( Recipient $recipient ): array {
		// Check the control group too, so that the analysis can remove the same users from both groups.
		$skipReason = $this->checker->getSkipReason( $recipient );

		if ( $skipReason !== null ) {
			return [ self::SKIP, $skipReason->value ];
		}

		// Before the user joins, so that an error here does not keep them in the experiment.
		$email = $recipient->sendEmail ? $this->composer->compose( $recipient ) : null;

		if ( !$this->joinExperiment( $recipient ) ) {
			return [ self::SKIP, SkipReason::AlreadyInExperiment->value ];
		}

		if ( $email === null ) {
			return [ self::ELIGIBLE, '' ];
		}

		if ( $this->dryRun ) {
			return [ self::WOULD_QUEUE, '' ];
		}

		$error = $this->sendEmail( $email, $recipient );

		return $error === null ? [ self::QUEUED, '' ] : [ self::FAILED, $error ];
	}

	/**
	 * Adds an eligible user to the experiment. Each user joins once, in either group (DE1.3.12 product document).
	 *
	 * Without --send, only checks.
	 *
	 * @return bool False if an earlier run already added the user
	 */
	private function joinExperiment( Recipient $recipient ): bool {
		try {
			return $this->dryRun
				? !$this->participantStore->has( $recipient->userId )
				: $this->participantStore->add( $recipient->userId );
		} catch ( RuntimeException $e ) {
			$this->stop( "{$e->getMessage()} at user ID {$recipient->userId}. Run the script again." );
		}
	}

	/**
	 * Sends the email in the format that the user chose for Echo emails, as Echo does. Shows only the keys of error
	 * messages, because their text can contain the email address.
	 *
	 * @param array{subject:string,text:string,html:string} $email
	 * @return string|null The keys of the error messages, or null if the mail server accepted the email
	 */
	private function sendEmail( array $email, Recipient $recipient ): ?string {
		$services = $this->getServiceContainer();
		$user = $services->getUserFactory()->newFromId( $recipient->userId );
		$body = $services->getUserOptionsLookup()->getOption( $user, 'echo-email-format' ) === 'plain-text'
			? $email['text']
			: [ 'text' => $email['text'], 'html' => $email['html'] ];
		$options = [
			'replyTo' => $this->replyTo,
			// Stops vacation and other automatic replies (RFC 3834, T412427).
			'headers' => [ 'Auto-Submitted' => 'auto-generated' ],
		];

		try {
			// One recipient for each call, so that BounceHandler can give each email its own return path.
			$status = UserMailer::send(
				MailAddress::newFromUser( $user ), $this->from, $email['subject'], $body, $options
			);
		} catch ( Throwable $e ) {
			// A hook can throw, and the exception message can contain the email address too.
			return $e::class;
		}

		if ( $status->isOK() ) {
			return null;
		}

		$keys = array_map(
			static fn ( MessageSpecifier $message ): string => $message->getKey(),
			$status->getMessages( 'error' )
		);

		return implode( ', ', $keys );
	}

	/**
	 * Writes the recipient's row, and counts the result for the summary.
	 *
	 * The row has the user ID but no username or email address, because the output goes to the analyst by hand.
	 */
	private function recordResult( int $index, Recipient $recipient, string $result, string $reason ): void {
		$time = ConvertibleTimestamp::now( TimestampFormat::ISO_8601 );
		$number = $index + 1;
		$group = $recipient->sendEmail ? self::TREATMENT : self::CONTROL;
		$this->output( "$time\t$number\t{$recipient->userId}\t$group\t$result\t$reason\n" );

		$summaryKey = $reason === '' ? "$group, $result" : "$group, $result, $reason";
		$this->counts[$summaryKey] = ( $this->counts[$summaryKey] ?? 0 ) + 1;
		$this->lastIndex = $index;
	}

	private function stop( string $message ): never {
		$this->outputSummary();
		$this->fatalError( $message );
	}

	/**
	 * Shows the count of each result, the most common first.
	 */
	private function outputSummary(): void {
		$lastRecipient = $this->lastIndex === null ? 'none' : $this->lastIndex + 1;
		$this->error( "Recipients in the file: {$this->recipientCount}" );
		$this->error( "Last processed recipient: $lastRecipient" );

		$counts = $this->counts;
		arsort( $counts );

		foreach ( $counts as $result => $count ) {
			$this->error( "$result: $count" );
		}
	}
}

// @codeCoverageIgnoreStart
return SendReengagementEmails::class;
// @codeCoverageIgnoreEnd
