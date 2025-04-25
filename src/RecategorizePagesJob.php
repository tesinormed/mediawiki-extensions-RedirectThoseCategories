<?php

namespace MediaWiki\Extension\RedirectThoseCategories;

use Exception;
use GenericParameterJob;
use Job;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\TextContent;
use MediaWiki\Language\Language;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class RecategorizePagesJob extends Job implements GenericParameterJob {
	/** @inheritDoc */
	public function __construct( array $params ) {
		parent::__construct( 'recategorizePages', $params );
		$this->removeDuplicates = true;
		$this->title = Title::newFromDBkey( $this->params['categoryDBkey'] );
	}

	public function run(): bool {
		$services = MediaWikiServices::getInstance();
		$dbProvider = $services->getConnectionProvider();
		$wikiPageFactory = $services->getWikiPageFactory();

		// regex to match [[Category:A category]] (with non-English language support)
		$language = $this->title->getPageLanguage();
		$categoryRegex = '/\[\[ *'
			. self::makeRegexCaseInsensitiveFirst( $language->getNsText( NS_CATEGORY ), $language )
			. ': *'
			. str_replace( '_', '[_ ]', self::makeRegexCaseInsensitiveFirst( $this->title->getDBkey(), $language ) )
			. '(?: *| *(\|) *(.*?) *)]]/m';

		// get all categorized pages
		$dbr = $dbProvider->getReplicaDatabase();
		$result = $dbr->newSelectQueryBuilder()
			->from( 'categorylinks' )
			->select( 'cl_from' )
			->where( [ 'cl_to' => $this->title->getDBkey() ] )
			->caller( __METHOD__ )->fetchFieldValues();

		foreach ( $result as $pageId ) {
			$categorizedWikiPage = $wikiPageFactory->newFromID( $pageId );
			$categorizedWikiPageContent = $categorizedWikiPage->getContent();
			// categorized page must be text
			if ( !( $categorizedWikiPageContent instanceof TextContent ) ) {
				continue;
			}

			// replace old link with redirected link
			$newContent = preg_replace(
				$categoryRegex,
				'[[Category:' . $this->title->getText() . '$1$2]]',
				$categorizedWikiPageContent->getText()
			);

			// try to save the new revision
			try {
				$categorizedWikiPage
					->newPageUpdater( User::newSystemUser( wfMessage( 'redirectthosecategories-user' )->plain() ) )
					->setContent(
						SlotRecord::MAIN,
						ContentHandler::makeContent( $newContent, $categorizedWikiPage->getTitle() )
					)
					->saveRevision( CommentStoreComment::newUnsavedComment(
						wfMessage( 'redirectthosecategories-edit-summary' )->plain()
					) );
			} catch ( Exception ) {
				return false;
			}
		}
		return true;
	}

	private static function makeRegexCaseInsensitiveFirst( string $text, Language $language ): string {
		return "(?:$text|{$language->lcfirst( $text )})";
	}
}
