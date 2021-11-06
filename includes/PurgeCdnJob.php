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

use DeferrableUpdate;
use MediaWiki\MediaWikiServices;
use TencentCloud\Cdn\V20180606\CdnClient;
use TencentCloud\Cdn\V20180606\Models\PurgeUrlsCacheRequest;
use TencentCloud\Common\Credential;

class PurgeCdnJob implements DeferrableUpdate {
	/**
	 * @var array
	 */
	private $pendingPurgeUrls;

	/**
	 * @param array $pendingPurgeUrls Collection of URLs to be purged from CDN
	 */
	public function __construct( array $pendingPurgeUrls ) {
		$this->pendingPurgeUrls = $pendingPurgeUrls;
	}

	/**
	 * @inheritDoc
	 */
	public function doUpdate() {
		$authConfig = MediaWikiServices::getInstance()->getMainConfig()->get( 'QCloudAuth' );
		$auth = new Credential( $authConfig['secretId'], $authConfig['secretKey'] );
		$client = new CdnClient( $auth, $authConfig['region'] );
		$req = new PurgeUrlsCacheRequest();
		$req->Urls = $this->pendingPurgeUrls;
		$client->PurgeUrlsCache( $req );
	}
}
