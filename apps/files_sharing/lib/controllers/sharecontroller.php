<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
 * @author Georg Ehrke <georg@owncloud.com>
 * @author Joas Schilling <nickvergessen@owncloud.com>
 * @author Lukas Reschke <lukas@owncloud.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Scrutinizer Auto-Fixer <auto-fixer@scrutinizer-ci.com>
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

namespace OCA\Files_Sharing\Controllers;

use OC;
use OC\Files\Filesystem;
use OC_Files;
use OC_Util;
use OCP;
use OCP\Template;
use OCP\Share;
use OCP\AppFramework\Controller;
use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUserManager;
use OCP\ISession;
use OCP\IPreview;
use OCA\Files_Sharing\Helper;
use OCP\Util;
use OCA\Files_Sharing\Activity;
use \OCP\Files\NotFoundException;
use \OC\Share20\IShare;
use OCP\Files\IRootFolder;

/**
 * Class ShareController
 *
 * @package OCA\Files_Sharing\Controllers
 */
class ShareController extends Controller {

	/** @var IConfig */
	protected $config;
	/** @var IURLGenerator */
	protected $urlGenerator;
	/** @var IUserManager */
	protected $userManager;
	/** @var ILogger */
	protected $logger;
	/** @var OCP\Activity\IManager */
	protected $activityManager;
	/** @var OC\Share20\Manager */
	protected $shareManager;
	/** @var ISession */
	protected $session;
	/** @var IPreview */
	protected $previewManager;
	/** @var IRootFolder */
	protected $rootFolder;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IConfig $config
	 * @param IURLGenerator $urlGenerator
	 * @param IUserManager $userManager
	 * @param ILogger $logger
	 * @param OCP\Activity\IManager $activityManager
	 * @param \OC\Share20\Manager $shareManager
	 * @param ISession $session
	 * @param IPreview $previewManager
	 */
	public function __construct($appName,
								IRequest $request,
								IConfig $config,
								IURLGenerator $urlGenerator,
								IUserManager $userManager,
								ILogger $logger,
								\OCP\Activity\IManager $activityManager,
								\OC\Share20\Manager $shareManager,
								ISession $session,
								IPreview $previewManager,
								IRootFolder $rootFolder) {
		parent::__construct($appName, $request);

		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->userManager = $userManager;
		$this->logger = $logger;
		$this->activityManager = $activityManager;
		$this->shareManager = $shareManager;
		$this->session = $session;
		$this->previewManager = $previewManager;
		$this->rootFolder = $rootFolder;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $token
	 * @return TemplateResponse|RedirectResponse
	 */
	public function showAuthenticate($token) {
		$share = $this->shareManager->getShareByToken($token);

		if($this->linkShareAuth($share)) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('files_sharing.sharecontroller.showShare', array('token' => $token)));
		}

