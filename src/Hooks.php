<?php

namespace MediaWiki\Extension\RedirectThoseCategories;

use JobQueueGroup;
use MediaWiki\Hook\ParserPreSaveTransformCompleteHook;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Language\Language;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;

class Hooks implements ParserPreSaveTransformCompleteHook, PageSaveCompleteHook {
	private JobQueueGroup $jobQueueGroup;
	private PageLookup $pageLookup;
	private RedirectLookup $redirectLookup;
	private RestrictionStore $restrictionStore;

	public function __construct(
		JobQueueGroupFactory $jobQueueGroupFactory,
		PageLookup $pageLookup,
		RedirectLookup $redirectLookup,
		RestrictionStore $restrictionStore,
	) {
		$this->jobQueueGroup = $jobQueueGroupFactory->makeJobQueueGroup();
		$this->pageLookup = $pageLookup;
		$this->redirectLookup = $redirectLookup;
		$this->restrictionStore = $restrictionStore;
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserPreSaveTransformCompleteHook
	 */
	public function onParserPreSaveTransformComplete( $parser, &$text ): void {
		// regex to match [[Category:A category]] (with non-English language support)
		$language = $parser->getContentLanguage();
		$matchCount = preg_match_all(
			'/\[\[ *('
			. self::makeRegexCaseInsensitiveFirst( $language->getNsText( NS_CATEGORY ), $language )
			. ': *.+?)(?: *| *\| *(.+?))]]/m',
			$text,
			$matches,
			PREG_SET_ORDER
		);
		if ( $matchCount === false || $matchCount === 0 ) {
			// error or no matches
			return;
		}
		foreach ( $matches as $match ) {
			// $match[0] is the category link: [[Category:A category]]
			// $match[1] is the category title text: Category:A category

			$categoryPage = $this->pageLookup->getPageByText( $match[1] );
			// category page must be valid and must be protected
			if ( $categoryPage === null || !$this->restrictionStore->isProtected( $categoryPage ) ) {
				continue;
			}

			$categoryPageRedirectTarget = $this->redirectLookup->getRedirectTarget( $categoryPage );
			// category page must redirect to another category page
			if ( $categoryPageRedirectTarget === null || $categoryPageRedirectTarget->getNamespace() !== NS_CATEGORY ) {
				continue;
			}

			// replace original link with redirected link
			$text = str_replace( $match[0], '[[Category:' . $categoryPageRedirectTarget->getText() . ']]', $text );
		}
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageSaveComplete
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		// page must be a category and must be protected
		if ( $wikiPage->getNamespace() !== NS_CATEGORY || !$this->restrictionStore->isProtected( $wikiPage ) ) {
			return;
		}

		// category page must redirect to another category page
		$redirectTarget = $this->redirectLookup->getRedirectTarget( $wikiPage );
		if ( $redirectTarget === null || $redirectTarget->getNamespace() !== NS_CATEGORY ) {
			return;
		}

		// push to job queue (expensive operation)
		$this->jobQueueGroup->lazyPush( new RecategorizePagesJob( $wikiPage->getTitle() ) );
	}

	private static function makeRegexCaseInsensitiveFirst( string $text, Language $language ): string {
		return "(?:$text|{$language->lcfirst( $text )})";
	}
}
