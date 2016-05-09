<?php
/**
 * Created by PhpStorm.
 * User: nicholas
 * Date: 16-5-9
 * Time: 下午5:40
 */

namespace OCA\Files_External\Lib\Backend;


use OCA\Files_External\Lib\Auth\AmazonS3\AccessKey;
use OCA\Files_External\Lib\DefinitionParameter;
use OCA\Files_External\Lib\LegacyDependencyCheckPolyfill;
use OCP\IL10N;

class AliyunOSS extends Backend {
	use LegacyDependencyCheckPolyfill;

	public function __construct(IL10N $l, AccessKey $legacyAuth) {
		$this
			->setIdentifier('aliyunoss')
			->addIdentifierAlias('\OC\Files\Storage\AliyunOSS') // legacy compat
			->setStorageClass('\OC\Files\Storage\AliyunOSS')
			->setText($l->t('Aliyun OSS'))
			->addParameters([
				(new DefinitionParameter('bucket', $l->t('Bucket'))),
				(new DefinitionParameter('endpoint', $l->t('Endpoint')))
			])
			->addAuthScheme(AccessKey::SCHEME_AMAZONS3_ACCESSKEY)
			->setLegacyAuthMechanism($legacyAuth)
		;
	}
}