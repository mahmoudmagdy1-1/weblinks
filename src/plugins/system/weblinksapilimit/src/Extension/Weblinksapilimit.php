<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  Weblinks
 *
 * @copyright   Copyright (C) 2005 - ##DATE## Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\System\Weblinks\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Rate limiting system plugin for Joomla Web Links.
 *
 * @since  __DEPLOY_VERSION__
 */
final class Weblinksapilimit extends CMSPlugin
{
    public function onAfterApiRoute()
    {
        $app = Factory::getApplication();
        $uri = $app->getInput()->server->get('REQUEST_URI', '', 'string');

        // Only apply to weblinks-related API requests for guest users
        if (
            strpos($uri, '/weblinks') !== false
            &&
            true !== $app->login(credentials: ['username' => ''], options: ['silent' => true, 'action' => 'core.login.api'])
        ) {
            $ip = $_SERVER['REMOTE_ADDR'];
            $limit = $this->params->get('limit', 2);
            $window = $this->params->get('window', 180);

            $config  = Factory::getApplication()->getConfig();
            $caching = (int) $config->get('caching', 0);

            if ($caching === 0) {
                // Non-persistent (file-based) caching
                $this->applyNonPersistentRateLimit($ip, $limit, $window);
            } else {
                // Persistent caching
                $this->applyPersistentRateLimit($ip, $limit, $window);
            }
        }
    }

    /**
     * Handles rate limiting with non-persistent (file-based) caching.
     */
    private function applyNonPersistentRateLimit(string $userIp, int $maxRequests, int $windowSeconds): void
    {
        $storageDir = JPATH_ROOT . '/tmp/api_rate_limit/';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $file = $storageDir . md5($userIp) . '.json';

        // Load or initialize rate data
        if (file_exists($file)) {
            $rateData = json_decode(file_get_contents($file), true);
            if (!is_array($rateData)) {
                $rateData = ['count' => 0, 'start' => time()];
            }
        } else {
            $rateData = ['count' => 0, 'start' => time()];
        }

        // Reset rate data if the time window has passed
        if (time() - $rateData['start'] > $windowSeconds) {
            $rateData = ['count' => 0, 'start' => time()];
        }

        // Increment the request count
        $rateData['count']++;

        // Check if the rate limit is exceeded
        if ($rateData['count'] > $maxRequests) {
            $this->handleRateLimitExceeded();
        }

        // Save the updated rate data
        file_put_contents($file, json_encode($rateData));
    }

    /**
     * Handles rate limiting with persistent caching.
     */
    private function applyPersistentRateLimit(string $userIp, int $maxRequests, int $windowSeconds): void
    {
        // Use Joomla cache if persistent
        $cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class)
            ->createCacheController('output', [
                'defaultgroup' => 'plg_system_weblinksapilimit',
                'lifetime'     => $windowSeconds,
            ]);

        $cacheKey = md5('api_rate_' . $userIp);

        // Load or initialize rate data
        $rateData = $cache->get($cacheKey);
        if (!$rateData) {
            $rateData = ['count' => 0, 'start' => time()];
        }

        // Reset rate data if the time window has passed
        if (time() - $rateData['start'] > $windowSeconds) {
            $rateData = ['count' => 0, 'start' => time()];
        }

        // Increment the request count
        $rateData['count']++;

        // Check if the rate limit is exceeded
        if ($rateData['count'] > $maxRequests) {
            $this->handleRateLimitExceeded();
        }

        // Save the updated rate data
        $cache->store($rateData, $cacheKey);
    }

    /**
     * Handles the scenario when the rate limit is exceeded.
     */
    private function handleRateLimitExceeded(): void
    {
        // Customize the behavior here (e.g., log the event, return a response, etc.)
        http_response_code(429); // HTTP 429 Too Many Requests
        echo json_encode([
            'errors' => [
                [
                    'title' => 'Rate limit exceeded',
                    'code'  => 429,
                ],
            ],
        ]);

        // Stop further execution
        exit;
    }
}
