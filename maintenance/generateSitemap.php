<?php
// maintenance/generateSitemap.php from MediaWiki 1.37, modified to use filebackend to simplify GCS integration.
// Some added logic based on maintenance/GenerateFancyCaptchas.php from MediaWiki 1.37 release of ConfirmEdit.
// TODO: Implement deleting of non-referenced sitemaps (such as from deleted or later no-indexed namespaces).
/**
 * Creates a sitemap for the site.
 *
 * Copyright © 2005, Ævar Arnfjörð Bjarmason, Jens Frank <jeluf@gmx.de> and
 * Brion Vibber <brion@pobox.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 * @see http://www.sitemaps.org/
 * @see http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd
 */

namespace MediaWiki\Extension\SubnauticaTweaks\Maintenance;

use FilesystemIterator;
use Maintenance;
use MediaWiki\MediaWikiServices;
use MWException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Title;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;
use WikiMap;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script that generates a sitemap for the site.
 *
 * @ingroup Maintenance
 */
class GenerateSitemap extends Maintenance {
	private const GS_MAIN = -2;
	private const GS_TALK = -1;

	/**
	 * The maximum amount of urls in a sitemap file
	 *
	 * @link http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd
	 *
	 * @var int
	 */
	public $url_limit;

	/**
	 * The maximum size of a sitemap file
	 *
	 * @link http://www.sitemaps.org/faq.php#faq_sitemap_size
	 *
	 * @var int
	 */
	public $size_limit;

	/**
	 * The path to prepend to the filename
	 *
	 * @var string
	 */
	public $fspath;

	/**
	 * The URL path to prepend to filenames in the index;
	 * should resolve to the same directory as $fspath.
	 *
	 * @var string
	 */
	public $urlpath;

	/**
	 * Whether or not to use compression
	 *
	 * @var bool
	 */
	public $compress;

	/**
	 * Whether or not to include redirection pages
	 *
	 * @var bool
	 */
	public $skipRedirects;

	/**
	 * The number of entries to save in each sitemap file
	 *
	 * @var array
	 */
	public $limit = [];

	/**
	 * Key => value entries of namespaces and their priorities
	 *
	 * @var array
	 */
	public $priorities = [];

	/**
	 * A one-dimensional array of namespaces in the wiki
	 *
	 * @var array
	 */
	public $namespaces = [];

	/**
	 * When this sitemap batch was generated
	 *
	 * @var string
	 */
	public $timestamp;

	/**
	 * A database replica DB object
	 *
	 * @var IDatabase
	 */
	public $dbr;

	/**
	 * A resource pointing to the sitemap index file
	 *
	 * @var resource
	 */
	public $findex;

