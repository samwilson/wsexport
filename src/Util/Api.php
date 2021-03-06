<?php

namespace App\Util;

/**
 * @author Thomas Pellissier Tanon
 * @copyright 2011 Thomas Pellissier Tanon
 * @license GPL-2.0-or-later
 */

use App\Exception\HttpException;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * a base class for communications with Wikisource
 */
class Api {
	private const USER_AGENT = 'Wikisource Export/0.1';
	private const CONNECT_TIMEOUT = 10; // in seconds
	private const REQUEST_TIMEOUT = 60; // in seconds

	public $lang = '';
	public $domainName = '';

	/**
	 * @var ClientInterface
	 */
	private $client;

	/**
	 * @param string $lang the language code of the Wikisource like 'en' or 'fr'
	 * @param string $domainName
	 * @param ClientInterface $client
	 */
	public function __construct( $lang = '', $domainName = '', ClientInterface $client = null ) {
		if ( $lang == '' ) {
			$lang = self::getHttpLang();
		}
		$this->lang = $lang;

		if ( $domainName != '' ) {
			$this->domainName = $domainName;
		} elseif ( $this->lang == 'www' || $this->lang == '' ) {
			$this->domainName = 'wikisource.org';
			$this->lang = '';
		} elseif ( $this->lang == 'wl' || $this->lang == 'wikilivres' ) {
			$this->domainName = 'wikilivres.ca';
			$this->lang = '';
		} elseif ( preg_match( '/^([a-z_]{2,})-?wikibooks$/', $this->lang, $m ) ) {
			$this->domainName = $m[1] . '.wikibooks.org';
			$this->lang = $m[1];
		} else {
			$this->domainName = $this->lang . '.wikisource.org';
		}
		if ( $client === null ) {
			$client = static::createClient( ToolLogger::get( __CLASS__ ) );
		}
		$this->client = $client;
	}

	/**
	 * @return ClientInterface
	 */
	public function getClient() {
		return $this->client;
	}

	/**
	 * GET action
	 *
	 * @param string $url the target URL
	 * @param array $options
	 * @return PromiseInterface the body of the result
	 */
	public function getAsync( $url, array $options = [] ) {
		return $this->client->getAsync(
			$url,
			$options
		)->then(
			function ( ResponseInterface $response ) {
				if ( $response->getStatusCode() !== 200 ) {
					throw new HttpException( 'HTTP error ' . $response->getStatusCode(), $response->getStatusCode() );
				}
				return $response->getBody()->getContents();
			}
		);
	}

	/**
	 * @param string $url
	 * @param array $options
	 * @return PromiseInterface
	 */
	public function createAsyncRequest( string $url, array $options = [] ): PromiseInterface {
		return $this->client->getAsync(
			$url,
			$options
		);
	}

	/**
	 * API query
	 *
	 * @deprecated Use Api::queryAsync
	 *
	 * @param array $params parameters sent to the api
	 * @return array result of the api query
	 * @throws HttpException
	 */
	public function query( $params ) {
		return $this->queryAsync( $params )->wait();
	}

	/**
	 * API query
	 *
	 * @param array $params an associative array for params send to the api
	 * @return PromiseInterface a Promise with the result array
	 * @throws HttpException
	 */
	public function queryAsync( $params ) {
		$params += [ 'action' => 'query', 'format' => 'json' ];

		return $this->getAsync(
			'https://' . $this->domainName . '/w/api.php',
			[ 'query' => $params ]
		)->then(
			function ( $result ) {
				$json = json_decode( $result, true );
				if ( isset( $json ) ) {
					return $json;
				} else {
					throw new Exception( 'invalid JSON: "' . $result . '": ' . json_last_error_msg() );
				}
			}
		);
	}

	/**
	 * api query. Give all pages of response
	 * @param array $params an associative array for params send to the api
	 * @return array an array with whe result of the api query
	 * @throws HttpException
	 */
	public function completeQuery( $params ) {
		$data = [];
		$continue = true;
		do {
			$temp = $this->query( $params );
			$data = array_merge_recursive( $data, $temp );

			if ( array_key_exists( 'continue', $temp ) ) {
				foreach ( $temp['continue'] as $keys => $value ) {
					$params[$keys] = $value;
				}
			} else {
				$continue = false;
			}
		} while ( $continue );

		return $data;
	}

	/**
	 * @param string $title the title of the page
	 * @return PromiseInterface promise with the content of a page
	 */
	public function getPageAsync( $title ) {
		return $this->queryAsync( [
			'titles' => $title,
			'prop' => 'revisions',
			'rvprop' => 'content',
			'rvparse' => true
		] )->then( function ( array $result ) {
			return $this->parseGetPageResponse( $result );
		} );
	}

	private function parseGetPageResponse( $response ) {
		$pages = $response['query']['pages'] ?? [];
		foreach ( $pages as $page ) {
			$title = $page['title'];
			if ( isset( $page['revisions'] ) ) {
				foreach ( $page['revisions'] as $revision ) {
					return Util::getXhtmlFromContent( $this->lang, $revision['*'], $title );
				}
			}
		}
		if ( !isset( $title ) ) {
			throw new HttpException( 'No page information found in response', 500 );
		}
		throw new HttpException( "Page revision not found for: $title", 404 );
	}

	/**
	 * @param string $url the url
	 * @return string the file content
	 */
	public function get( $url ) {
		return $this->client->get( $url )->getBody()->getContents();
	}

	/**
	 * @return string the lang of the Wikisource used
	 */
	public static function getHttpLang() {
		$lang = '';
		if ( isset( $_GET['lang'] ) ) {
			$lang = htmlspecialchars( $_GET['lang'] );
		} else {
			if ( isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
				$langs = explode( ',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
				if ( isset( $langs[0] ) ) {
					$langs = explode( '-', $langs[0] );
					$lang = $langs[0];
				}
			}
		}

		return strtolower( $lang );
	}

	/**
	 * @param string $url
	 * @return string the url encoded like mediawiki does.
	 */
	public static function mediawikiUrlEncode( string $url ): string {
		$search = [ '%21', '%24', '%28', '%29', '%2A', '%2C', '%2D', '%2E', '%2F', '%3A', '%3B', '%40' ];
		$replace = [ '!', '$', '(', ')', '*', ',', '-', '.', '/', ':', ';', '@' ];

		return str_replace( $search, $replace, urlencode( str_replace( ' ', '_', $url ) ) );
	}

	/**
	 * @param LoggerInterface $logger
	 * @return ClientInterface
	 */
	private static function createClient( LoggerInterface $logger ) {
		$handler = HandlerStack::create();
		$handler->push( LoggingMiddleware::forLogger( $logger ), 'logging' );
		return new Client( [
			'defaults' => [
				'connect_timeout' => self::CONNECT_TIMEOUT,
				'headers' => [ 'User-Agent' => self::USER_AGENT ],
				'timeout' => self::REQUEST_TIMEOUT
			],
			'handler' => $handler
		] );
	}
}
