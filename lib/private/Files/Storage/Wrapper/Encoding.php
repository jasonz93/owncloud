<?php
/**
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Files\Storage\Wrapper;

use OCP\ICache;
use OC\Cache\CappedMemoryCache;

/**
 * Encoding wrapper that deals with file names that use unsupported encodings like NFD.
 *
 * When applied and a UTF-8 file name was given, the wrapper will first attempt to access
 * the actual given name
 */
class Encoding extends Wrapper {

	const NOT_FOUND = '';

	/**
	 * @var ICache
	 */
	private $namesCache;

	/**
	 * @param array $parameters
	 */
	public function __construct($parameters) {
		$this->storage = $parameters['storage'];
		$this->namesCache = new CappedMemoryCache();
	}

	/**
	 * Returns whether the given string is only made of ASCII characters
	 *
	 * @param string $str string
	 *
	 * @return bool true if the string is all ASCII, false otherwise
	 */
	private function isAscii($str) {
		return 0 == preg_match('/[^\x00-\x7F]/', $str);
	}

	/**
	 * Checks whether the given path exists in NFC or NFD form and returns
	 * the correct form. If no existing path found, returns the pass as
	 * it was given.
	 *
	 * @param string $path path to check
	 *
	 * @return string original or converted path
	 */
	private function findPathToUse($path) {
		if ($path !== '' && !$this->isAscii($path)) {
			$cachedPath = $this->namesCache[$path];
			if ($cachedPath !== null) {
				return $cachedPath;
			}

			$result = $this->storage->file_exists($path);
			if ($result) {
				$this->namesCache[$path] = $path;
				return $path;
			}
			// swap encoding
			if (\Normalizer::isNormalized($path, \Normalizer::FORM_C)) {
				$otherFormPath = \Normalizer::normalize($path, \Normalizer::FORM_D);
			} else {
				$otherFormPath = \Normalizer::normalize($path, \Normalizer::FORM_C);
			}
			if ($this->storage->file_exists($otherFormPath)) {
				$this->namesCache[$path] = $otherFormPath;
				return $otherFormPath;
			}

			// return original path, file did not exist at all
			$this->namesCache->set[$path] = self::NOT_FOUND;
			return $path;
		}
		return $path;
	}

	/**
	 * see http://php.net/manual/en/function.mkdir.php
	 *
	 * @param string $path
	 * @return bool
	 */
	public function mkdir($path) {
		// note: no conversion here, method should not be called with non-NFC names!
		return $this->storage->mkdir($path);
	}

	/**
	 * see http://php.net/manual/en/function.rmdir.php
	 *
	 * @param string $path
	 * @return bool
	 */
	public function rmdir($path) {
		return $this->storage->rmdir($this->findPathToUse($path));
	}

	/**
	 * see http://php.net/manual/en/function.opendir.php
	 *
	 * @param string $path
	 * @return resource
	 */
	public function opendir($path) {
		return $this->storage->opendir($this->findPathToUse($path));
	}

	/**
	 * see http://php.net/manual/en/function.is_dir.php
	 *
	 * @param string $path
	 * @return bool
	 */
	public function is_dir($path) {
		return $this->storage->is_dir($this->findPathToUse($path));
	}

	/**
	 * see http://php.net/manual/en/function.is_file.php
	 *
	 * @param string $path
	 * @return bool
	 */
	public function is_file($path) {
		return $this->storage->is_file($this->findPathToUse($path));
	}

	/**
	 * see http://php.net/manual/en/function.stat.php
	 * only the following keys are required in the result: size and mtime
	 *
	 * @param string $path
	 * @return array
	 */
	public function stat($path) {
		return $this->storage->stat($this->findPathToUse($path));
	}

	/**
	 * see http://php.net/manual/en/function.filetype.php
	 *
	 * @param string $path
	 * @return bool
	 */
	public function filetype($path) {
		return $this->storage->filetype($this->findPathToUse($path));
	}

	/**
	 * see http://php.net/manual/en/function.filesize.php
	 * The result for filesize when called on a folder is required to be 0
	 *
	 * @param string $path
	 * @return int
	 */
	public function filesize($path) {
		return $this->storage->filesize($this->findPathToUse($path));
	}

