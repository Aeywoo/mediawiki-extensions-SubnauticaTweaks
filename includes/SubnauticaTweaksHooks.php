<?php

namespace MediaWiki\Extension\SubnauticaTweaks;

use CdnCacheUpdate;
use DeferredUpdates;
use ErrorPageError;
use Html;
use MediaWiki\Extension\SubnauticaTweaks\ResourceLoader\ThemeStylesModule;
use MediaWiki\Extension\SubnauticaTweaks\StopForumSpam\StopForumSpam;
use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use OutputPage;
use RequestContext;
use Skin;
use Title;
use WikiMap;
use WikiPage;

/**
 * Hooks for SubnauticaTweaks extension
 *
 * @file
 * @ingroup Extensions
 */
class SubnauticaTweaksHooks {
	/**
	 * When core requests certain messages, change the key to a Subnautica version.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/MessageCacheFetchOverrides
	 * @param string[] &$keys
	 */
	public static function onMessageCacheFetchOverrides( array &$keys ): void {
		global $wgSubnauticaTweaksEnableMessageOverrides;
		if ( !$wgSubnauticaTweaksEnableMessageOverrides ) return;

		static $keysToOverride = [
			'privacypage',
			'changecontentmodel-text',
			'emailmessage',
			'mobile-frontend-copyright',
			'contactpage-pagetext',
			'newusermessage-editor',
			'revisionslider-help-dialog-slide1'
		];

		foreach( $keysToOverride as $key ) {
			$keys[$key] = "subnautica-$key";
		}
	}

	// When [[MediaWiki:subnautica-contact-filter]] is edited, clear the contact-filter-regexes global cache key.
	public static function onPageSaveComplete( WikiPage $wikiPage, UserIdentity $user, string $summary, int $flags, RevisionRecord $revisionRecord, EditResult $editResult ) {
		if ( $wikiPage->getTitle()->getPrefixedDBkey() === 'MediaWiki:Subnautica-contact-filter' ) {
			$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

			$cache->delete(
				$cache->makeGlobalKey(
					'SubnauticaTweaks',
					'contact-filter-regexes'
				)
			);
		}
	}

	/**
	 * Override with Weird Subnautica's site-specific copyright message.
	 *
	 * @param Title $title
	 * @param string $type
	 * @param string &$msg
	 * @param string &$link
	 */
	public static function onSkinCopyrightFooter( $title, $type, &$msg, &$link ) {
		global $wgSubnauticaTweaksEnableMessageOverrides;

		if ($wgSubnauticaTweaksEnableMessageOverrides) {
			if ( $type !== 'history' ) {
				$msg = 'subnautica-copyright';
			}
		}
	}

