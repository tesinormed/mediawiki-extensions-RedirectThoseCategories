<?php

namespace MediaWiki\Extension\RedirectThoseCategories;

use Exception;
use MediaWiki\Hook\ParserPreSaveTransformCompleteHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Permissions\RestrictionStore;
use MediaWiki\Title\Title;

class Hooks implements ParserPreSaveTransformCompleteHook {
	private PageLookup $pageLookup;
	private RedirectLookup $redirectLookup;
	private RestrictionStore $restrictionStore;

	public function __construct(
		PageLookup $pageLookup,
		RedirectLookup $redirectLookup,
		RestrictionStore $restrictionStore,
	) {
		$this->pageLookup = $pageLookup;
		$this->redirectLookup = $redirectLookup;
		$this->restrictionStore = $restrictionStore;
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserPreSaveTransformCompleteHook
	 */
	public function onParserPreSaveTransformComplete( $parser, &$text ): void {
		// regex to match [[Category:A category|text]]
		$matchCount = preg_match_all(
			'/\[\[ *([Cc]ategory: *.+?)(?: *| *\| *(.*?) *)]]/m',
			$text, $matches,
			flags: PREG_SET_ORDER
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
			if ( $categoryPage === null || !$this->restrictionStore->isProtected( $categoryPage, 'edit' ) ) {
				continue;
			}

			$redirectTarget = $this->redirectLookup->getRedirectTarget( $categoryPage );
			// category page must redirect to another category page
			if ( $redirectTarget === null || $redirectTarget->getNamespace() !== NS_CATEGORY ) {
				continue;
			}
			if ( $this->getRedirectTargetForLink( $redirectTarget ) !== null ) {
				wfLogWarning( __METHOD__ . ": category {$categoryPage->getDBkey()} is a double redirect" );
				continue;
			}

			// replace original link with redirected link
			$replacementText = '[[' . Title::newFromLinkTarget( $redirectTarget )->getPrefixedText();
			if ( isset( $match[2] ) ) {
				$replacementText .= '|' . $match[2];
			}
			$replacementText .= ']]';
			$text = str_replace( $match[0], $replacementText, $text );
		}
	}

	private function getRedirectTargetForLink( LinkTarget $linkTarget ): ?LinkTarget {
		try {
			return $this->redirectLookup->getRedirectTarget( $this->pageLookup->getPageForLink( $linkTarget ) );
		} catch ( Exception ) {
			return null;
		}
	}
}
