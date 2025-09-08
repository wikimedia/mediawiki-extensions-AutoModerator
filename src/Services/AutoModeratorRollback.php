<?php

namespace AutoModerator\Services;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Language\RawMessage;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\ActorMigration;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserIdentity;
use StatusValue;
use Wikimedia\Message\MessageValue;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IDBAccessObject;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * This code is mostly copied from RollbackPage (backend logic for performing a page rollback action).
 * This is duplicated due to AutoModerator needing custom functionality to override the minor edit flag
 * which is not currently possible in the existing RollbackPage implementation.
 */
class AutoModeratorRollback {
	public const CONSTRUCTOR_OPTIONS = [
		MainConfigNames::UseRCPatrol,
		MainConfigNames::DisableAnonTalk,
	];

	/** @var string */
	private string $summary = '';

	/** @var bool */
	private bool $bot = false;

	/** @var string[] */
	private array $tags = [];

	private ServiceOptions $options;
	private IConnectionProvider $dbProvider;
	private RevisionStore $revisionStore;
	private TitleFormatter $titleFormatter;
	private HookRunner $hookRunner;
	private WikiPageFactory $wikiPageFactory;
	private ActorMigration $actorMigration;
	private ActorNormalization $actorNormalization;
	private PageIdentity $page;
	private UserIdentity $performer;
	/** @var UserIdentity who made the edits we are rolling back */
	private UserIdentity $byUser;

	/** @var Config */
	private Config $wikiConfig;

	/** @var Config */
	private Config $config;

	/**
	 * @internal Create via the RollbackPageFactory service.
	 */
	public function __construct(
		ServiceOptions $options,
		IConnectionProvider $dbProvider,
		RevisionStore $revisionStore,
		TitleFormatter $titleFormatter,
		HookContainer $hookContainer,
		WikiPageFactory $wikiPageFactory,
		ActorMigration $actorMigration,
		ActorNormalization $actorNormalization,
		PageIdentity $page,
		UserIdentity $performer,
		UserIdentity $byUser,
		Config $config,
		Config $wikiConfig
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->options = $options;
		$this->dbProvider = $dbProvider;
		$this->revisionStore = $revisionStore;
		$this->titleFormatter = $titleFormatter;
		$this->hookRunner = new HookRunner( $hookContainer );
		$this->wikiPageFactory = $wikiPageFactory;
		$this->actorMigration = $actorMigration;
		$this->actorNormalization = $actorNormalization;
		$this->page = $page;
		$this->performer = $performer;
		$this->byUser = $byUser;
		$this->config = $config;
		$this->wikiConfig = $wikiConfig;
	}

	/**
	 * Set custom edit summary.
	 *
	 * @param string|null $summary
	 * @return $this
	 */
	public function setSummary( ?string $summary ): self {
		$this->summary = $summary ?? '';
		return $this;
	}

