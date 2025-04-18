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

use MediaWiki\FileRepo\LocalRepo;
use MediaWiki\Title\Title;

class QCloudFile extends \LocalFile {
	/**
	 * @var string
	 */
	protected $repoClass = QCloudRepo::class;

	/**
	 * @var QCloudRepo
	 */
	public $repo;

	/**
	 * @see LocalFile::newFromTitle()
	 *
	 * @param Title $title
	 * @param LocalRepo $repo
	 * @param null $unused
	 * @return QCloudFile
	 */
	public static function newFromTitle( $title, $repo, $unused = null ): self {
		return new self( $title, $repo );
	}

	/**
	 * Rewrite \LocalFile::exists() to support check files on QCloud COS
	 * @return bool Whether file exist on disk.
	 */
	public function exists() {
		return $this->getPath() && $this->repo->fileExists( $this->path );
	}
}
