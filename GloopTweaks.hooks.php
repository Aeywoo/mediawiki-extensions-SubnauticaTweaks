<?php

use MediaWiki\MediaWikiServices;

/**
 * Hooks for GloopTweaks extension
 *
 * @file
 * @ingroup Extensions
 */
class GloopTweaksHooks {
	/**
	 * Called after this is loaded
	 */
	public static function onRegistration() {
		global $wgAuthManagerAutoConfig;

		// Load our pre-authentication provider to handle our custom anti-spam checks.
		$wgAuthManagerAutoConfig['preauth'][GloopPreAuthenticationProvider::class] = [
			'class' => GloopPreAuthenticationProvider::class,
			'sort' => 5
		];
	}

	/**
	 * When core requests certain messages, change the key to a Weird Gloop version.
	 *
	 * @note Don't make this a closure, it causes the Database Dumps to fail.
	 *   See https://bugs.php.net/bug.php?id=52144
	 *
	 *   mwscript getSlaveServer.php --wiki='dewiki' --group=dump --globals
	 *   print_r( $GLOBALS['wgHooks']['MessageCache::get'] );
	 *
	 * @param String &$lcKey message key to check and possibly convert
	 *
	 * @return bool
	 */
	public static function onMessageCacheGet( &$lcKey ) {
		global $wglEnableMessageOverrides, $wgLanguageCode;

		if ($wglEnableMessageOverrides) {
			static $keys = [
				'privacypage',
				'changecontentmodel-text',
				'emailmessage',
				'mobile-frontend-copyright',
				'contactpage-pagetext',
				'newusermessage-editor',
				'revisionslider-help-dialog-slide1'
			];

			if ( in_array( $lcKey, $keys, true ) ) {
				$transformedKey = "weirdgloop-$lcKey";
			} else {
				return true;
			}

			// MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
			// of the above.  Revisit if non-ASCII keys are used.
			$ucKey = ucfirst( $lcKey );

			$cache = MessageCache::singleton();
			if (
				/*
					* Override order:
					* 1. If the MediaWiki:$ucKey page exists, use the key unprefixed
					* (in all languages) with normal fallback order.  Specific
					* language pages (MediaWiki:$ucKey/xy) are not checked when
					* deciding which key to use, but are still used if applicable
					* after the key is decided.
					*
					* 2. Otherwise, use the prefixed key with normal fallback order
					* (including MediaWiki pages if they exist).
					*/
				$cache->getMsgFromNamespace( $ucKey, $wgLanguageCode ) === false
			) {
				$lcKey = $transformedKey;
			}
		}

		return true;
	}

	// When [[MediaWiki:weirdgloop-contact-filter]] is edited, clear the contact-filter-regexes global cache key.
	public static function onPageContentSaveComplete( &$wikiPage, &$user, $content, $summary, $isMinor, $isWatch, $section, &$flags, $revision, &$status, $baseRevId, $undidRevId ) {
		if ( $wikiPage->getTitle()->getPrefixedDBkey() === 'MediaWiki:Weirdgloop-contact-filter' ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

			$cache->delete(
				$cache->makeGlobalKey(
					'GloopTweaks',
					'contact-filter-regexes'
				)
			);
		}

		return true;
	}

	/**
	 * Override with Weird Gloop's site-specific copyright message.
	 *
	 * @param Title $title
	 * @param string $type
	 * @param string &$msg
	 * @param string &$link
	 *
	 * @return bool
	 */
	public static function onSkinCopyrightFooter( $title, $type, &$msg, &$link ) {
		global $wglEnableMessageOverrides;

		if ($wglEnableMessageOverrides) {
			if ( $type !== 'history' ) {
				$msg = 'weirdgloop-copyright';
			}
		}

		return true;
	}

