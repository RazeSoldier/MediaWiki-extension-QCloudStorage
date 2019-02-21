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

class QCloudAPIClient {
	/**
	 * @var Client
	 */
	private $client;

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
	}

	/**
	 * Get Qcloud\Cos\Client instance
	 * @return Client
	 */
	public function get() : Client {
		return $this->client;
	}
}
