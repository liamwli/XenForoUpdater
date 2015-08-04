<?php

class LiamW_XenForoUpdater_Extend_ControllerAdmin_Tools extends XFCP_LiamW_XenForoUpdater_Extend_ControllerAdmin_Tools2
{
	public function actionUpdate()
	{
		return $this->responseView('', 'liam_xenforo_update_initial');
	}

	public function actionUpdateStepProduct()
	{
		$this->_assertPostOnly();

		if (!$this->isConfirmedPost())
		{
			return $this->responseNoPermission();
		}

		$availableProducts = $this->_getAutomaticUpdateModel()
			->getAvailableProducts();

		$availableProductsReturn = array();
		foreach ($availableProducts as $availableProduct)
		{
			$availableProductsReturn[$availableProduct] = new XenForo_Phrase('liam_xenforoupdater_update_' . $availableProduct);
		}

		$viewParams = array(
			'availableProducts' => $availableProducts,
		);

		return $this->responseView('', 'liam_xenforo_update_product', $viewParams);
	}

	public function actionUpdateStepCredentials()
	{
		$this->_assertPostOnly();

		if (!$this->isConfirmedPost())
		{
			return $this->responseNoPermission();
		}

		return $this->responseView('', 'liam_xenforo_update_credentials');
	}

	public function actionUpdateStepLicense()
	{
		$this->_assertPostOnly();

		if (!$this->isConfirmedPost())
		{
			return $this->responseNoPermission();
		}

		$data = $this->_input->filter(array(
			'email' => XenForo_Input::STRING,
			'password' => XenForo_Input::STRING,
			'product' => XenForo_Input::STRING
		));

		if (!$data['email'] || !$data['password'])
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_invalid_credentials'));
		}

		$licenses = $this->_getAutomaticUpdateModel()
			->getLicenses($data['email'], $data['password'], $cookies, $data['product']);

		if (!$licenses)
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_invalid_credentials_or_no_licenses'));
		}

		$viewParams = array(
			'licenses' => $licenses,
			'cookies' => $cookies,
			'product' => $data['product']
		);

		return $this->responseView('', 'liam_xenforo_update_licenses', $viewParams);
	}

	public function actionUpdateStepVersion()
	{
		$this->_assertPostOnly();

		if (!$this->isConfirmedPost())
		{
			return $this->responseNoPermission();
		}

		$data = $this->_input->filter(array(
			'license_id' => XenForo_Input::STRING,
			'cookies' => XenForo_Input::STRING,
			'product' => XenForo_Input::STRING
		));

		if (!$data['cookies'])
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_cookies_missing'));
		}

		if (!$data['license_id'])
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_must_select_license'));
		}

		$downloadVersions = $this->_getAutomaticUpdateModel()
			->getVersions($data['cookies'], $data['license_id'], $data['product']);

		$viewParams = array(
			'versions' => $downloadVersions,
			'licenseId' => $data['license_id'],
			'cookies' => $data['cookies'],
			'product' => $data['product']
		);

		return $this->responseView('', 'liam_xenforo_update_version', $viewParams);
	}

	public function actionUpdateStepUpdate()
	{
		$this->_assertPostOnly();

		if (!$this->isConfirmedPost())
		{
			return $this->responseNoPermission();
		}

		@set_time_limit(0);
		@ignore_user_abort(true);

		$data = $this->_input->filter(array(
			'download_version_id' => XenForo_Input::STRING,
			'license_id' => XenForo_Input::STRING,
			'cookies' => XenForo_Input::STRING,
			'product' => XenForo_Input::STRING
		));

		if (!$data['license_id'])
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_must_select_license'));
		}

		if (!$data['cookies'])
		{
			return $this->responseError(new XenForo_Phrase('liam_xenforoupdater_cookies_missing'));
		}

		$ftpData = $this->_input->filter(array(
			'ftp_upload' => XenForo_Input::BOOLEAN,
			'host' => XenForo_Input::STRING,
			'port' => XenForo_Input::INT,
			'user' => XenForo_Input::STRING,
			'password' => XenForo_Input::STRING,
			'ssl' => XenForo_Input::BOOLEAN,
			'xf_path' => XenForo_Input::STRING
		));

		$this->_getAutomaticUpdateModel()
			->downloadAndCopy($data['cookies'], $data['download_version_id'], $data['license_id'], $ftpData,
				$data['product']);

		switch ($data['product'])
		{
			case LiamW_XenForoUpdater_Model_AutoUpdate::PRODUCT_XENFORO:
				return $this->responseRedirect(XenForo_ControllerResponse_Redirect::SUCCESS,
					'/install/index.php?upgrade/');
				break;
			case LiamW_XenForoUpdater_Model_AutoUpdate::PRODUCT_RESOURCE_MANAGER:
				$this->_request->setParam('addon_id', 'XenResource');
				$this->_request->setParam('server_file',
					realpath(XenForo_Application::getInstance()
							->getRootDir() . '/library/XenResource/addon-XenResource.xml'));
				break;
			case LiamW_XenForoUpdater_Model_AutoUpdate::PRODUCT_MEDIA_GALLERY:
				$this->_request->setParam('addon_id', 'XenGallery');
				$this->_request->setParam('server_file',
					realpath(XenForo_Application::getInstance()
							->getRootDir() . '/library/XenGallery/addon-XenGallery.xml'));
				break;
			case LiamW_XenForoUpdater_Model_AutoUpdate::PRODUCT_ENHANCED_SEARCH:
				$this->_request->setParam('addon_id', 'XenES');
				$this->_request->setParam('server_file',
					realpath(XenForo_Application::getInstance()->getRootDir() . '/library/XenES/addon-XenES.xml'));
				break;
		}

		return $this->responseReroute('XenForo_ControllerAdmin_AddOn',
			'Upgrade'); // Use the default add-on upgrade system for the rest.
	}

	/**
	 * @return LiamW_XenForoUpdater_Model_AutoUpdate
	 */
	protected function _getAutomaticUpdateModel()
	{
		return $this->getModelFromCache('LiamW_XenForoUpdater_Model_AutoUpdate');
	}
}

if (false)
{
	class XFCP_LiamW_XenForoUpdater_Extend_ControllerAdmin_Tools2 extends XenForo_ControllerAdmin_Tools
	{
	}
}