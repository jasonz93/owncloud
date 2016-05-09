<?php
/**
 * Created by PhpStorm.
 * User: nicholas
 * Date: 16-5-9
 * Time: 下午4:43
 */

namespace OC\Files\Storage;

use Icewind\Streams\IteratorDirectory;
use OSS\Model\ObjectInfo;
use OSS\OssClient;

set_include_path(get_include_path() . PATH_SEPARATOR .
	\OC_App::getAppPath('files_external') . '/3rdparty/aliyun-oss-php-sdk');
require 'autoload.php';

class AliyunOSS extends Common{

	/**
	 * @var OssClient
	 */
	private $client = null;

	private $accessKeyId;
	private $accessKeySecret;
	private $endpoint;
	private $bucket;

	private static $tmpFiles = [];

	public function __construct($parameters) {
		if (empty($parameters['key']) || empty($parameters['secret']) || empty($parameters['bucket'])) {
			throw new \Exception("Access Key, Secret and Bucket have to be configured.");
		}

		$this->bucket = $parameters['bucket'];
		$this->endpoint = $parameters['endpoint'];
		$this->accessKeyId = $parameters['key'];
		$this->accessKeySecret = $parameters['secret'];
	}

	public function getClient() {
		if (!is_null($this->client)) {
			return $this->client;
		}

		$this->client = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);
		if (!$this->client->doesBucketExist($this->bucket)) {
			throw new \Exception('Aliyun OSS Bucket does not exist.');
		}