	/**
	 * @return StatusValue On success, wrapping the array with the following keys:
	 *   'summary' - rollback edit summary
	 *   'current-revision-record' - revision record that was current before rollback
	 *   'target-revision-record' - revision record we are rolling back to
	 *   'newid' => the id of the rollback revision
	 *   'tags' => the tags applied to the rollback
	 */
	public function rollback(): StatusValue {
		// Begin revision creation cycle by creating a PageUpdater.
		// If the page is changed concurrently after grabParentRevision(), the rollback will fail.
		// TODO: move PageUpdater to PageStore or PageUpdaterFactory or something?
		$updater = $this->wikiPageFactory->newFromTitle( $this->page )->newPageUpdater( $this->performer );
		$currentRevision = $updater->grabParentRevision();

		if ( !$currentRevision ) {
			// Something wrong... no page?
			return StatusValue::newFatal( 'notanarticle' );
		}

		$currentEditor = $currentRevision->getUser( RevisionRecord::RAW );
		$currentEditorForPublic = $currentRevision->getUser();
		// User name given should match up with the top revision.

		if ( !$this->byUser->equals( $currentEditor ) ) {
			$result = StatusValue::newGood( [
				'current-revision-record' => $currentRevision
			] );
			$result->fatal(
				'alreadyrolled',
				htmlspecialchars( $this->titleFormatter->getPrefixedText( $this->page ) ),
				htmlspecialchars( $this->byUser->getName() ),
				htmlspecialchars( $currentEditorForPublic ? $currentEditorForPublic->getName() : '' )
			);
			return $result;
		}

		$dbw = $this->dbProvider->getPrimaryDatabase();
		// Get the last edit not by this person...
		// Note: these may not be public values
		$actorWhere = $this->actorMigration->getWhere( $dbw, 'rev_user', $currentEditor );
		$queryBuilder = $this->revisionStore->newSelectQueryBuilder( $dbw )
			->where( [ 'rev_page' => $currentRevision->getPageId(), 'NOT(' . $actorWhere['conds'] . ')' ] )
			->useIndex( [ 'revision' => 'rev_page_timestamp' ] )
			->orderBy( [ 'rev_timestamp', 'rev_id' ], SelectQueryBuilder::SORT_DESC );
		$targetRevisionRow = $queryBuilder->caller( __METHOD__ )->fetchRow();
		if ( $targetRevisionRow === false ) {
			// No one else ever edited this page
			return StatusValue::newFatal( 'cantrollback' );
		} elseif ( $targetRevisionRow->rev_deleted & RevisionRecord::DELETED_TEXT
			|| $targetRevisionRow->rev_deleted & RevisionRecord::DELETED_USER
		) {
			// Only admins can see this text
			return StatusValue::newFatal( 'notvisiblerev' );
		}

		// Generate the edit summary if necessary
		$targetRevision = $this->revisionStore
			->getRevisionById( $targetRevisionRow->rev_id, IDBAccessObject::READ_LATEST );

		// Save
		$flags = EDIT_UPDATE | EDIT_INTERNAL;

		if ( $this->wikiConfig->get( 'AutoModeratorUseEditFlagMinor' ) ) {
			$flags |= EDIT_MINOR;
		}

		if ( $this->wikiConfig->get( 'AutoModeratorEnableBotFlag' ) ) {
			$flags |= EDIT_FORCE_BOT;
		}

		// TODO: MCR: also log model changes in other slots, in case that becomes possible!
		$currentContent = $currentRevision->getContent( SlotRecord::MAIN );
		$targetContent = $targetRevision->getContent( SlotRecord::MAIN );
		$changingContentModel = $targetContent->getModel() !== $currentContent->getModel();

		// Build rollback revision:
		// Restore old content
		// TODO: MCR: test this once we can store multiple slots
		foreach ( $targetRevision->getSlots()->getSlots() as $slot ) {
			$updater->inheritSlot( $slot );
		}

		// Remove extra slots
		// TODO: MCR: test this once we can store multiple slots
		foreach ( $currentRevision->getSlotRoles() as $role ) {
			if ( !$targetRevision->hasSlot( $role ) ) {
				$updater->removeSlot( $role );
			}
		}

		$updater->markAsRevert(
			EditResult::REVERT_ROLLBACK,
			$currentRevision->getId(),
			$targetRevision->getId()
		);

		// TODO: this logic should not be in the storage layer, it's here for compatibility
		// with 1.31 behavior. Applying the 'autopatrol' right should be done in the same
		// place the 'bot' right is handled, which is currently in EditPage::attemptSave.
		$options = new ServiceOptions(
			self::CONSTRUCTOR_OPTIONS, $this->config );
		if ( $this->options->get( MainConfigNames::UseRCPatrol )
		) {
			$updater->setRcPatrolStatus( RecentChange::PRC_AUTOPATROLLED );
		}

		$summary = $this->getSummary( $currentRevision, $targetRevision );

		// Actually store the rollback
		$rev = $updater->addTags( $this->tags )->saveRevision(
			CommentStoreComment::newUnsavedComment( $summary ),
			$flags
		);

		// This is done even on edit failure to have patrolling in that case (T64157).
		$this->updateRecentChange( $dbw, $currentRevision, $targetRevision );

		if ( !$updater->wasSuccessful() ) {
			return $updater->getStatus();
		}

		// Report if the edit was not created because it did not change the content.
		if ( !$updater->wasRevisionCreated() ) {
			$result = StatusValue::newGood( [
				'current-revision-record' => $currentRevision
			] );
			$result->fatal(
				'alreadyrolled',
				htmlspecialchars( $this->titleFormatter->getPrefixedText( $this->page ) ),
				htmlspecialchars( $this->byUser->getName() ),
				htmlspecialchars( $currentEditorForPublic ? $currentEditorForPublic->getName() : '' )
			);
			return $result;
		}

		if ( $changingContentModel ) {
			// If the content model changed during the rollback,
			// make sure it gets logged to Special:Log/contentmodel
			$log = new ManualLogEntry( 'contentmodel', 'change' );
			$log->setPerformer( $this->performer );
			$log->setTarget( new TitleValue( $this->page->getNamespace(), $this->page->getDBkey() ) );
			$log->setComment( $summary );
			$log->setParameters( [
				'4::oldmodel' => $currentContent->getModel(),
				'5::newmodel' => $targetContent->getModel(),
			] );

			$logId = $log->insert( $dbw );
			$log->publish( $logId );
		}

		$wikiPage = $this->wikiPageFactory->newFromTitle( $this->page );

		$this->hookRunner->onRollbackComplete(
			$wikiPage,
			$this->performer,
			$targetRevision,
			$currentRevision
		);

		return StatusValue::newGood( [
			'summary' => $summary,
			'current-revision-record' => $currentRevision,
			'target-revision-record' => $targetRevision,
			'newid' => $rev->getId(),
			'tags' => array_merge( $this->tags, $updater->getEditResult()->getRevertTags() )
		] );
	}