	/**
	 * Add some links at the bottom of pages
	 *
	 * @param SkinTemplate &$skin
	 * @param QuickTemplate &$template
	 *
	 * @return bool
	 */
	public static function onSkinTemplateOutputPageBeforeExec( &$skin, &$template ) {
		global $wglAddFooterLinks;

		if ($wglAddFooterLinks) {
			GloopTweaksUtils::addFooterLink('tou', 'weirdgloop-tou-url', 'weirdgloop-tou', $skin, $template);
			GloopTweaksUtils::addFooterLink('contact', 'weirdgloop-contact-url', 'weirdgloop-contact', $skin, $template);
		}

		return true;
	}

	/**
	 * Set the message on TorBlock being triggered
	 *
	 * @param string &$msg The message to over-ride
	 *
	 * @return bool
	 */
	public static function onTorBlockBlockedMsg( &$msg ) {
		global $wglEnableMessageOverrides;

		if ($wglEnableMessageOverrides) {
			$msg = 'weirdgloop-torblock-blocked';
		}

		return true;
	}

	/**
	 * Set the message on GlobalBlocking IP block being triggered
	 *
	 * @param string &$msg The message to over-ride
	 *
	 * @return bool
	 */
	public static function onGlobalBlockingBlockedIpMsg( &$msg ) {
		global $wglEnableMessageOverrides;

		if ($wglEnableMessageOverrides) {
			$msg = 'weirdgloop-globalblocking-ipblocked';
		}

		return true;
	}

	/**
	 * Set the message on GlobalBlocking XFF block being triggered
	 *
	 * @param string &$msg The message to over-ride
	 *
	 * @return bool
	 */
	public static function onGlobalBlockingBlockedIpXffMsg( &$msg ) {
		global $wglEnableMessageOverrides;

		if ($wglEnableMessageOverrides) {
			$msg = 'weirdgloop-globalblocking-ipblocked-xff';
		}

		return true;
	}

	/**
	 * Require the creation of MediaWiki:Licenses to enable uploading.
	 *
	 * Do not require it when licenses is in $wgForceUIMsgAsContentMsg,
	 * to prevent checking each subpage of MediaWiki:Licenses.
	 *
	 * @param BaseTemplate $tpl
	 * @return bool
	 * @throws ErrorPageError
	 */
	public static function onUploadFormInitial( $tpl ) {
		global $wglRequireLicensesToUpload, $wgForceUIMsgAsContentMsg;

		if ($wglRequireLicensesToUpload) {
			if ( !in_array( 'licenses', $wgForceUIMsgAsContentMsg )
				&& wfMessage( 'licenses' )->inContentLanguage()->isDisabled()
			) {
				throw new ErrorPageError( 'uploaddisabled', 'weirdgloop-upload-nolicenses' );
			}
		}

		return true;
	}

	/**
	 * Protect Weird Gloop system messages from being edited by those that do not have
	 * the "editinterfacesite" right. This is because system messages that are prefixed
	 * with "weirdgloop" are probably there for a legal reason or to ensure consistency
	 * across the site.
	 *
	 * @return str
	 * @return bool
	 */
	public static function ongetUserPermissionsErrors( $title, $user, $action, &$result ) {
		global $wglProtectSiteInterface;

		if ( $wglProtectSiteInterface
			&& $title->inNamespace( NS_MEDIAWIKI )
			&& strpos( lcfirst( $title->getDBKey() ), 'weirdgloop-' ) === 0
			&& !$user->isAllowed( 'editinterfacesite' )
			&& $action !== 'read'
		) {
				$result = 'weirdgloop-siteinterface';
				return false;
		}

		return true;
	}

