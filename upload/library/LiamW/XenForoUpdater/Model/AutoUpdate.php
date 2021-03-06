<?php

class LiamW_XenForoUpdater_Model_AutoUpdate extends XenForo_Model
{
	const LOGIN_URL = 'https://xenforo.com/customers/login';
	const CUSTOMER_URL = 'https://xenforo.com/customers';
	const DOWNLOAD_URL = 'https://xenforo.com/customers/download';
	const CUSTOMER_REDIRECT = 'customers';

	const USER_AGENT = 'XenForo Updater (Liam W)';

	const PRODUCT_XENFORO = 'xenforo';
	const PRODUCT_RESOURCE_MANAGER = 'xfresource';
	const PRODUCT_MEDIA_GALLERY = 'xfmg';
	const PRODUCT_ENHANCED_SEARCH = 'xfes';

	const SESSION_COOKIE_JAR = 'xfUpdater_cookie_jar';

	public function getAvailableProducts()
	{
		$activeAddons = XenForo_Application::get('addOns');

		$availableProducts = [self::PRODUCT_XENFORO];

		if (isset($activeAddons['XenResource']))
		{
			$availableProducts[] = self::PRODUCT_RESOURCE_MANAGER;
		}
		if (isset($activeAddons['XenGallery']))
		{
			$availableProducts[] = self::PRODUCT_MEDIA_GALLERY;
		}
		if (isset($activeAddons['XenES']))
		{
			$availableProducts[] = self::PRODUCT_ENHANCED_SEARCH;
		}

		return $availableProducts;
	}

	public function getLicenses($email, $password, $product = self::PRODUCT_XENFORO, Zend_Http_CookieJar $cookies = null)
	{
		$client = $this->_getClient(self::LOGIN_URL);

		if ($cookies == null)
		{
			$cookies = new Zend_Http_CookieJar();
		}

		$client->setCookieJar($cookies);
		$client->setParameterPost('email', $email);
		$client->setParameterPost('password', $password);
		$client->setParameterPost('redirect', self::CUSTOMER_REDIRECT);

		$loginResponse = $client->request('POST');

		$domQuery = new Zend_Dom_Query($loginResponse->getBody());
		$licensesQuery = $domQuery->query('.licenses .license');

		$licenses = [];

		foreach ($licensesQuery as $license)
		{
			/** @var DOMElement $license */

			$licenseId = false;

			foreach ($license->getElementsByTagName('a') as $downloadLink)
			{
				$href = $downloadLink->attributes->getNamedItem('href')->textContent;

				// Download link in format: customers/download/?l=<license_id>&d=<product>
				$regex = sprintf('/^\/?customers\/download\/?\?l\=([A-Z0-9]+)\&d=%s$/', $product);
				if (preg_match($regex, $href, $matches))
				{
					$licenseId = $matches[1];
				}
			}

			if (!$licenseId)
			{
				// No license for requested product found on this license, continue onto next license.
				continue;
			}

			$anchors = $license->childNodes->item(1)->childNodes->item(1)->getElementsByTagName('a');

			if (!$anchors->length)
			{
				// License hasn't been named - isn't valid.
				continue;
			}

			$licenses[$licenseId] = $anchors->item(0)->nodeValue;
		}

		$this->_saveCookieJar($client->getCookieJar());

		return $licenses;
	}

	public function getVersions($licenseId, $product = self::PRODUCT_XENFORO)
	{
		$client = $this->_getClient(self::DOWNLOAD_URL);

		$client->setCookieJar($this->_getSavedCookieJar());
		$client->setParameterGet('l', $licenseId);
		$client->setParameterGet('d', $product);

		$downloadForm = $client->request('GET');
		$downloadQuery = new Zend_Dom_Query($downloadForm->getBody());

		$versionsQuery = $downloadQuery->query('select[name~="download_version_id"] option');

		if (!$versionsQuery->count())
		{
			return false;
		}

		$downloadVersions = [];

		foreach ($versionsQuery as $version)
		{
			$downloadVersions[$version->getAttribute('value')] = [
				'value' => $version->getAttribute('value'),
				'label' => $version->textContent,
				'selected' => $version->getAttribute('selected') == 'selected' && ($product != self::PRODUCT_XENFORO || (substr(XenForo_Application::$versionId,
								5, 1) == 7 || substr(XenForo_Application::$versionId, 5, 1) == 9))
			];
		}

		krsort($downloadVersions);

		return $downloadVersions;
	}

