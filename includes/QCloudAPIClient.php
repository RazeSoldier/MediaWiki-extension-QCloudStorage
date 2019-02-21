<?php
/**
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
 */

namespace RazeSoldier\MWQCloudStorage;

use Qcloud\Cos\Client;
use Curl\Curl;
use MediaWiki\MediaWikiServices;

class QCloudAPIClient {
	/**
	 * @var Client
	 */
	private $client;

	private $secretId;
	private $secretKey;

	/**
	 * QCloudAPIClient constructor.
	 * @param array $config
	 */
	public function __construct( array $config ) {
		if ( !isset( $config['region'] ) ) {
			throw new \ConfigException( 'You have not configured "$wgQCloudAuth[\'region\']"' );
		}
		if ( !isset( $config['secretId'] ) ) {
			throw new \ConfigException( 'You have not configured "$wgQCloudAuth[\'secretId\']"' );
		}
		if ( !isset( $config['secretKey'] ) ) {
			throw new \ConfigException( 'You have not configured "$wgQCloudAuth[\'secretKey\']"' );
		}
		$this->client = new Client( [
			'region' => $config['region'],
			'credentials' => [
				'secretId' => $config['secretId'],
				'secretKey' => $config['secretKey'],
			],
		] );
		$this->secretId = $config['secretId'];
		$this->secretKey = $config['secretKey'];
	}

	/**
	 * Get Qcloud\Cos\Client instance
	 * @return Client
	 */
	public function get() : Client {
		return $this->client;
	}

	/**
	 * Used to PUT a object to COS
	 * Instead of Qcloud\Cos\Client::putObject(), because the SDK doesn't support setting file meta
	 * @param string $src
	 * @param string $dst
	 * @param string $host
	 * @param array $meta
	 * @return bool
	 * @throws \ErrorException
	 */
	public function upload( string $src, string $dst, string $host, array $meta = [] ) : bool {
		$headers = [];
		foreach ( $meta as $key => $value ) {
			$headers["x-cos-meta-$key"] = $value;
		}
		$headers['host'] = $host;
		$headers['Authorization'] = $this->sign( 'put', $dst, $headers );
		$magic = MediaWikiServices::getInstance()->getMimeAnalyzer();
		$headers['Content-Type'] = $magic->guessMimeType( $src );
		$curl = new Curl();
		$curl->setOpt( CURLOPT_PUT, true );
		$curl->setHeaders( $headers );
		if ( is_file( $src ) ) {
			$filesize = filesize( $src );
			$curl->setHeader( 'Content-Length', $filesize );
			$file = fopen( $src, 'rb' );
			$curl->setOpt( CURLOPT_INFILE, $file );
			$curl->setOpt( CURLOPT_INFILESIZE, $filesize );
			$curl->put( "https://$host$dst" );
		} else {
			$curl->setHeader( 'Content-Length', strlen( $src ) );
			$curl->put( "https://$host$dst", $src );
		}
		return $curl->getHttpStatusCode() === 200 ? true : false;
	}

	/**
	 * Generate request signature
	 * @see https://cloud.tencent.com/document/product/436/7778
	 * @param string $httpMethod HTTP method
	 * @param string $url
	 * @param array $headers
	 * @return string Signature
	 */
	private function sign( string $httpMethod, string $url, array $headers ) : string {
		$tmpHeaders = $headers;
		foreach ( $tmpHeaders as $key => $value ) {
			$headers[strtolower( $key )] = urlencode( $value );
		}
		ksort( $headers );
		$nowTime = time();
		// We sign 60 s
		$futureTime = $nowTime + 60;
		$signTime = "$nowTime;$futureTime";
		$signKey = hash_hmac( 'sha1', $signTime, $this->secretKey );
		$httpString = "$httpMethod\n$url\n\n";
		foreach ( $headers as $key => $value ) {
			$httpString .= "$key=$value&";
			$headerList[] = $key;
		}
		$httpString = substr( $httpString, 0, -1 );
		$httpString .= "\n";
		$sha1edHttpString = sha1( $httpString );
		$stringToSign = "sha1\n$signTime\n$sha1edHttpString\n";
		$signature = hash_hmac( 'sha1', $stringToSign, $signKey );
		 return "q-sign-algorithm=sha1&q-ak={$this->secretId}&q-sign-time=$signTime&q-key-time=$signTime".
			'&q-header-list=' . implode( ';', $headerList ) . "&q-url-param-list=&q-signature=$signature";
	}
}
