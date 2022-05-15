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

use DeferredUpdates;
use MediaWiki\MediaWikiServices;
use Qcloud\Cos\Exception\ServiceResponseException;
use StatusValue;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class QCloudFileBackend extends \FileBackendStore {
	/**
	 * @var string
	 */
	private $bucket;

	/**
	 * @var QCloudAPIClient
	 */
	private $client;

	/**
	 * @var string API endpoint URL
	 */
	private $endpoint;

	/**
	 * @var string API endpoint URL without protocol
	 */
	private $endpointBase;

	/**
	 * @var string Public access point
	 */
	private $viewpoint;

	/**
	 * QCloudFileBackend constructor.
	 * @param array $config From $wgFileBackends
	 */
	public function __construct( array $config ) {
		parent::__construct( $config );
		if ( !isset( $config['bucket'] ) ) {
			throw new \ConfigException( 'Need bucket config key' );
		}
		$this->bucket = $config['bucket'];
		$authConfig = MediaWikiServices::getInstance()->getMainConfig()->get( 'QCloudAuth' );
		$this->client = new QCloudAPIClient( $authConfig );
		$this->endpointBase = "{$this->bucket}.cos.{$authConfig['region']}.myqcloud.com";
		$this->endpoint = "https://{$this->endpointBase}";
		$this->viewpoint = $config['viewpoint'] ?? $this->endpoint;
		$this->memCache = MediaWikiServices::getInstance()->getMainWANObjectCache();
	}

	/**
	 * Check if a file can be created or changed at a given storage path.
	 * FS backends should check if the parent directory exists, files can be
	 * written under it, and that any file already there is writable.
	 * Backends using key/value stores should check if the container exists.
	 *
	 * @param string $storagePath
	 * @return bool
	 */
	public function isPathUsableInternal( $storagePath ): bool {
		// Always return TRUE, because QCloud COS will automatically create a directory
		return true;
	}

	/**
	 * @see FileBackendStore::createInternal()
	 * @param array $params
	 * @return StatusValue
	 */
	protected function doCreateInternal( array $params ): StatusValue {
		$this->handleOpsOption( $params );
		try {
			$this->client->get()->putObject( [
				'Bucket' => $this->bucket,
				'Key' => $this->getRemoteStoragePath( $params['dst'] ),
				'Body' => $params['content'],
			] );
		} catch ( ServiceResponseException $e ) {
			return StatusValue::newFatal( $e->getMessage() );
		}
		return StatusValue::newGood();
	}

	/**
	 * @see FileBackendStore::storeInternal()
	 * @param array $params
	 * @return StatusValue
	 */
	protected function doStoreInternal( array $params ): StatusValue {
		$this->handleOpsOption( $params );
		if ( !$this->checkFileCanOverwriteIfExists( $params['dst'], $params['overwrite'] ) ) {
			return StatusValue::newFatal( 'The target path already exists' );
		}
		try {
			$fileSha1 = sha1_file( $params['src'] );
			$meta = [
				'sha1' => \Wikimedia\base_convert( $fileSha1, 16, 36, 31 ),
				'size' => filesize( $params['src'] ),
			];
			$res = $this->client->upload( $params['src'], $this->getRemoteStoragePath( $params['dst'] ),
				$this->endpointBase, $meta );
			if ( !$res ) {
				return StatusValue::newFatal( "Failed to upload {$params['src']}" );
			}
		} catch ( ServiceResponseException $e ) {
			return StatusValue::newFatal( $e->getMessage() );
		}
		return StatusValue::newGood();
	}

	/**
	 * @see FileBackendStore::copyInternal()
	 * @param array $params
	 * @return StatusValue
	 */
	protected function doCopyInternal( array $params ): StatusValue {
		$this->handleOpsOption( $params );
		try {
			$this->client->get()->copyObject( [
				'Bucket' => $this->bucket,
				'CopySource' => $this->endpointBase . '/' . $this->getRemoteStoragePath( $params['src'] ),
				'Key' => $this->getRemoteStoragePath( $params['dst'] ),
			] );
		} catch ( ServiceResponseException $e ) {
			if ( $params['ignoreMissingSource'] ) {
				return StatusValue::newGood();
			} else {
				return StatusValue::newFatal( $e->getMessage() );
			}
		}
		return StatusValue::newGood();
	}

	/**
	 * @see FileBackendStore::deleteInternal()
	 * @param array $params
	 * @return StatusValue
	 */
	protected function doDeleteInternal( array $params ): StatusValue {
		$this->handleOpsOption( $params );
		try {
			$this->client->get()->deleteObject( [
				'Bucket' => $this->bucket,
				'Key' => $this->getRemoteStoragePath( $params['src'] ),
			] );
		} catch ( ServiceResponseException $e ) {
			if ( $e->getExceptionCode() === 'NoSuchKey' ) {
				if ( $params['ignoreMissingSource'] ) {
					return StatusValue::newGood();
				} else {
					return StatusValue::newFatal( $e->getMessage() );
				}
			}
			return StatusValue::newFatal( $e->getMessage() );
		}

		if ( MediaWikiServices::getInstance()->getMainConfig()->get( 'QCloudUseCdn' ) ) {
			// Purge the CDN cache after deleted the files, avoid users accessing edge caches that have been deleted
			DeferredUpdates::addUpdate( new PurgeCdnJob( [ $this->getViewPointFullUrl( $params['src'] ) ] ) );
		}
		return StatusValue::newGood();
	}

	/**
	 * @see FileBackendStore::getFileStat()
	 * @param array $params
	 * @return array|false
	 */
	protected function doGetFileStat( array $params ) {
		$this->logger->debug( "Doing QCloudFileBackend::doGetFileStat(): {$params['src']}" );
		$start = microtime( true );
		try {
			$res = $this->client->get()->headObject( [ 'Bucket' => $this->bucket,
				'Key' => $this->getRemoteStoragePath( $params['src'] ) ] );
		} catch ( ServiceResponseException $e ) {
			return false;
		}
		$duration = microtime( true ) - $start;
		$this->logger->debug( 'QCloudFileBackend::doGetFileStat() done, duration: ' . $duration . 's' );

		$return = [
			'size' => $res['Metadata']['size'],
			'mtime' => ( new ConvertibleTimestamp( $res['LastModified'] ) )->getTimestamp( TS_MW ),
		];
		if ( isset( $res['Metadata'] ) ) {
			$return = array_merge( $return, $res['Metadata'] );
		}
		return $return;
	}

	/**
	 * @see FileBackendStore::getLocalCopyMulti()
	 * @param array $params
	 * @return array
	 */
	protected function doGetLocalCopyMulti( array $params ): array {
		/** @var \TempFSFile[] $tmpFiles */
		$tmpFiles = [];
		/** @var array $reqs Store information about bulk operations */
		$reqs = [];
		foreach ( $params['srcs'] as $src ) {
			// Get source file extension
			$ext = \FileBackend::extensionFromPath( $src );
			// Create a new temporary file...
			$tmpFile = $this->tmpFileFactory->newTempFSFile( 'localcopy_', $ext );
			if ( $tmpFile ) {
				$handle = fopen( $tmpFile->getPath(), 'wb' );
				if ( $handle ) {
					$reqs[$src] = [
						'Bucket' => $this->bucket,
						'Key' => $this->getRemoteStoragePath( $src ),
						'SaveAs' => $tmpFile->getPath(),
					];
				} else {
					$tmpFile = null;
				}
			}
			$tmpFiles[$src] = $tmpFile;
		}

		// Batch exec request
		foreach ( $reqs as $src => $req ) {
			try {
				$res = $this->client->get()->getObject( $req );
				$fileSize = $tmpFiles[$src] ? $tmpFiles[$src]->getSize() : 0;
				// Double check that the disk is not full/broken
				if ( $fileSize != $res['ContentLength'] ) {
					$tmpFiles[$src] = null;
					$errorMsg = "Try to download $src but got {$fileSize}/{$res['ContentLength']} bytes";
					wfDebug( '[' . __CLASS__ . '::' . __METHOD__ . "] $errorMsg" );
				}
			} catch ( ServiceResponseException $e ) {
				$tmpFiles[$src] = null;
			}
		}

		return $tmpFiles;
	}

	/**
	 * @see FileBackendStore::directoryExists()
	 *
	 * @param string $container Resolved container name
	 * @param string $dir Resolved path relative to container
	 * @param array $params
	 * @return bool
	 */
	protected function doDirectoryExists( $container, $dir, array $params ): bool {
		$containerName = $this->getContainerName( $container );
		$dir = $containerName === 'public' ? '' : $containerName;
		$dir .= $dir;
		$res = $this->client->get()->listObjects( [
			'Bucket' => $this->bucket,
			'Prefix' => $dir,
		] );
		return isset( $res['Contents'] );
	}

	/**
	 * Do not call this function from places outside FileBackend
	 *
	 * @see FileBackendStore::getDirectoryList()
	 *
	 * @param string $container Resolved container name
	 * @param string $dir Resolved path relative to container
	 * @param array $params
	 * @return array
	 * @throws \FileBackendError
	 */
	public function getDirectoryListInternal( $container, $dir, array $params ): array {
		$dirs = [];
		$files = $this->getFileListInternal( $container, $dir, $params );
		foreach ( $files as $filePath ) {
			// First handle the path ending with a slash
			if ( substr( $filePath, -1 ) === '/' ) {
				$dirs[] = substr( $filePath, 0, -1 );
				continue;
			}
			$pos = strrpos( '/', $filePath );
			if ( $pos === false ) {
				// $filePath is a file
				continue;
			}
			// Only add the top dirs if $params['topOnly'] set
			if ( isset( $params['topOnly'] ) && $params['topOnly'] === true
				&& substr_count( $filePath, '/' ) !== 1
			) {
				continue;
			}
			$filePrefix = substr( $filePath, 0, $pos );
			if ( in_array( $filePrefix, $dirs ) ) {
				$dirs[] = $filePrefix;
			}
		}
		return $dirs;
	}

	/**
	 * Do not call this function from places outside FileBackend
	 *
	 * @see FileBackendStore::getFileList()
	 *
	 * @param string $container Resolved container name
	 * @param string $dir Resolved path relative to container
	 * @param array $params
	 * @return string[]
	 * @throws \FileBackendError
	 */
	public function getFileListInternal( $container, $dir, array $params ): array {
		$res = [];
		try {
			$result = $this->client->get()->listObjects( [ 'Bucket' => $this->bucket, 'Prefix' => $dir ] );
			if ( isset( $result['Contents'] ) ) {
				$files = $result['Contents'];
				foreach ( $files as $file ) {
					$res[] = $file['Key'];
				}
			}
		} catch ( ServiceResponseException $e ) {
			throw new \FileBackendError( $e->getMessage(), $e->getCode() );
		}
		return $res;
	}

	/**
	 * Is this a key/value store where directories are just virtual?
	 * Virtual directories exists in so much as files exists that are
	 * prefixed with the directory path followed by a forward slash.
	 *
	 * @return bool
	 */
	protected function directoriesAreVirtual(): bool {
		return true;
	}

	// Helper methods:

	/**
	 * Can overwritten the existing file?
	 * @param string $src
	 * @param bool $overwrite
	 * @return bool Returns FALSE if the file exists but $overwrite is FALSE, otherwise returns TRUE
	 */
	private function checkFileCanOverwriteIfExists( string $src, bool $overwrite ): bool {
		if ( $this->fileExists( [ 'src' => $src ] ) ) {
			return $overwrite;
		}
		return true;
	}

	/**
	 * Used to convert the container name and the real path to a full remote path
	 * @param string $storagePath
	 * @return string
	 */
	private function getRemoteStoragePath( string $storagePath ): string {
		list( $container, $real ) = $this->resolveStoragePathReal( $storagePath );
		$containerName = $this->getContainerName( $container );
		// public container shouldn't need prefix
		$prefix = $containerName === 'public' ? '' : "$containerName/";
		return "$prefix$real";
	}

	/**
	 * Used to convert a standard container name into a simple name
	 * @param string $container
	 * @return string
	 */
	private function getContainerName( string $container ): string {
		preg_match( '/(\w*-)*(?<name>\w*)/', $container, $matches );
		if ( !isset( $matches['name'] ) ) {
			throw new \RuntimeException( "Failed to find ContainerName in '$container'" );
		}
		return $matches['name'];
	}

	/**
	 * Used to pre-handle the boolean flags for operation.
	 * This method will define all options, even if they don't exist.
	 * @see \FileBackend::doOperations()
	 * @param array &$op
	 */
	private function handleOpsOption( array &$op ) {
		$op['ignoreMissingSource'] = $op['ignoreMissingSource'] ?? false;
		$op['overwrite'] = $op['overwrite'] ?? false;
		$op['overwriteSame'] = $op['overwriteSame'] ?? false;
		$op['headers'] = $op['headers'] ?? [];
	}

	/**
	 * Return a full viewpoint URL includes protocol
	 * @param string $storePath
	 * @return string
	 */
	private function getViewPointFullUrl( string $storePath ): string {
		return $this->viewpoint . '/' . $this->getRemoteStoragePath( $storePath );
	}

	/**
	 * Return API endpoint
	 * @return string
	 */
	public function getEndpoint(): string {
		return $this->endpoint;
	}

	/**
	 * @return string
	 */
	public function getViewpoint(): string {
		return $this->viewpoint;
	}
}
