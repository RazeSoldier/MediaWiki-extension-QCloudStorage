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

class QCloudRepo extends \LocalRepo {
	/**
	 * @var string[]
	 */
	protected $fileFactory = [ QCloudFile::class, 'newFromTitle' ];

	/**
	 * @var QCloudFileBackend
	 */
	protected $backend;

	/**
	 * QCloudRepo constructor.
	 * @param array $info From $wgLocalFileRepo
	 */
	public function __construct( array $info ) {
		parent::__construct( $info );
		$this->url = $this->backend->getViewpoint();
		$this->thumbUrl = $this->url . '/thumb';
	}

	/**
	 * @return QCloudFileBackend
	 */
	public function getBackend(): QCloudFileBackend {
		return $this->backend;
	}
}