	/**
	 * Set patrolling and bot flag on the edits which get rolled back.
	 *
	 * @param IDatabase $dbw
	 * @param RevisionRecord $current
	 * @param RevisionRecord $target
	 */
	private function updateRecentChange(
		IDatabase $dbw,
		RevisionRecord $current,
		RevisionRecord $target
	) {
		$useRCPatrol = $this->options->get( MainConfigNames::UseRCPatrol );
		if ( !$this->bot && !$useRCPatrol ) {
			return;
		}

		$actorId = $this->actorNormalization->findActorId( $current->getUser( RevisionRecord::RAW ), $dbw );
		$timestamp = $dbw->timestamp( $target->getTimestamp() );
		$rows = $dbw->newSelectQueryBuilder()
			->select( [ 'rc_id', 'rc_patrolled' ] )
			->from( 'recentchanges' )
			->where( [ 'rc_cur_id' => $current->getPageId(), 'rc_actor' => $actorId, ] )
			->andWhere( $dbw->buildComparison( '>', [
				'rc_timestamp' => $timestamp,
				'rc_this_oldid' => $target->getId(),
			] ) )
			->caller( __METHOD__ )->fetchResultSet();

		$all = [];
		$patrolled = [];
		$unpatrolled = [];
		foreach ( $rows as $row ) {
			$all[] = (int)$row->rc_id;
			if ( $row->rc_patrolled ) {
				$patrolled[] = (int)$row->rc_id;
			} else {
				$unpatrolled[] = (int)$row->rc_id;
			}
		}

		if ( $useRCPatrol && $this->bot ) {
			// Mark all reverted edits as if they were made by a bot
			// Also mark only unpatrolled reverted edits as patrolled
			if ( $unpatrolled ) {
				$dbw->newUpdateQueryBuilder()
					->update( 'recentchanges' )
					->set( [ 'rc_bot' => 1, 'rc_patrolled' => RecentChange::PRC_AUTOPATROLLED ] )
					->where( [ 'rc_id' => $unpatrolled ] )
					->caller( __METHOD__ )->execute();
			}
			if ( $patrolled ) {
				$dbw->newUpdateQueryBuilder()
					->update( 'recentchanges' )
					->set( [ 'rc_bot' => 1 ] )
					->where( [ 'rc_id' => $patrolled ] )
					->caller( __METHOD__ )->execute();
			}
		} elseif ( $useRCPatrol ) {
			// Mark only unpatrolled reverted edits as patrolled
			if ( $unpatrolled ) {
				$dbw->newUpdateQueryBuilder()
					->update( 'recentchanges' )
					->set( [ 'rc_patrolled' => RecentChange::PRC_AUTOPATROLLED ] )
					->where( [ 'rc_id' => $unpatrolled ] )
					->caller( __METHOD__ )->execute();
			}
		} else {
			// Edit is from a bot
			if ( $all ) {
				$dbw->newUpdateQueryBuilder()
					->update( 'recentchanges' )
					->set( [ 'rc_bot' => 1 ] )
					->where( [ 'rc_id' => $all ] )
					->caller( __METHOD__ )->execute();
			}
		}
	}

	/**
	 * Generate and format summary for the rollback.
	 *
	 * @param RevisionRecord $current
	 * @param RevisionRecord $target
	 * @return string
	 */
	private function getSummary( RevisionRecord $current, RevisionRecord $target ): string {
		$revisionsBetween = $this->revisionStore->countRevisionsBetween(
			$current->getPageId(),
			$target,
			$current,
			1000,
			RevisionStore::INCLUDE_NEW
		);
		$currentEditorForPublic = $current->getUser( RevisionRecord::FOR_PUBLIC );
		if ( $this->summary === '' ) {
			if ( !$currentEditorForPublic ) {
				// no public user name
				$summary = MessageValue::new( 'automoderator-wiki-revertpage-nouser' );
			} elseif ( $this->options->get( MainConfigNames::DisableAnonTalk ) &&
				!$currentEditorForPublic->isRegistered() ) {
				$summary = MessageValue::new( 'automoderator-wiki-revertpage-anon' );
			} else {
				$summary = MessageValue::new( 'automoderator-wiki-revertpage' );
			}
		} else {
			$summary = $this->summary;
		}

		$targetEditorForPublic = $target->getUser( RevisionRecord::FOR_PUBLIC );
		// Allow the custom summary to use the same args as the default message
		$args = [
			$targetEditorForPublic ? $targetEditorForPublic->getName() : null,
			$currentEditorForPublic ? $currentEditorForPublic->getName() : null,
			$target->getId(),
			Message::dateTimeParam( $target->getTimestamp() ),
			$current->getId(),
			Message::dateTimeParam( $current->getTimestamp() ),
			$revisionsBetween,
		];
		if ( $summary instanceof MessageValue ) {
			$summary = Message::newFromSpecifier( $summary )->params( $args )->inContentLanguage()->text();
		} else {
			$summary = ( new RawMessage( $summary, $args ) )->inContentLanguage()->plain();
		}

		// Trim spaces on user supplied text
		return trim( $summary );
	}
}
