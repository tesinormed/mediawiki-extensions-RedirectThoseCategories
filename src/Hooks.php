<?php

namespace MediaWiki\Extension\RedirectThoseCategories;

use MediaWiki\Hook\ParserPreSaveTransformCompleteHook;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\RedirectLookup;

class Hooks implements ParserPreSaveTransformCompleteHook {
	private const CATEGORY_REGEX = '/\[\[ *([Cc]ategory: *.+?)(?: *| *\| *(.+?))]]/m';

	private PageLookup $pageLookup;
	private RedirectLookup $redirectLookup;

	public function __construct( PageLookup $pageLookup, RedirectLookup $redirectLookup ) {
		$this->pageLookup = $pageLookup;
		$this->redirectLookup = $redirectLookup;
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserPreSaveTransformCompleteHook
	 */
	public function onParserPreSaveTransformComplete( $parser, &$text ): void {
		$matchCount = preg_match_all( self::CATEGORY_REGEX, $text, $matches, PREG_SET_ORDER );
		if ( $matchCount === false || $matchCount === 0 ) {
			return;
		}
		foreach ( $matches as $match ) {
			// $match[0] is the category link: [[Category:A category]]
			// $match[1] is the category title text: Category:A category

			$categoryPage = $this->pageLookup->getPageByText( $match[1] );
			if ( $categoryPage === null ) {
				continue;
			}

			$categoryPageRedirectTarget = $this->redirectLookup->getRedirectTarget( $categoryPage );
			if ( $categoryPageRedirectTarget === null || $categoryPageRedirectTarget->getNamespace() !== NS_CATEGORY ) {
				continue;
			}

			$text = str_replace( $match[0], '[[Category:' . $categoryPageRedirectTarget->getText() . ']]', $text );
		}
	}
}