		return $this->client;
	}

	private function normalizePath($path) {
		$path = trim($path, '/');

		if (!$path) {
			$path = '.';
		}

		return $path;
	}

	private function isRoot($path) {
		return $path === '.';
	}

	private function cleanKey($path) {
		if ($this->isRoot($path)) {
			return '/';
		}
		return $path;
	}

	private function clearBuctet() {
		return $this->batchDelete();
	}

	private function batchDelete($path = null) {
		$params = [
			OssClient::OSS_MAX_KEYS => 1000
		];
		if ($path !== null) {
			$params[OssClient::OSS_PREFIX] = $path.'/';
		}
		try {
			do {
				$objs = $this->getClient()->listObjects($this->bucket, $params);
				$keys = [];
				foreach ($objs->getObjectList() as $obj) {
					$keys[] = $obj->getKey();
				}
				$this->getClient()->deleteObjects($this->bucket, $keys);
			} while (!$objs->getIsTruncated());
		} catch (\Exception $e) {
			\OCP\Util::logException('files_external', $e);
			return false;
		}
		return true;

	}

	/**
	 * Get the identifier for the storage,
	 * the returned id should be the same for every storage object that is created with the same parameters
	 * and two storage objects with the same id should refer to two storages that display the same files.
	 *
	 * @return string
	 * @since 6.0.0
	 */
	public function getId() {
		return 'aliyunoss::'.$this->bucket;
	}

	/**
	 * see http://php.net/manual/en/function.mkdir.php
	 * implementations need to implement a recursive mkdir
	 *
	 * @param string $path
	 * @return bool
	 * @since 6.0.0
	 */
	public function mkdir($path) {
		$path = $this->normalizePath($path);

		if ($this->is_dir($path)) {
			return false;
		}

		try {
			$this->getClient()->putObject($this->bucket, $path.'/', '', [
				OssClient::OSS_CONTENT_TYPE => 'httpd/unix-directory'
			]);
		} catch (\Exception $e) {
			\OCP\Util::logException('files_external', $e);
			return false;
		}

		return true;
	}

	/**
	 * see http://php.net/manual/en/function.rmdir.php
	 *
	 * @param string $path
	 * @return bool
	 * @since 6.0.0
	 */
	public function rmdir($path) {
		if ($this->isRoot($path)) {
			return $this->clearBuctet();
		}

		if (!$this->file_exists($path)) {
			return false;
		}

		return $this->batchDelete($path);
	}

	/**
	 * see http://php.net/manual/en/function.opendir.php
	 *
	 * @param string $path
	 * @return resource|false
	 * @since 6.0.0
	 */
	public function opendir($path) {
		$path = $this->normalizePath($path);

		if ($this->isRoot($path)) {
			$path = '';
		} else {
			$path .= '/';
		}

		try {
			$files = array();
			$result = $this->getClient()->listObjects($this->bucket, [
				OssClient::OSS_DELIMITER => '/',
				OssClient::OSS_PREFIX => $path
			]);
			$objects = $result->getObjectList();
			$prefixs = $result->getPrefixList();
			foreach ($objects as $index => $object) {
				if ($object->getKey() !== '' && $object->getKey() === $path) {
					continue;
				}
				$file = basename(
					$object->getKey() === '' ? $prefixs[$index]->getPrefix() : $object->getKey()
				);
				$files[] = $file;
			}

			return IteratorDirectory::wrap($files);
		} catch (\Exception $e) {
			\OCP\Util::logException('files_external', $e);
			return false;
		}
	}

	/**
	 * see http://php.net/manual/en/function.stat.php
	 * only the following keys are required in the result: size and mtime
	 *
	 * @param string $path
	 * @return array|false
	 * @since 6.0.0
	 */
	public function stat($path) {
		$path = $this->normalizePath($path);

		try {
			$stat = array();
			if ($this->is_dir($path)) {
				//folders don't really exist
				$stat['size'] = -1; //unknown
				$stat['mtime'] = time();
			} else {
				$result = $this->getClient()->getObjectMeta($this->bucket, $path);

				$stat['size'] = $result['ContentLength'] ? $result['ContentLength'] : 0;
				if ($result['Metadata']['lastmodified']) {
					$stat['mtime'] = strtotime($result['Metadata']['lastmodified']);
				} else {
					$stat['mtime'] = strtotime($result['LastModified']);
				}
			}
			$stat['atime'] = time();

			return $stat;
		} catch(\Exception $e) {
			\OCP\Util::logException('files_external', $e);
			return false;
		}
	}

	/**
	 * see http://php.net/manual/en/function.filetype.php
	 *
	 * @param string $path
	 * @return string|false
	 * @since 6.0.0
	 */
	public function filetype($path) {
		$path = $this->normalizePath($path);

		if ($this->isRoot($path)) {
			return 'dir';
		}

		try {
			if ($this->getClient()->doesObjectExist($this->bucket, $path)) {
				return 'file';
			}
			if ($this->getClient()->doesObjectExist($this->bucket, $path.'/')) {
				return 'dir';
			}
		} catch (\Exception $e) {
			\OCP\Util::logException('files_external', $e);
			return false;
		}

		return false;
	}

	/**
	 * see http://php.net/manual/en/function.file_exists.php
	 *
	 * @param string $path
	 * @return bool
	 * @since 6.0.0
	 */
	public function file_exists($path) {
		return $this->filetype($path) !== false;
	}

	/**
	 * see http://php.net/manual/en/function.unlink.php
	 *
	 * @param string $path
	 * @return bool
	 * @since 6.0.0
	 */
	public function unlink($path) {
		$path = $this->normalizePath($path);

		if ($this->is_dir($path)) {
			return $this->rmdir($path);
		}

		try {
			$this->getClient()->deleteObject($this->bucket, $path);
		} catch (\Exception $e) {
			\OCP\Util::logException('files_external', $e);
			return false;
		}

		return true;
	}

	/**
	 * see http://php.net/manual/en/function.fopen.php
	 *
	 * @param string $path
	 * @param string $mode
	 * @return resource|false
	 * @since 6.0.0
	 */
	public function fopen($path, $mode) {
		$path = $this->normalizePath($path);

		switch ($mode) {
			case 'r':
			case 'rb':
				$tmpFile = \OCP\Files::tmpFile();
				self::$tmpFiles[$tmpFile] = $path;

				try {
					$content = $this->getClient()->getObject($this->bucket, $path);
					file_put_contents($tmpFile, $content);
				} catch (\Exception $e) {
					\OCP\Util::logException('files_external', $e);
					return false;
				}

				return fopen($tmpFile, 'r');
			case 'w':
			case 'wb':
			case 'a':
			case 'ab':
			case 'r+':
			case 'w+':
			case 'wb+':
			case 'a+':
			case 'x':
			case 'x+':
			case 'c':
			case 'c+':
				if (strrpos($path, '.') !== false) {
					$ext = substr($path, strrpos($path, '.'));
				} else {
					$ext = '';
				}
				$tmpFile = \OCP\Files::tmpFile($ext);
				\OC\Files\Stream\Close::registerCallback($tmpFile, array($this, 'writeBack'));
				if ($this->file_exists($path)) {
					$source = $this->fopen($path, 'r');
					file_put_contents($tmpFile, $source);
				}
				self::$tmpFiles[$tmpFile] = $path;

				return fopen('close://' . $tmpFile, $mode);
		}
		return false;
	}

	/**
	 * see http://php.net/manual/en/function.touch.php
	 * If the backend does not support the operation, false should be returned
	 *
	 * @param string $path
	 * @param int $mtime
	 * @return bool
	 * @since 6.0.0
	 */
	public function touch($path, $mtime = null) {
		$path = $this->normalizePath($path);

		$fileType = $this->filetype($path);
		try {
			if ($fileType !== false) {
				if ($fileType === 'dir' && ! $this->isRoot($path)) {
					$path .= '/';
				}
				$this->getClient()->copyObject($this->bucket, $this->bucket.'/'.$path, $this->bucket, $this->cleanKey($path));
			} else {
				$mimeType = \OC::$server->getMimeTypeDetector()->detectPath($path);
				$this->getClient()->putObject($this->bucket, $path, '', [
					OssClient::OSS_CONTENT_TYPE => $mimeType
				]);
			}
		} catch (\Exception $e) {
			\OCP\Util::logException('files_external', $e);
			return false;
		}

		return true;
	}
}