<?php

class LiamW_XenForoUpdater_Extend_ControllerAdmin_Tools extends XFCP_LiamW_XenForoUpdater_Extend_ControllerAdmin_Tools
{
	public function actionXenForoUpdate()
	{
		$this->_assertRootWritable();

		return $this->responseView('', 'liam_xenforo_update');
	}

	public function actionDoXenForoUpdateStep1()
	{
		$this->_assertPostOnly();
		$this->_assertRootWritable();

		$data = $this->_input->filter(array(
			'email' => XenForo_Input::STRING,
			'password' => XenForo_Input::STRING,
		));

		if (!$data['email'] || !$data['password'])
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_invalid_credentials'));
		}

		$client = XenForo_Helper_Http::getClient("https://xenforo.com/customers/login", array(
			'useragent' => 'XenForo CLI Updater (by Liam W)'
		));
		$client->setCookieJar();
		$client->setParameterPost('email', $data['email']);
		$client->setParameterPost('password', $data['password']);
		$client->setParameterPost('redirect', 'customers');

		$loginResponse = $client->request('POST');

		$domQuery = new Zend_Dom_Query($loginResponse->getBody());
		$licensesQuery = $domQuery->query('.licenses .license');

		$licenses = array();

		foreach ($licensesQuery as $license)
		{
			/** @var DOMElement $license */

			$downloadLink = $license->lastChild->firstChild->getElementsByTagName('a')
				->item(0)->attributes->getNamedItem('href')->textContent;

			// Download link in format: customers/download/?l=<license_id>&d=<product>
			if (!preg_match('#^.*\?l\=([A-Z0-9]+)\&.*$#', $downloadLink, $matches))
			{
				continue;
			}
			else
			{
				$licenseId = $matches[1];
			}

			$anchors = $license->childNodes->item(1)->childNodes->item(1)->getElementsByTagName('a');

			if (!$anchors->length)
			{
				continue;
			}

			$licenses[$licenseId] = $anchors->item(0)->nodeValue;
		}

		if (!count($licenses))
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_invalid_credentials_or_no_licenses'));
		}

		$viewParams = array(
			'licenses' => $licenses,
			'cookies' => $client->getCookieJar()->getAllCookies(Zend_Http_CookieJar::COOKIE_STRING_CONCAT)
		);

		return $this->responseView('', 'liam_xenforo_update_step1', $viewParams);
	}

	public function actionDoXenForoUpdateStep2()
	{
		$this->_assertPostOnly();
		$this->_assertRootWritable();

		$data = $this->_input->filter(array(
			'license_id' => XenForo_Input::STRING,
			'cookies' => XenForo_Input::STRING,
		));

		if (!$data['cookies'])
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_cookies_missing'));
		}

		if (!$data['license_id'])
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_please_select_license'));
		}

		$client = XenForo_Helper_Http::getClient("https://xenforo.com/customers/download/", array(
			'useragent' => 'XenForo CLI Updater (by Liam W)'
		));

		$cookieJar = new Zend_Http_CookieJar();
		$cookieJar->addCookie($data['cookies'], 'https://xenforo.com/customers');

		$client->setCookieJar($cookieJar);
		$client->setParameterGet('l', $data['license_id']);
		$client->setParameterGet('d', 'xenforo');

		$downloadForm = $client->request('GET');
		$downloadQuery = new Zend_Dom_Query($downloadForm->getBody());

		$versionsQuery = $downloadQuery->query('select[name~="download_version_id"] option');

		if (!$versionsQuery->count())
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_no_downloads'));
		}

		$downloadVersions = array();

		foreach ($versionsQuery as $version)
		{
			$downloadVersions[$version->getAttribute('value')] = array(
				'label' => $version->textContent,
				'selected' => $version->getAttribute('selected') == 'selected' && (substr(XenForo_Application::$versionId,
							5, 1) == 7 || substr(XenForo_Application::$versionId, 5, 1) == 9)
			);
		}

		krsort($downloadVersions);

		$viewParams = array(
			'versions' => $downloadVersions,
			'licenseId' => $data['license_id'],
			'cookies' => $client->getCookieJar()->getAllCookies(Zend_Http_CookieJar::COOKIE_STRING_CONCAT)
		);

		return $this->responseView('', 'liam_xenforo_update_step2', $viewParams);
	}

	public function actionDoXenForoUpdateStep3()
	{
		$this->_assertPostOnly();
		@set_time_limit(0);
		@ignore_user_abort(true);

		$data = $this->_input->filter(array(
			'download_version_id' => XenForo_Input::STRING,
			'license_id' => XenForo_Input::STRING,
			'cookies' => XenForo_Input::STRING,
		));

		if (!$data['cookies'])
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_cookies_missing'));
		}

		$client = XenForo_Helper_Http::getClient("https://xenforo.com/customers/download/", array(
			'useragent' => 'XenForo CLI Updater (by Liam W)'
		));

		$streamDir = XenForo_Helper_File::getInternalDataPath() . '/xf_update/';
		$streamFile = $streamDir . $data['download_version_id'] . '.zip';

		if (!XenForo_Helper_File::createDirectory($streamDir))
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_unable_to_create_directory'));
		}

		$cookieJar = new Zend_Http_CookieJar();
		$cookieJar->addCookie($data['cookies'], 'https://xenforo.com/customers');

		$client->setCookieJar($cookieJar);

		$client->setStream($streamFile);
		$client->setParameterPost('download_version_id', $data['download_version_id']);
		$client->setParameterPost('options[upgradePackage]', 1);
		$client->setParameterPost('agree', 1);
		$client->setParameterPost('l', $data['license_id']);
		$client->setParameterPost('d', 'xenforo');

		$client->request('POST');

		XenForo_Helper_File::createDirectory($streamDir . $data['download_version_id'] . '/');

		$zip = new Zend_Filter_Decompress(array(
			'adapter' => 'Zip',
			'options' => array(
				'target' => $streamDir . $data['download_version_id'] . '/'
			)
		));

		$zip->filter($streamFile);

		if (!is_dir($streamDir . $data['download_version_id'] . '/'))
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_unable_to_extract_zip'));
		}

		LiamW_XenForoUpdater_Helper::recursiveCopy($streamDir . $data['download_version_id'] . '/upload',
			XenForo_Application::getInstance()->getRootDir());

		return $this->responseRedirect(XenForo_ControllerResponse_Redirect::SUCCESS, '/install/index.php?upgrade/');
	}

	protected function _assertRootWritable()
	{
		if (!is_writable(XenForo_Application::getInstance()->getRootDir()))
		{
			throw $this->responseException($this->responseError(new XenForo_Phrase('liam_xenforoupdater_root_not_writable')));
		}
	}
}

if (false)
{
	class XFCP_LiamW_XenForoUpdater_Extend_ControllerAdmin_Tools extends XenForo_ControllerAdmin_Tools
	{
	}
}