	/**
	 * check if a file can be created in $path
	 *
	 * @param string $path
	 * @return bool
	 */
	public function isCreatable($path) {
		return $this->storage->isCreatable($this->findPathToUse($path));
	}

	/**
	 * check if a file can be read
	 *
	 * @param string $path
	 * @return bool
	 */
	public function isReadable($path) {
		return $this->storage->isReadable($this->findPathToUse($path));
	}

	/**
	 * check if a file can be written to
	 *
	 * @param string $path
	 * @return bool
	 */
	public function isUpdatable($path) {
		return $this->storage->isUpdatable($this->findPathToUse($path));
	}

	/**
	 * check if a file can be deleted
	 *
	 * @param string $path
	 * @return bool
	 */
	public function isDeletable($path) {
		return $this->storage->isDeletable($this->findPathToUse($path));
	}

	/**
	 * check if a file can be shared
	 *
	 * @param string $path
	 * @return bool
	 */
	public function isSharable($path) {
		return $this->storage->isSharable($this->findPathToUse($path));
	}

	/**
	 * get the full permissions of a path.
	 * Should return a combination of the PERMISSION_ constants defined in lib/public/constants.php
	 *
	 * @param string $path
	 * @return int
	 */
	public function getPermissions($path) {
		return $this->storage->getPermissions($this->findPathToUse($path));
	}

	/**
	 * see http://php.net/manual/en/function.file_exists.php
	 *
	 * @param string $path
	 * @return bool
	 */
	public function file_exists($path) {
		// trigger caching of existing path entry
		$this->findPathToUse($path);
		$cachedPath = $this->namesCache[$path];
		// was not found with file_exists
		if ($cachedPath === self::NOT_FOUND) {
			return false;
		}
		if ($cachedPath === null) {
			// ASCII path case
			return $this->storage->file_exists($path);
		}
		// file name cached, meaning a file was found with one of the encodings
		return true;
	}

	/**
	 * see http://php.net/manual/en/function.filemtime.php
	 *
	 * @param string $path
	 * @return int
	 */
	public function filemtime($path) {
		return $this->storage->filemtime($this->findPathToUse($path));
	}

	/**
	 * see http://php.net/manual/en/function.file_get_contents.php
	 *
	 * @param string $path
	 * @return string
	 */
	public function file_get_contents($path) {
		return $this->storage->file_get_contents($this->findPathToUse($path));
	}

	/**
	 * see http://php.net/manual/en/function.file_put_contents.php
	 *
	 * @param string $path
	 * @param string $data
	 * @return bool
	 */
	public function file_put_contents($path, $data) {
		return $this->storage->file_put_contents($this->findPathToUse($path), $data);
	}

	/**
	 * see http://php.net/manual/en/function.unlink.php
	 *
	 * @param string $path
	 * @return bool
	 */
	public function unlink($path) {
		$result = $this->storage->unlink($this->findPathToUse($path));
		if ($result) {
			unset($this->namesCache[$path]);
		}
		return $result;
	}

	/**
	 * see http://php.net/manual/en/function.rename.php
	 *
	 * @param string $path1
	 * @param string $path2
	 * @return bool
	 */
	public function rename($path1, $path2) {
		// second name always NFC
		$result = $this->storage->rename($this->findPathToUse($path1), $path2);
		if ($result) {
			unset($this->namesCache[$path1]);
		}
		return $result;
	}

	/**
	 * see http://php.net/manual/en/function.copy.php
	 *
	 * @param string $path1
	 * @param string $path2
	 * @return bool
	 */
	public function copy($path1, $path2) {
		return $this->storage->copy($this->findPathToUse($path1), $path2);
	}

	/**
	 * see http://php.net/manual/en/function.fopen.php
	 *
	 * @param string $path
	 * @param string $mode
	 * @return resource
	 */
	public function fopen($path, $mode) {
		return $this->storage->fopen($this->findPathToUse($path), $mode);
	}

	/**
	 * get the mimetype for a file or folder
	 * The mimetype for a folder is required to be "httpd/unix-directory"
	 *
	 * @param string $path
	 * @return string
	 */
	public function getMimeType($path) {
		return $this->storage->getMimeType($this->findPathToUse($path));
	}

