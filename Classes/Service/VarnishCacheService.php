<?php
/* * *************************************************************
 *  Copyright notice
 *
 *  (C) 2015 Mittwald CM Service GmbH & Co. KG <opensource@mittwald.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 * ************************************************************* */

namespace Mittwald\Varnishcache\Service;


use Mittwald\Varnishcache\Domain\Model\Server;
use Mittwald\Varnishcache\Domain\Model\SysDomain;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class VarnishCacheService {

    /**
     * @var \Mittwald\Varnishcache\Service\FrontendUrlGenerator
     * @inject
     */
    protected $frontendUrlGenerator;

    /**
     * @var \Mittwald\Varnishcache\Domain\Repository\SysDomainRepository
     * @inject
     */
    protected $domainRepository;

    /**
     * Flush cache for every found domain and given varnish server
     *
     * @param $currentPageId
     * @return bool
     */
    public function flushCache($currentPageId) {

        $url = $this->frontendUrlGenerator->getFrontendUrl($currentPageId);

        if ($currentPageId > 0) {
            $domains = $this->domainRepository->findByRootLine(BackendUtility::BEgetRootLine($currentPageId));
        } else {
            $domains = $this->domainRepository->findAll();
        }

        if ($domains) {
            /* @var $domain SysDomain */
            foreach ($domains as $domain) {
                if ($servers = $domain->getServers()) {
                    /* @var $server Server */
                    foreach ($servers as $server) {
                        $this->request($domain, $server, $url);
                    }


                }
            }
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Send curl request
     *
     * @param SysDomain $domain
     * @param Server $server
     * @param $frontendUrl
     * @return mixed
     */
    protected function request(SysDomain $domain, Server $server, $frontendUrl) {

        if (!function_exists('curl_version')) {
            throw new \BadFunctionCallException('Curl is required but not loaded', '1444895510');
        }

        if ($server->isStripSlashes()) {
            $frontendUrl = rtrim($frontendUrl, '/');
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_NOBODY, 1);
        curl_setopt($curl, CURLOPT_PORT, $server->getPort());
        curl_setopt($curl, CURLOPT_URL, $server->getRequestUrlByFrontendUrl($frontendUrl));
        curl_setopt($curl, CURLOPT_HTTP_VERSION, ($server->getProtocol() == 1) ? CURL_HTTP_VERSION_1_0 : CURL_HTTP_VERSION_1_1);

        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $server->getMethod());

        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'X-Host: ' . $domain->getDomainName(),
                'X-Url: ' . $frontendUrl,
        ));
        curl_setopt($curl, CURLINFO_HEADER_OUT, 1);
        $header = curl_exec($curl);
        curl_close($curl);

        $this->getBackendUser()->writelog(3, 1, 0, 0, 'User %s has cleared the varnishcache (server=%s,host=%s, url=%s)', array($this->getBackendUser()->user['username'], $server->getIp(), $domain->getDomainName(), $frontendUrl));

        return $header;

    }


    /**
     * @return BackendUserAuthentication
     */
    protected function getBackendUser() {
        return $GLOBALS['BE_USER'];
    }
}