	/**
	 * Implement a dark mode and add structured data for the Google Sitelinks search box.
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		global $wglEnableLoadingDarkmode, $wglEnableLoadingReadermode, $wglEnableSearchboxMetadata, $wgArticlePath, $wgCanonicalServer;

		if ( $wglEnableLoadingDarkmode ) {
			/* Dark mode */
			if ( isset( $_COOKIE['darkmode'] ) ) {
				if ( $_COOKIE['darkmode'] == 'true' ) {
					$out->addModuleStyles( [ 'wg.darkmode' ] );
				}
			}
		}

		if ( $wglEnableLoadingReadermode ) {
			/* Reader mode */
			if ( isset( $_COOKIE['readermode'] ) ) {
				if ( $_COOKIE['readermode'] == 'true' ) {
					$out->addModuleStyles( [ 'wg.readermode' ] );
				}
			}
		}

		if ( $wglEnableSearchboxMetadata ) {
			/* Structured data for the Google Sitelinks search box. */
			if ( $out->getTitle()->isMainPage() ) {
				$targetUrl = $wgCanonicalServer . str_replace( '$1', 'Special:Search', $wgArticlePath );
				$targetUrl = wfAppendQuery( $targetUrl, 'search={search_term_string}' );
				$structuredData = [
					'@context'        => 'http://schema.org',
					'@type'           => 'WebSite',
					'url'             => $wgCanonicalServer,
					'potentialAction' => [
						'@type'       => 'SearchAction',
						'target'      => $targetUrl,
						'query-input' => 'required name=search_term_string',
					],
				];
				$out->addHeadItem( 'StructuredData', '<script type="application/ld+json">' . json_encode( $structuredData ) . '</script>' );
			}
		}

		return true;
	}

	// Cache OpenSearch for 600 seconds. (10 minutes)
	public static function onOpenSearchUrls( &$urls ) {
		foreach ( $urls as &$url ) {
			if ( in_array( $url['type'], [ 'application/x-suggestions+json', 'application/x-suggestions+xml' ] ) ) {
				$url['template'] = wfAppendQuery( $url['template'], [ 'maxage' => 600, 'smaxage' => 600, 'uselang' => 'content' ] );
			}
		}

		return true;
	}

	public static function onOutputPageBodyAttributes( OutputPage $out, Skin $sk, &$bodyAttrs ) {
		global $wglEnableLoadingDarkmode, $wglEnableLoadingReadermode;

		if ( $wglEnableLoadingDarkmode ) {
			// add a class to the body to identify if we're in darkmode or not
			// so gadgets can hook off it
			if ( isset( $_COOKIE['darkmode'] ) && $_COOKIE['darkmode'] == 'true' ) {
				$bodyAttrs['class'] .= ' wgl-darkmode';
			} else {
				$bodyAttrs['class'] .= ' wgl-lightmode';
			}
		}

		if ( $wglEnableLoadingReadermode ) {
			if ( isset( $_COOKIE['readermode'] ) && $_COOKIE['readermode'] == 'true' ) {
				// Add a class to the body so that gadgets can identify that we're using reader mode
				$bodyAttrs['class'] .= ' wgl-readermode';
			}
		}

		if ( isset( $_COOKIE['stickyheader'] ) && $_COOKIE['stickyheader'] == 'true' ) {
			// Add a class to the body so that gadgets can identify that we're using sticky headers
			$bodyAttrs['class'] .= ' wgl-stickyheader';
		}

		return true;
	}

	/**
	 * Implement diagnostic information into Special:Contact.
	 * Hook provided by ContactPage extension.
	 */
	public static function onContactPage( &$to, &$replyTo, &$subject, &$text ) {
		global $wglSendDetailsWithContactPage, $wgDBname, $wgRequest, $wgOut, $wgServer;

		$user = $wgOut->getUser();
		$userIP = $wgRequest->getIP();

		// Spam filter for Special:Contact, checks against [[MediaWiki:weirdgloop-contact-filter]] on metawiki. Regex per line and use '#' for comments.
		if ( !GloopTweaksUtils::checkContactFilter( $subject . "\n" . $text ) ) {
			wfDebugLog( 'GloopTweaks', "Blocked contact form from {$userIP} as their message matches regex in our contact filter" );
			return false;
		}

		// StopForumSpam check: only check users who are not registered already
		if ( $user->isAnon() && GloopStopForumSpam::isBlacklisted( $userIP ) ) {
			wfDebugLog( 'GloopTweaks', "Blocked contact form from {$userIP} as they are in StopForumSpam's database" );
			return false;
		}

		// Block contact page submissions that have an invalid "Reply to"
		// Bots appear to rewrite <input> tags with type='email' to type='text'
		// And then the form lets them submit without any additional verification.
		// if ( !filter_var( $replyTo, FILTER_VALIDATE_EMAIL ) ) {
		// 	wfDebugLog( 'GloopTweaks', "Blocked contact form from {$userIP} as the Reply-To address is not an email address" );
		// 	return false;
		// }

		if ($wglSendDetailsWithContactPage) {
			$text .= "\n\n---\n\n"; // original message
			$text .= $wgServer . ' (' . $wgDBname . ") [" . gethostname() . "]\n"; // server & database name
			$text .= $userIP . ' - ' . ( $_SERVER['HTTP_USER_AGENT'] ?? null ) . "\n"; // IP & user agent
			$text .= 'Referrer: ' . ( $_SERVER['HTTP_REFERER'] ?? null ) . "\n"; // referrer if any
			$text .= 'Skin: ' . $wgOut->getSkin()->getSkinName() . "\n"; // skin
			$text .= 'User: ' . $user->getName() . ' (' . $user->getId() . ')'; // user
		}

		return true;
	}

	/**
	 * Prevent infinite looping of main page requests with cache parameters.
	 */
	public static function onTestCanonicalRedirect( $request, $title, $output ) {
		if ( $title->isMainPage() && strpos( $request->getRequestURL(), '/?') === 0 ) {
			return false;
		}
	}

	/**
	 * Our main pages are at domain root, implemented as a hook,
	 * because $wgMainPageIsDomainRoot doesn't work for queries.
	 */
	public static function onGetLocalURLArticle( $title, &$url ) {
		if ( $title->isMainPage() ) {
			$url = '/';
		}
	}

	/**
	 * Use Short URL always, even for queries.
	 * Additionally apply it to main page queries,
	 * because $wgMainPageIsDomainRoot doesn't work for queries.
	 */
	public static function onGetLocalURLInternal( $title, &$url, $query ) {
		global $wgArticlePath, $wgScript;
		$dbkey = wfUrlencode( $title->getPrefixedDBkey() );
		if ( $title->isMainPage() ) {
			$url = wfAppendQuery( '/', $query );
		} elseif ( $url == "{$wgScript}?title={$dbkey}&{$query}" ) {
			$url = wfAppendQuery(str_replace( '$1', $dbkey, $wgArticlePath ), $query );
		}
	}

	/**
	 * Add internal mobile URL variant for purging.
	 */
	public static function onTitleSquidURLs( Title $title, array &$urls ) {
		global $wglCloudflareCacheBreaker, $wglCloudflareCacheEnabled, $wglCloudflareNoncachedNamespaces, $wglEnableMobileVariant;

		// Hack to remove namespaces we don't want to cache due to low traffic, but high purge rate.
		// TODO: Re-evaluate with MW 1.35 improvements.
		if ( !$wglCloudflareCacheEnabled || in_array( $this->getNamespace(), $wglCloudflareNoncachedNamespaces ) ) {
			$urls = [];
			return;
		}

		// Add cache breaker to desktop page views.
		// TODO: Decide if with MW 1.35, we should stop patching out 'action=history' and add it here as well.
		$internalViewURL = $title->getInternalURL();
		foreach ( $urls as $key => $url ) {
			if ( $url === $internalViewURL ) {
				$urls[$key] = $title->getInternalURL( 'wglCacheVer=' . $wglCloudflareCacheBreaker );
				break;
			}
		}

		// Cache mobile page views.
		if ( $wglEnableMobileVariant ) {
			$urls[] = $title->getInternalURL( 'wglCacheVer=' . $wglCloudflareCacheBreaker . '&wglMobile=1' );
		}
	}

	/**
	 * External Lua library for Scribunto
	 *
	 * @param string $engine
	 * @param array &$extraLibraries
	 */
	public static function onScribuntoExternalLibraries( $engine, array &$extraLibraries ) {
		if ( $engine == 'lua' ) {
			$extraLibraries['mw.ext.GloopTweaks'] = 'Scribunto_LuaGloopTweaksLibrary';
		}
	}
}