	/**
	 * see http://php.net/manual/en/function.hash.php
	 *
	 * @param string $type
	 * @param string $path
	 * @param bool $raw
	 * @return string
	 */
	public function hash($type, $path, $raw = false) {
		return $this->storage->hash($type, $this->findPathToUse($path), $raw);
	}

	/**
	 * see http://php.net/manual/en/function.free_space.php
	 *
	 * @param string $path
	 * @return int
	 */
	public function free_space($path) {
		return $this->storage->free_space($this->findPathToUse($path));
	}

	/**
	 * search for occurrences of $query in file names
	 *
	 * @param string $query
	 * @return array
	 */
	public function search($query) {
		return $this->storage->search($query);
	}

	/**
	 * see http://php.net/manual/en/function.touch.php
	 * If the backend does not support the operation, false should be returned
	 *
	 * @param string $path
	 * @param int $mtime
	 * @return bool
	 */
	public function touch($path, $mtime = null) {
		return $this->storage->touch($this->findPathToUse($path), $mtime);
	}

	/**
	 * get the path to a local version of the file.
	 * The local version of the file can be temporary and doesn't have to be persistent across requests
	 *
	 * @param string $path
	 * @return string
	 */
	public function getLocalFile($path) {
		return $this->storage->getLocalFile($this->findPathToUse($path));
	}

	/**
	 * check if a file or folder has been updated since $time
	 *
	 * @param string $path
	 * @param int $time
	 * @return bool
	 *
	 * hasUpdated for folders should return at least true if a file inside the folder is add, removed or renamed.
	 * returning true for other changes in the folder is optional
	 */
	public function hasUpdated($path, $time) {
		return $this->storage->hasUpdated($this->findPathToUse($path), $time);
	}

	/**
	 * get a cache instance for the storage
	 *
	 * @param string $path
	 * @param \OC\Files\Storage\Storage (optional) the storage to pass to the cache
	 * @return \OC\Files\Cache\Cache
	 */
	public function getCache($path = '', $storage = null) {
		if (!$storage) {
			$storage = $this;
		}
		return $this->storage->getCache($this->findPathToUse($path), $storage);
	}

	/**
	 * get a scanner instance for the storage
	 *
	 * @param string $path
	 * @param \OC\Files\Storage\Storage (optional) the storage to pass to the scanner
	 * @return \OC\Files\Cache\Scanner
	 */
	public function getScanner($path = '', $storage = null) {
		if (!$storage) {
			$storage = $this;
		}
		return $this->storage->getScanner($this->findPathToUse($path), $storage);
	}

	/**
	 * get the ETag for a file or folder
	 *
	 * @param string $path
	 * @return string
	 */
	public function getETag($path) {
		return $this->storage->getETag($this->findPathToUse($path));
	}

	/**
	 * @param \OCP\Files\Storage $sourceStorage
	 * @param string $sourceInternalPath
	 * @param string $targetInternalPath
	 * @return bool
	 */
	public function copyFromStorage(\OCP\Files\Storage $sourceStorage, $sourceInternalPath, $targetInternalPath) {
		if ($sourceStorage === $this) {
			return $this->copy($this->findPathToUse($sourceInternalPath), $targetInternalPath);
		}

		return $this->storage->copyFromStorage($sourceStorage, $this->findPathToUse($sourceInternalPath), $targetInternalPath);
	}

	/**
	 * @param \OCP\Files\Storage $sourceStorage
	 * @param string $sourceInternalPath
	 * @param string $targetInternalPath
	 * @return bool
	 */
	public function moveFromStorage(\OCP\Files\Storage $sourceStorage, $sourceInternalPath, $targetInternalPath) {
		if ($sourceStorage === $this) {
			$result = $this->rename($this->findPathToUse($sourceInternalPath), $targetInternalPath);
			if ($result) {
				unset($this->namesCache[$sourceInternalPath]);
			}
			return $result;
		}

		$result = $this->storage->moveFromStorage($sourceStorage, $this->findPathToUse($sourceInternalPath), $targetInternalPath);
		if ($result) {
			unset($this->namesCache[$sourceInternalPath]);
		}
		return $result;
	}

	/**
	 * @param string $path
	 * @return array
	 */
	public function getMetaData($path) {
		return $this->storage->getMetaData($this->findPathToUse($path));
	}
}