	/**
	 * A resource pointing to a sitemap file
	 *
	 * @var resource|false
	 */
	public $file;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Creates a sitemap for the site' );
		$this->addOption(
			'compress',
			'Compress the sitemap files, can take value yes|no, default yes',
			false,
			true
		);
		$this->addOption(
			'skip-redirects',
			'Do not include redirecting articles in the sitemap, can take value yes|no, default yes',
			false,
			true
		);
	}

	/**
	 * Execute
	 */
	public function execute() {
		global $wgCanonicalServer, $wgSubnauticaTweaksSitemapsCompressed;
		$this->setNamespacePriorities();
		$this->url_limit = 50000;
		$this->size_limit = ( 2 ** 20 ) * 10;

		// Create temporary directory to use for generating the sitemaps before uploading.
		$dbDomain = WikiMap::getCurrentWikiDbDomain()->getId();
		$tmpDir = wfTempDir() . '/subnautica-' . $dbDomain;
		if ( !wfMkdirParents( $tmpDir ) ) {
			$this->fatalError( "Could not create temporary directory.\n", 1 );
		}

		$this->fspath = realpath( $tmpDir ) . DIRECTORY_SEPARATOR;
		$this->urlpath = "$wgCanonicalServer/images/sitemaps/";
		// WGL - Make sitemap compression configurable as Chinese search engines don't support compressed sitemaps.
		$this->compress = $wgSubnauticaTweaksSitemapsCompressed;
		$this->skipRedirects = $this->hasOption( 'skip-redirects', 'yes' ) !== 'no';
		$this->dbr = $this->getDB( DB_REPLICA );
		$this->generateNamespaces();
		$this->timestamp = wfTimestamp( TS_ISO_8601, wfTimestampNow() );
		$this->findex = fopen( "{$this->fspath}index.xml", 'wb' );
		$this->main();
		$this->storeSitemaps();

		// Remove temporary directory.
		wfRecursiveRemoveDir( $tmpDir );
	}

	private function setNamespacePriorities() {
		global $wgSitemapNamespacesPriorities;

		// Custom main namespaces
		$this->priorities[self::GS_MAIN] = '0.5';
		// Custom talk namesspaces
		$this->priorities[self::GS_TALK] = '0.1';
		// MediaWiki standard namespaces
		$this->priorities[NS_MAIN] = '1.0';
		$this->priorities[NS_TALK] = '0.1';
		$this->priorities[NS_USER] = '0.5';
		$this->priorities[NS_USER_TALK] = '0.1';
		$this->priorities[NS_PROJECT] = '0.5';
		$this->priorities[NS_PROJECT_TALK] = '0.1';
		$this->priorities[NS_FILE] = '0.5';
		$this->priorities[NS_FILE_TALK] = '0.1';
		$this->priorities[NS_MEDIAWIKI] = '0.0';
		$this->priorities[NS_MEDIAWIKI_TALK] = '0.1';
		$this->priorities[NS_TEMPLATE] = '0.0';
		$this->priorities[NS_TEMPLATE_TALK] = '0.1';
		$this->priorities[NS_HELP] = '0.5';
		$this->priorities[NS_HELP_TALK] = '0.1';
		$this->priorities[NS_CATEGORY] = '0.5';
		$this->priorities[NS_CATEGORY_TALK] = '0.1';

		// Custom priorities
		if ( $wgSitemapNamespacesPriorities !== false ) {
			/**
			 * @var array $wgSitemapNamespacesPriorities
			 */
			foreach ( $wgSitemapNamespacesPriorities as $namespace => $priority ) {
				$float = floatval( $priority );
				if ( $float > 1.0 ) {
					$priority = '1.0';
				} elseif ( $float < 0.0 ) {
					$priority = '0.0';
				}
				$this->priorities[$namespace] = $priority;
			}
		}
	}

	/**
	 * Generate a one-dimensional array of existing namespaces
	 */
	private function generateNamespaces() {
		global $wgNamespaceRobotPolicies;
		// Only generate for specific namespaces if $wgSitemapNamespaces is an array.
		global $wgSitemapNamespaces;
		if ( is_array( $wgSitemapNamespaces ) ) {
			$this->namespaces = $wgSitemapNamespaces;

			return;
		}

		$res = $this->dbr->select( 'page',
			[ 'page_namespace' ],
			[],
			__METHOD__,
			[
				'GROUP BY' => 'page_namespace',
				'ORDER BY' => 'page_namespace',
			]
		);

		foreach ( $res as $row ) {
			$policy = $wgNamespaceRobotPolicies[$row->page_namespace] ?? '';
			if ( !str_contains( $policy, 'noindex' ) ) {
				$this->namespaces[] = $row->page_namespace;
			}
		}
	}

	/**
	 * Get the priority of a given namespace
	 *
	 * @param int $namespace The namespace to get the priority for
	 * @return string
	 */
	private function priority( $namespace ) {
		return $this->priorities[$namespace] ?? $this->guessPriority( $namespace );
	}

	/**
	 * If the namespace isn't listed on the priority list return the
	 * default priority for the namespace, varies depending on whether it's
	 * a talkpage or not.
	 *
	 * @param int $namespace The namespace to get the priority for
	 * @return string
	 */
	private function guessPriority( $namespace ) {
		return MediaWikiServices::getInstance()->getNamespaceInfo()->isSubject( $namespace )
			? $this->priorities[self::GS_MAIN]
			: $this->priorities[self::GS_TALK];
	}

	/**
	 * Return a database resolution of all the pages in a given namespace
	 *
	 * @param int $namespace Limit the query to this namespace
	 * @return IResultWrapper
	 */
	private function getPageRes( $namespace ) {
		return $this->dbr->select(
			[ 'page', 'page_props' ],
			[
				'page_namespace',
				'page_title',
				'page_touched',
				'page_is_redirect',
				'pp_propname',
			],
			[ 'page_namespace' => $namespace ],
			__METHOD__,
			[],
			[
				'page_props' => [
					'LEFT JOIN',
					[
						'page_id = pp_page',
						'pp_propname' => 'noindex'
					]
				]
			]
		);
	}

	/**
	 * Main loop
	 */
	public function main() {
		$services = MediaWikiServices::getInstance();
		$contLang = $services->getContentLanguage();
		$langConverter = $services->getLanguageConverterFactory()->getLanguageConverter( $contLang );

		fwrite( $this->findex, $this->openIndex() );

		foreach ( $this->namespaces as $namespace ) {
			$res = $this->getPageRes( $namespace );
			$this->file = false;
			$this->generateLimit( $namespace );
			$length = $this->limit[0];
			$i = $smcount = 0;

			$fns = $contLang->getFormattedNsText( $namespace );
			$this->output( "$namespace ($fns)\n" );
			$skippedRedirects = 0; // Number of redirects skipped for that namespace
			$skippedNoindex = 0; // Number of pages with __NOINDEX__ switch for that NS
			foreach ( $res as $row ) {
				if ( $row->pp_propname === 'noindex' ) {
					$skippedNoindex++;
					continue;
				}

				if ( $this->skipRedirects && $row->page_is_redirect ) {
					$skippedRedirects++;
					continue;
				}

				if ( $i++ === 0
					|| $i === $this->url_limit + 1
					|| $length + $this->limit[1] + $this->limit[2] > $this->size_limit
				) {
					if ( $this->file !== false ) {
						$this->write( $this->file, $this->closeFile() );
						$this->close( $this->file );
					}
					$filename = $this->sitemapFilename( $namespace, $smcount++ );
					$this->file = $this->open( $this->fspath . $filename, 'wb' );
					$this->write( $this->file, $this->openFile() );
					fwrite( $this->findex, $this->indexEntry( $filename ) );
					$this->output( "\t$this->fspath$filename\n" );
					$length = $this->limit[0];
					$i = 1;
				}
				$title = Title::makeTitle( $row->page_namespace, $row->page_title );
				$date = wfTimestamp( TS_ISO_8601, $row->page_touched );
				$entry = $this->fileEntry( $title->getCanonicalURL(), $date, $this->priority( $namespace ) );
				$length += strlen( $entry );
				$this->write( $this->file, $entry );
				// generate pages for language variants
				if ( $langConverter->hasVariants() ) {
					$variants = $langConverter->getVariants();
					foreach ( $variants as $vCode ) {
						if ( $vCode == $contLang->getCode() ) {
							continue; // we don't want default variant
						}
						$entry = $this->fileEntry(
							$title->getCanonicalURL( '', $vCode ),
							$date,
							$this->priority( $namespace )
						);
						$length += strlen( $entry );
						$this->write( $this->file, $entry );
					}
				}
			}

			if ( $skippedNoindex > 0 ) {
				$this->output( "  skipped $skippedNoindex page(s) with __NOINDEX__ switch\n" );
			}

			if ( $this->skipRedirects && $skippedRedirects > 0 ) {
				$this->output( "  skipped $skippedRedirects redirect(s)\n" );
			}

			if ( $this->file ) {
				$this->write( $this->file, $this->closeFile() );
				$this->close( $this->file );
			}
		}
		fwrite( $this->findex, $this->closeIndex() );
		fclose( $this->findex );
	}

	/**
	 * gzopen() / fopen() wrapper
	 *
	 * @param string $file
	 * @param string $flags
	 * @return resource
	 */
	private function open( $file, $flags ) {
		$resource = $this->compress ? gzopen( $file, $flags ) : fopen( $file, $flags );
		if ( $resource === false ) {
			throw new MWException( __METHOD__
				. " error opening file $file with flags $flags. Check permissions?" );
		}

		return $resource;
	}

	/**
	 * gzwrite() / fwrite() wrapper
	 *
	 * @param resource &$handle
	 * @param string $str
	 */
	private function write( &$handle, $str ) {
		if ( $handle === true || $handle === false ) {
			throw new MWException( __METHOD__ . " was passed a boolean as a file handle.\n" );
		}
		if ( $this->compress ) {
			gzwrite( $handle, $str );
		} else {
			fwrite( $handle, $str );
		}
	}

	/**
	 * gzclose() / fclose() wrapper
	 *
	 * @param resource &$handle
	 */
	private function close( &$handle ) {
		if ( $this->compress ) {
			gzclose( $handle );
		} else {
			fclose( $handle );
		}
	}

	/**
	 * Get a sitemap filename
	 *
	 * @param int $namespace
	 * @param int $count
	 * @return string
	 */
	private function sitemapFilename( $namespace, $count ) {
		$ext = $this->compress ? '.gz' : '';

		return "NS_$namespace-$count.xml$ext";
	}

	/**
	 * Return the XML required to open an XML file
	 *
	 * @return string
	 */
	private function xmlHead() {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	}

	/**
	 * Return the XML schema being used
	 *
	 * @return string
	 */
	private function xmlSchema() {
		return 'http://www.sitemaps.org/schemas/sitemap/0.9';
	}

	/**
	 * Return the XML required to open a sitemap index file
	 *
	 * @return string
	 */
	private function openIndex() {
		return $this->xmlHead() . '<sitemapindex xmlns="' . $this->xmlSchema() . '">' . "\n";
	}

	/**
	 * Return the XML for a single sitemap indexfile entry
	 *
	 * @param string $filename The filename of the sitemap file
	 * @return string
	 */
	private function indexEntry( $filename ) {
		return "\t<sitemap>\n" .
			"\t\t<loc>{$this->urlpath}$filename</loc>\n" .
			"\t\t<lastmod>{$this->timestamp}</lastmod>\n" .
			"\t</sitemap>\n";
	}

	/**
	 * Return the XML required to close a sitemap index file
	 *
	 * @return string
	 */
	private function closeIndex() {
		return "</sitemapindex>\n";
	}

	/**
	 * Return the XML required to open a sitemap file
	 *
	 * @return string
	 */
	private function openFile() {
		return $this->xmlHead() . '<urlset xmlns="' . $this->xmlSchema() . '">' . "\n";
	}

	/**
	 * Return the XML for a single sitemap entry
	 *
	 * @param string $url An RFC 2396 compliant URL
	 * @param string $date A ISO 8601 date
	 * @param string $priority A priority indicator, 0.0 - 1.0 inclusive with a 0.1 stepsize
	 * @return string
	 */
	private function fileEntry( $url, $date, $priority ) {
		return "\t<url>\n" .
			// T36666: $url may contain bad characters such as ampersands.
			"\t\t<loc>" . htmlspecialchars( $url ) . "</loc>\n" .
			"\t\t<lastmod>$date</lastmod>\n" .
			"\t\t<priority>$priority</priority>\n" .
			"\t</url>\n";
	}

	/**
	 * Return the XML required to close sitemap file
	 *
	 * @return string
	 */
	private function closeFile() {
		return "</urlset>\n";
	}

	/**
	 * Populate $this->limit
	 *
	 * @param int $namespace
	 */
	private function generateLimit( $namespace ) {
		// T19961: make a title with the longest possible URL in this namespace
		$title = Title::makeTitle( $namespace, str_repeat( "\u{28B81}", 63 ) . "\u{5583}" );

		$this->limit = [
			strlen( $this->openFile() ),
			strlen( $this->fileEntry(
				$title->getCanonicalURL(),
				wfTimestamp( TS_ISO_8601, wfTimestamp() ),
				$this->priority( $namespace )
			) ),
			strlen( $this->closeFile() )
		];
	}

	/**
	 * Store the generated sitemaps on the FileBackend.
	 */
	private function storeSitemaps() {
		global $wgSubnauticaTweaksSitemapsFileBackend;
		$services = MediaWikiServices::getInstance();
		$backend = $services->getFileBackendGroup()->get( $wgSubnauticaTweaksSitemapsFileBackend );

		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$this->fspath,
				FilesystemIterator::SKIP_DOTS
			),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		$filesToStore = [];
		$path = $backend->getRootStoragePath() . '/sitemaps/';
		/**
		 * @var $fileInfo SplFileInfo
		 */
		foreach ( $iter as $fileInfo ) {
			if ( !$fileInfo->isFile() ) {
				continue;
			}
			$backend->prepare( [ 'dir' => $path ] );
			$filesToStore[] = [
				'op' => 'store',
				'src' => $fileInfo->getPathname(),
				'dst' => $path . $fileInfo->getBasename(),
			];
		}
		var_dump($filesToStore);

		$backend->doQuickOperations( $filesToStore );
	}
}

$maintClass = GenerateSitemap::class;
require_once RUN_MAINTENANCE_IF_MAIN;