		return new TemplateResponse($this->appName, 'authenticate', array(), 'guest');
	}

	/**
	 * @PublicPage
	 * @UseSession
	 *
	 * Authenticates against password-protected shares
	 * @param string $token
	 * @param string $password
	 * @return RedirectResponse|TemplateResponse
	 */
	public function authenticate($token, $password = '') {

		// Check whether share exists
		try {
			$share = $this->shareManager->getShareByToken($token);
		} catch (\OC\Share20\Exception\ShareNotFound $e) {
			return new NotFoundResponse();
		}

		$authenticate = $this->linkShareAuth($share, $password);

		if($authenticate === true) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('files_sharing.sharecontroller.showShare', array('token' => $token)));
		}

		return new TemplateResponse($this->appName, 'authenticate', array('wrongpw' => true), 'guest');
	}

	/**
	 * Authenticate a link item with the given password.
	 * Or use the session if no password is provided.
	 *
	 * This is a modified version of Helper::authenticate
	 * TODO: Try to merge back eventually with Helper::authenticate
	 *
	 * @param IShare $share
	 * @param string|null $password
	 * @return bool
	 */
	private function linkShareAuth(IShare $share, $password = null) {
		if ($password !== null) {
			if ($this->shareManager->checkPassword($share, $password)) {
				$this->session->set('public_link_authenticated', (string)$share->getId());
			} else {
				return false;
			}
		} else {
			// not authenticated ?
			if ( ! $this->session->exists('public_link_authenticated')
				|| $this->session->get('public_link_authenticated') !== (string)$share->getId()) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $token
	 * @param string $path
	 * @return TemplateResponse|RedirectResponse
	 * @throws NotFoundException
	 */
	public function showShare($token, $path = '') {
		\OC_User::setIncognitoMode(true);

		// Check whether share exists
		try {
			$share = $this->shareManager->getShareByToken($token);
		} catch (\OC\Share20\Exception\ShareNotFound $e) {
			return new NotFoundResponse();
		}

		// Share is password protected - check whether the user is permitted to access the share
		if ($share->getPassword() !== null && !$this->linkShareAuth($share)) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('files_sharing.sharecontroller.authenticate',
				array('token' => $token)));
		}

		// We can't get the path of a file share
		if ($share->getPath() instanceof \OCP\Files\File && $path !== '') {
			throw new NotFoundException();
		}

		$rootFolder = null;
		if ($share->getPath() instanceof \OCP\Files\Folder) {
			/** @var \OCP\Files\Folder $rootFolder */
			$rootFolder = $share->getPath();

			try {
				$path = $rootFolder->get($path);
			} catch (\OCP\Files\NotFoundException $e) {
				throw new NotFoundException();
			}
		}

		$shareTmpl = [];
		$shareTmpl['displayName'] = $share->getShareOwner()->getDisplayName();
		$shareTmpl['owner'] = $share->getShareOwner()->getUID();
		$shareTmpl['filename'] = $share->getPath()->getName();
		$shareTmpl['directory_path'] = $share->getTarget();
		$shareTmpl['mimetype'] = $share->getPath()->getMimetype();
		$shareTmpl['previewSupported'] = $this->previewManager->isMimeSupported($share->getPath()->getMimetype());
		$shareTmpl['dirToken'] = $token;
		$shareTmpl['sharingToken'] = $token;
		$shareTmpl['server2serversharing'] = Helper::isOutgoingServer2serverShareEnabled();
		$shareTmpl['protected'] = $share->getPassword() !== null ? 'true' : 'false';
		$shareTmpl['dir'] = '';
		$shareTmpl['nonHumanFileSize'] = $share->getPath()->getSize();
		$shareTmpl['fileSize'] = \OCP\Util::humanFileSize($share->getPath()->getSize());

		// Show file list
		if ($share->getPath() instanceof \OCP\Files\Folder) {
			$shareTmpl['dir'] = $rootFolder->getRelativePath($path->getPath());

			/*
			 * The OC_Util methods require a view. This just uses the node API
			 */
			$freeSpace = $share->getPath()->getStorage()->free_space($share->getPath()->getInternalPath());
			if ($freeSpace !== \OCP\Files\FileInfo::SPACE_UNKNOWN) {
				$freeSpace = max($freeSpace, 0);
			} else {
				$freeSpace = (INF > 0) ? INF: PHP_INT_MAX; // work around https://bugs.php.net/bug.php?id=69188
			}

			$uploadLimit = Util::uploadLimit();
			$maxUploadFilesize = min($freeSpace, $uploadLimit);

			$folder = new Template('files', 'list', '');
			$folder->assign('dir', $rootFolder->getRelativePath($path->getPath()));
			$folder->assign('dirToken', $token);
			$folder->assign('permissions', \OCP\Constants::PERMISSION_READ);
			$folder->assign('isPublic', true);
			$folder->assign('publicUploadEnabled', 'no');
			$folder->assign('uploadMaxFilesize', $maxUploadFilesize);
			$folder->assign('uploadMaxHumanFilesize', OCP\Util::humanFileSize($maxUploadFilesize));
			$folder->assign('freeSpace', $freeSpace);
			$folder->assign('uploadLimit', $uploadLimit); // PHP upload limit
			$folder->assign('usedSpacePercent', 0);
			$folder->assign('trash', false);
			$shareTmpl['folder'] = $folder->fetchPage();
		}

		$shareTmpl['downloadURL'] = $this->urlGenerator->linkToRouteAbsolute('files_sharing.sharecontroller.downloadShare', array('token' => $token));
		$shareTmpl['maxSizeAnimateGif'] = $this->config->getSystemValue('max_filesize_animated_gifs_public_sharing', 10);
		$shareTmpl['previewEnabled'] = $this->config->getSystemValue('enable_previews', true);

		$csp = new OCP\AppFramework\Http\ContentSecurityPolicy();
		$csp->addAllowedFrameDomain('\'self\'');
		$response = new TemplateResponse($this->appName, 'public', $shareTmpl, 'base');
		$response->setContentSecurityPolicy($csp);

		return $response;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $token
	 * @param string $files
	 * @param string $path
	 * @param string $downloadStartSecret
	 * @return void|RedirectResponse
	 */
	public function downloadShare($token, $files = null, $path = '', $downloadStartSecret = '') {
		\OC_User::setIncognitoMode(true);

		$share = $this->shareManager->getShareByToken($token);

		// Share is password protected - check whether the user is permitted to access the share
		if ($share->getPassword() !== null && !$this->linkShareAuth($share)) {
			return new RedirectResponse($this->urlGenerator->linkToRoute('files_sharing.sharecontroller.authenticate',
				['token' => $token]));
		}

		$files_list = null;
		if (!is_null($files)) { // download selected files
			$files_list = json_decode($files);
			// in case we get only a single file
			if ($files_list === null) {
				$files_list = [$files];
			}
		}

		$userFolder = $this->rootFolder->getUserFolder($share->getShareOwner()->getUID());
		$originalSharePath = $userFolder->getRelativePath($share->getPath()->getPath());

		// Single file share
		if ($share->getPath() instanceof \OCP\Files\File) {
			$this->activityManager->publishActivity(
				'files_sharing', Activity::SUBJECT_PUBLIC_SHARED_FILE_DOWNLOADED, [$originalSharePath], '', [],
				$originalSharePath, '', $share->getShareOwner()->getUID(), Activity::TYPE_PUBLIC_LINKS, Activity::PRIORITY_MEDIUM
			);
		}
		// Directory share
		else {
			/** @var \OCP\Files\Folder $node */
			$node = $share->getPath();

			// Try to get the path
			if ($path !== '') {
				try {
					$node = $node->get($path);
				} catch (NotFoundException $e) {
					return new NotFoundResponse();
				}
			}

			$originalSharePath = $userFolder->getRelativePath($node->getPath());

			if ($node instanceof \OCP\Files\File) {
				// Single file download
				$this->activityManager->publishActivity(
					'files_sharing', Activity::SUBJECT_PUBLIC_SHARED_FILE_DOWNLOADED, [$originalSharePath], '', [],
					$originalSharePath, '', $share->getShareOwner()->getUID(), Activity::TYPE_PUBLIC_LINKS, Activity::PRIORITY_MEDIUM
				);
			} else if (!empty($files_list)) {
				/** @var \OCP\Files\Folder $node */

				// Subset of files is downloaded
				foreach ($files_list as $file) {
					$subNode = $node->get($file);
					$nodePath = $userFolder->getRelativePath($subNode->getPath());
					if ($subNode instanceof \OCP\Files\File) {
						$this->activityManager->publishActivity(
							'files_sharing', Activity::SUBJECT_PUBLIC_SHARED_FILE_DOWNLOADED, [$nodePath], '', [],
							$nodePath, '', $share->getShareOwner()->getUID(), Activity::TYPE_PUBLIC_LINKS, Activity::PRIORITY_MEDIUM
						);
					} else {
						$this->activityManager->publishActivity(
							'files_sharing', Activity::SUBJECT_PUBLIC_SHARED_FOLDER_DOWNLOADED, [$nodePath], '', [],
							$nodePath, '', $share->getShareOwner()->getUID(), Activity::TYPE_PUBLIC_LINKS, Activity::PRIORITY_MEDIUM
						);
					}
				}
			} else {
				// The folder is downloaded
				$this->activityManager->publishActivity(
					'files_sharing', Activity::SUBJECT_PUBLIC_SHARED_FOLDER_DOWNLOADED, [$originalSharePath], '', [],
					$originalSharePath, '', $share->getShareOwner()->getUID(), Activity::TYPE_PUBLIC_LINKS, Activity::PRIORITY_MEDIUM
				);
			}
		}

		/* FIXME: We should do this all nicely in OCP */
		OC_Util::tearDownFS();
		OC_Util::setupFS($share->getShareOwner()->getUID());

		/**
		 * this sets a cookie to be able to recognize the start of the download
		 * the content must not be longer than 32 characters and must only contain
		 * alphanumeric characters
		 */
		if (!empty($downloadStartSecret)
			&& !isset($downloadStartSecret[32])
			&& preg_match('!^[a-zA-Z0-9]+$!', $downloadStartSecret) === 1) {

			// FIXME: set on the response once we use an actual app framework response
			setcookie('ocDownloadStarted', $downloadStartSecret, time() + 20, '/');
		}

		// download selected files
		if (!is_null($files)) {
			// FIXME: The exit is required here because otherwise the AppFramework is trying to add headers as well
			// after dispatching the request which results in a "Cannot modify header information" notice.
			OC_Files::get($originalSharePath, $files_list, $_SERVER['REQUEST_METHOD'] == 'HEAD');
			exit();
		} else {
			// FIXME: The exit is required here because otherwise the AppFramework is trying to add headers as well
			// after dispatching the request which results in a "Cannot modify header information" notice.
			OC_Files::get(dirname($originalSharePath), basename($originalSharePath), $_SERVER['REQUEST_METHOD'] == 'HEAD');
			exit();
		}
	}
}