	/**
	 * Add some links at the bottom of pages
	 *
	 * @param Skin $skin
	 * @param string $key
	 * @param array &$footerLinks
	 */
	public static function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerLinks ) {
		global $wgSubnauticaTweaksAddFooterLinks;

		if ( $wgSubnauticaTweaksAddFooterLinks && $key === 'places' ) {
			$footerLinks['tou'] = Html::element(
				'a',
				[
					'href' => Skin::makeInternalOrExternalUrl(
						$skin->msg( 'subnautica-tou-url' )->inContentLanguage()->text()
					),
				],
				$skin->msg( 'subnautica-tou' )->text()
			);

			$footerLinks['contact'] = Html::element(
				'a',
				[
					'href' => Skin::makeInternalOrExternalUrl(
						$skin->msg( 'subnautica-contact-url' )->inContentLanguage()->text()
					),
				],
				$skin->msg( 'subnautica-contact' )->text()
			);
		}
	}

	/**
	 * Set the message on GlobalBlocking IP block being triggered
	 *
	 * @param string &$msg The message to over-ride
	 */
	public static function onGlobalBlockingBlockedIpMsg( &$msg ) {
		global $wgSubnauticaTweaksEnableMessageOverrides;

		if ($wgSubnauticaTweaksEnableMessageOverrides) {
			$msg = 'subnautica-globalblocking-ipblocked';
		}
	}

	/**
	 * Set the message on GlobalBlocking XFF block being triggered
	 *
	 * @param string &$msg The message to over-ride
	 */
	public static function onGlobalBlockingBlockedIpXffMsg( &$msg ) {
		global $wgSubnauticaTweaksEnableMessageOverrides;

		if ($wgSubnauticaTweaksEnableMessageOverrides) {
			$msg = 'subnautica-globalblocking-ipblocked-xff';
		}
	}

	/**
	 * Require the creation of MediaWiki:Licenses to enable uploading.
	 *
	 * Do not require it when licenses is in $wgForceUIMsgAsContentMsg,
	 * to prevent checking each subpage of MediaWiki:Licenses.
	 *
	 * @param BaseTemplate $tpl
	 * @throws ErrorPageError
	 */
	public static function onUploadFormInitial( $tpl ) {
		global $wgSubnauticaTweaksRequireLicensesToUpload, $wgForceUIMsgAsContentMsg;

		if ($wgSubnauticaTweaksRequireLicensesToUpload) {
			if ( !in_array( 'licenses', $wgForceUIMsgAsContentMsg )
				&& wfMessage( 'licenses' )->inContentLanguage()->isDisabled()
			) {
				throw new ErrorPageError( 'uploaddisabled', 'subnautica-upload-nolicenses' );
			}
		}
	}

	/**
	 * Restrict sensitive user rights to only 2FAed sessions.
	 *
	 * @param User $user Current user
	 * @param array &$rights Current user rights.
	 */
	public static function onUserGetRightsRemove( $user, &$rights ) {
		global $wgSubnauticaTweaksSensitiveRights;

		// Avoid 2FA lookup if the user doesn't have any sensitive user rights.
		if ( array_intersect( $wgSubnauticaTweaksSensitiveRights, $rights ) === [] ) {
			return;
		}

		$userRepo = MediaWikiServices::getInstance()->getService( 'OATHUserRepository' );
		$oathUser = $userRepo->findByUser( $user );
		if ( $oathUser->getModule() === null ) {
			// No 2FA, remove sensitive user rights.
			$rights = array_diff( $rights, $wgSubnauticaTweaksSensitiveRights );
		}
	}

	/**
	 * Protect Subnautica system messages from being edited by those that do not have
	 * the "editinterfacesite" right. This is because system messages that are prefixed
	 * with "subnautica" are probably there for a legal reason or to ensure consistency
	 * across the site.
	 *
	 * @return bool
	 */
	public static function ongetUserPermissionsErrors( $title, $user, $action, &$result ) {
		global $wgSubnauticaTweaksProtectSiteInterface;

		if ( $wgSubnauticaTweaksProtectSiteInterface
			&& $action !== 'read'
			&& $title->inNamespace( NS_MEDIAWIKI )
			&& strpos( lcfirst( $title->getDBKey() ), 'subnautica-' ) === 0
			&& !$user->isAllowed( 'editinterfacesite' )
		) {
				$result = 'subnautica-siteinterface';
				return false;
		}

		return true;
	}

	/**
	 * Implement theming and add structured data for the Google Sitelinks search box.
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		global $wgSubnauticaTweaksAnalyticsID, $wgCloudflareDomain, $wgSubnauticaTweaksCSP, $wgSubnauticaTweaksCSPAnons, $wgSitename;
		global $wgSubnauticaTweaksEnableTheming, $wgSubnauticaTweaksEnableLoadingFixedWidth, $wgSubnauticaTweaksEnableStructuredData, $wgArticlePath, $wgCanonicalServer;

		// For letting user JS import from additional sources, like the Wikimedia projects, they have a longer CSP than anons.
		if ( $wgSubnauticaTweaksCSP !== '' ) {
			$user = RequestContext::getMain()->getUser();
			$response = $out->getRequest()->response();

			if ( $wgSubnauticaTweaksCSPAnons === '' || ( $user && !$user->isAnon() ) ) {
				$response->header( 'Content-Security-Policy: ' . $wgSubnauticaTweaksCSP );
			} else {
				$response->header( 'Content-Security-Policy: ' . $wgSubnauticaTweaksCSPAnons );
			}
		}

		// Inject Google Tag Manager.
		if ( $wgSubnauticaTweaksAnalyticsID ) {
			$out->addInlineScript( "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','$wgSubnauticaTweaksAnalyticsID')" );
			$out->prependHTML( '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . $wgSubnauticaTweaksAnalyticsID . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' );
		}

		/*
		 * Server-side logic to implement theming and fixed width styling customisations.
		 * However, for most requests, this is instead done by our Cloudflare worker to avoid cache fragmentation.
		 * The actual styling is located on the wikis and toggling implemented through Gadgets.
		 */
		$cfWorker = $out->getRequest()->getHeader( 'CF-Worker' );
		$cfWorkerHandled = $out->getRequest()->getHeader( 'WGL-Worker' );
		$workerProcessed = $cfWorker !== false && $cfWorkerHandled === '1';

		// Avoid duplicate processing if this will be performed instead by our Cloudflare worker.
		if ( !$workerProcessed ) {
			/* Theming */
			if ( $wgSubnauticaTweaksEnableTheming ) {
				$legacyDarkmode = isset( $_COOKIE['darkmode'] ) && $_COOKIE['darkmode'] === 'true';
				$theme = $_COOKIE['theme'] ?? ( $legacyDarkmode ? 'dark' : 'light' );

				// Light mode is the base styling, so it doesn't load a separate theme stylesheet.
				if ( $theme === 'light' ) {
					// Legacy non-darkmode selector.
					$out->addBodyClasses( [ 'wgl-lightmode' ] );
				} else {
					if ( $theme === 'dark' ) {
						// Legacy darkmode selector.
						$out->addBodyClasses( [ 'wgl-darkmode' ] );
					}
					$out->addModuleStyles( [ "wgl.theme.$theme" ] );
				}
				$out->addBodyClasses( [ "wgl-theme-$theme" ] );
			}

			/* Fixed width mode */
			if ( $wgSubnauticaTweaksEnableLoadingFixedWidth && isset( $_COOKIE['readermode'] ) && $_COOKIE['readermode'] === 'true' ) {
				$out->addBodyClasses( [ 'wgl-fixedWidth' ] );
				$out->addModuleStyles( [ 'wg.fixedwidth' ] );
			}
		}

		$title = $out->getTitle();
		if ( $title->isMainPage() ) {
			/* Open Graph protocol */
			$out->addMeta( 'og:title', $wgSitename );
			$out->addMeta( 'og:type', 'website' );

			/* Structured data for Google etc */
			if ( $wgSubnauticaTweaksEnableStructuredData ) {
				$structuredData = [
					'@context'        => 'http://schema.org',
					'@type'           => 'WebSite',
					'name'            => $wgSitename,
					'url'             => $wgCanonicalServer,
				];
				$out->addHeadItem( 'StructuredData', '<script type="application/ld+json">' . json_encode( $structuredData ) . '</script>' );
			}
		} else {
			/* Open Graph protocol */
			$out->addMeta( 'og:site_name', $wgSitename );
			$out->addMeta( 'og:title', $out->getHTMLTitle() );
			$out->addMeta( 'og:type', 'article' );
		}
		/* Open Graph protocol */
		$out->addMeta( 'og:url', $title->getFullURL() );
	}

	// Cache OpenSearch for 600 seconds. (10 minutes)
	public static function onOpenSearchUrls( &$urls ) {
		foreach ( $urls as &$url ) {
			if ( in_array( $url['type'], [ 'application/x-suggestions+json', 'application/x-suggestions+xml' ] ) ) {
				$url['template'] = wfAppendQuery( $url['template'], [ 'maxage' => 600, 'smaxage' => 600, 'uselang' => 'content' ] );
			}
		}
	}

	/**
	 * Implement diagnostic information into Special:Contact.
	 * Hook provided by ContactPage extension.
	 */
	public static function onContactPage( &$to, &$replyTo, &$subject, &$text ) {
		global $wgSubnauticaTweaksEnableContactFilter, $wgSubnauticaTweaksSendDetailsWithContactPage, $wgSubnauticaTweaksUseSFS, $wgDBname, $wgRequest, $wgOut, $wgServer;

		$user = $wgOut->getUser();
		$userIP = $wgRequest->getIP();

		// Spam filter for Special:Contact, checks against [[MediaWiki:subnautica-contact-filter]] on metawiki. Regex per line and use '#' for comments.
		if ( $wgSubnauticaTweaksEnableContactFilter && !SubnauticaTweaksUtils::checkContactFilter( $subject . "\n" . $text ) ) {
			wfDebugLog( 'SubnauticaTweaks', "Blocked contact form from {$userIP} as their message matches regex in our contact filter" );
			return false;
		}

		// StopForumSpam check: only check users who are not registered already
		if ( $wgSubnauticaTweaksUseSFS && $user->isAnon() && StopForumSpam::isBlacklisted( $userIP ) ) {
			wfDebugLog( 'SubnauticaTweaks', "Blocked contact form from {$userIP} as they are in StopForumSpam's database" );
			return false;
		}

		// Block contact page submissions that have an invalid "Reply to"
		// Bots appear to rewrite <input> tags with type='email' to type='text'
		// And then the form lets them submit without any additional verification.
		// if ( !filter_var( $replyTo, FILTER_VALIDATE_EMAIL ) ) {
		// 	wfDebugLog( 'SubnauticaTweaks', "Blocked contact form from {$userIP} as the Reply-To address is not an email address" );
		// 	return false;
		// }

		if ($wgSubnauticaTweaksSendDetailsWithContactPage) {
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
		global $wgScriptPath;
		if ( $title->isMainPage() && str_starts_with( $request->getRequestURL(), $wgScriptPath . '/?' ) ) {
			return false;
		}
	}

	/**
	 * Use Short URL always, even for queries.
	 * Additionally apply it to the main page
	 * because $wgMainPageIsDomainRoot doesn't apply to the internal URL, which is used for purging.
	 */
	public static function onGetLocalURLInternal( $title, &$url, $query ) {
		global $wgArticlePath, $wgScript, $wgMainPageIsDomainRoot, $wgScriptPath;
		$dbkey = wfUrlencode( $title->getPrefixedDBkey() );
		if ( $wgMainPageIsDomainRoot && $title->isMainPage() ) {
			$url = wfAppendQuery( $wgScriptPath . '/', $query );
		} elseif ( $url == "{$wgScript}?title={$dbkey}&{$query}" ) {
			$url = wfAppendQuery(str_replace( '$1', $dbkey, $wgArticlePath ), $query );
		}
	}

	/**
	 * Add purging for hashless thumbnails.
	 */
	public static function onLocalFilePurgeThumbnails( $file, $archiveName, $hashedUrls ) {
		$hashlessUrls = [];
		foreach ( $hashedUrls as $url ) {
			$hashlessUrls[] = strtok( $url, '?' );
		}

		// Purge the CDN
		DeferredUpdates::addUpdate( new CdnCacheUpdate( $hashlessUrls ), DeferredUpdates::PRESEND );
	}

	/**
	* Add purging for global robots.txt, well-known URLs, and hashless images.
	*/
	public static function onTitleSquidURLs( Title $title, array &$urls ) {
		global $wgCanonicalServer, $wgSubnauticaTweaksNetworkCentralDB, $wgDBname;
		$dbkey = $title->getPrefixedDBKey();
		// MediaWiki:Robots.txt on metawiki is global.
		if ( $wgDBname === $wgSubnauticaTweaksNetworkCentralDB && $dbkey === 'MediaWiki:Robots.txt' ) {
			// Purge each wiki's /robots.txt route.
			foreach( WikiMap::getCanonicalServerInfoForAllWikis() as $serverInfo ) {
				$urls[] = $serverInfo['url'] . '/robots.txt';
			}
		} elseif ( $dbkey === 'File:Apple-touch-icon.png' ) {
			$urls[] = $wgCanonicalServer . '/apple-touch-icon.png';
		} elseif ( $dbkey === 'File:Favicon.ico' ) {
			$urls[] = $wgCanonicalServer . '/favicon.ico';
		} elseif ( $title->getNamespace() == NS_FILE ) {
			$file = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->newFile( $title );
			if ( $file ) {
				$urls[] = strtok( $file->getUrl(), '?' );
			}
		}
	}

	/**
	* Register resource modules for themes.
	*/
	public static function onResourceLoaderRegisterModules( ResourceLoader $resourceLoader ) {
		global $wgSubnauticaTweaksThemes;
		foreach ( $wgSubnauticaTweaksThemes as $theme ) {
			$resourceLoader->register( "wgl.theme.$theme", [
				'class' => ThemeStylesModule::class,
				'theme' => $theme,
			] );

			// Legacy dark mode
			if ( $theme === 'dark' ) {
				$resourceLoader->register( 'wg.darkmode', [
					'class' => ThemeStylesModule::class,
					'theme' => $theme,
				] );
			}
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
			$extraLibraries['mw.ext.SubnauticaTweaks'] = Scribunto_LuaSubnauticaTweaksLibrary::class;
		}
	}
}