	public function downloadAndCopy($downloadVersionId, $licenseId, array $ftpData, &$error, $product = self::PRODUCT_XENFORO)
	{
		$client = $this->_getClient(self::DOWNLOAD_URL);

		$streamDir = XenForo_Helper_File::getInternalDataPath() . '/xf_update/';
		$streamFile = $streamDir . $downloadVersionId . '.zip';

		if (!XenForo_Helper_File::createDirectory($streamDir))
		{
			return false;
		}

		$client->setCookieJar($this->_getSavedCookieJar());
		$this->_removeSavedCookieJar();

		$client->setStream($streamFile);
		$client->setParameterPost('download_version_id', $downloadVersionId);
		if ($product == self::PRODUCT_XENFORO)
		{
			$client->setParameterPost('options[upgradePackage]', 1);
		}
		$client->setParameterPost('agree', 1);
		$client->setParameterPost('l', $licenseId);
		$client->setParameterPost('d', $product);

		try
		{
			$client->request('POST');
		} catch (Zend_Http_Exception $e)
		{
			XenForo_Error::logException($e, true, "Error downloading ZIP: ");
			$error = new XenForo_Phrase('liam_xenforoupdater_error_downloading_zip_check_error_log');

			return false;
		}

		XenForo_Helper_File::createDirectory($streamDir . $downloadVersionId . '/');

		try
		{
			$zip = new Zend_Filter_Decompress([
				'adapter' => 'Zip',
				'options' => [
					'target' => $streamDir . $downloadVersionId . '/'
				]
			]);

			$zip->filter($streamFile);
		} catch (Zend_Filter_Exception $e)
		{
			XenForo_Error::logException($e, true, "Error extracting ZIP ($streamFile): ");
			$error = new XenForo_Phrase('liam_xenforoupdater_error_extracting_zip_check_error_log');

			return false;
		}

		if (!is_dir($streamDir . $downloadVersionId . '/'))
		{
			$error = new XenForo_Phrase('liam_xenforoupdater_error_extracting_zip_check_error_log');

			return false;
		}

		if ($ftpData['ftp_upload'])
		{
			if (empty($ftpData['host']))
			{
				$ftpData['host'] = '127.0.0.1';
			}
			if (empty($ftpData['port']))
			{
				$ftpData['port'] = 21;
			}
			if (empty($ftpData['xf_path']))
			{
				$ftpData['xf_path'] = 'public_html';
			}

			try
			{
				$ftp = new LiamW_XenForoUpdater_FtpClient_FtpClient();
				$ftp->connect($ftpData['host'], $ftpData['ssl'], $ftpData['port']);
				$ftp->login($ftpData['user'], $ftpData['password']);

				$ftp->putAll($streamDir . $downloadVersionId . '/upload', $ftpData['xf_path']);
			} catch (Exception $e)
			{
				XenForo_Error::logException($e, true, "Error copying files via FTP: ");
				$error = new XenForo_Phrase('liam_xenforoupdater_error_copying_files_ftp_check_error_log');

				return false;
			}
		}
		else
		{
			try
			{
				LiamW_XenForoUpdater_Helper::recursiveCopy($streamDir . $downloadVersionId . '/upload',
					XenForo_Application::getInstance()->getRootDir());
			} catch (Exception $e)
			{
				XenForo_Error::logException($e, true, "Error copying files: ");
				$error = new XenForo_Phrase('liam_xenforoupdater_error_copying_files_check_error_log');

				return false;
			}
		}

		return true;
	}

	public function purgeAllData()
	{
		$updateDataPath = XenForo_Helper_File::getInternalDataPath() . '/xf_update/';

		if (file_exists($updateDataPath))
		{
			LiamW_XenForoUpdater_Helper::recursiveDelete($updateDataPath);
		}
	}

	public function purgeZips()
	{
		$updateDataPath = XenForo_Helper_File::getInternalDataPath() . '/xf_update/';

		if (file_exists($updateDataPath))
		{
			LiamW_XenForoUpdater_Helper::recursiveDelete($updateDataPath, ['zip']);
		}
	}

	public function purgeDirs()
	{
		$updateDataPath = XenForo_Helper_File::getInternalDataPath() . '/xf_update/';

		$dir = new DirectoryIterator($updateDataPath);
		foreach ($dir as $dirInfo)
		{
			if (!$dirInfo->isDot() && $dirInfo->isDir())
			{
				LiamW_XenForoUpdater_Helper::recursiveDelete($dirInfo->getRealPath());
			}
		}
	}

	protected function _getSavedCookieJar()
	{
		$session = XenForo_Application::getSession();

		if (!$session->isRegistered(self::SESSION_COOKIE_JAR))
		{
			throw new XenForo_Exception(new XenForo_Phrase('liam_xenforoupdater_cookie_jar_lost_start_again'), true);
		}

		return $session->get(self::SESSION_COOKIE_JAR);
	}

	protected function _saveCookieJar(Zend_Http_CookieJar $cookieJar)
	{
		XenForo_Application::getSession()->set(self::SESSION_COOKIE_JAR, $cookieJar);
	}

	protected function _removeSavedCookieJar()
	{
		XenForo_Application::getSession()->remove(self::SESSION_COOKIE_JAR);
	}

	protected function _getClient($url)
	{
		return XenForo_Helper_Http::getClient($url, [
			'timeout' => 5
		]);
	}
}