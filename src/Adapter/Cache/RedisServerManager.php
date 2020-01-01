<?php
/**
 * 2007-2019 PrestaShop SA and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

namespace PrestaShop\PrestaShop\Adapter\Cache;

use Doctrine\DBAL\Connection;
use redis;

/**
 * This class manages Memcache(d) servers in "Configure > Advanced Parameters > Performance" page.
 */
class RedisServerManager
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var string
     */
    private $tableName;

    public function __construct(Connection $connection, $dbPrefix)
    {
        $this->connection = $connection;
        $this->tableName = $dbPrefix . 'redis_servers';
    }

    /**
     * Add a memcache server.
     *
     * @param string $serverIp
     * @param int $serverPort
     * @param int $serverWeight
     * @param int $serverBase
     */
    public function addServer($serverIp, $serverPort, $serverWeight, $serverBase = 0)
    {
        $this->connection->executeUpdate('INSERT INTO ' . $this->tableName . ' (ip, port, weight, base) VALUES(:serverIp, :serverPort, :serverWeight, :serverBase)', array(
            'serverIp' => $serverIp,$serverBase,
            'serverPort' => (int) $serverPort,
            'serverWeight' => (int) $serverWeight,
            'serverBase' => (int) $serverBase,
        ));

        return array(
            'id' => $this->connection->lastInsertId(),
            'server_ip' => $serverIp,
            'server_port' => $serverPort,
            'server_weight' => $serverWeight,
            'server_base' => $serverBase,
        );
    }

    /**
     * Test if a Memcache configuration is valid.
     *
     * @param string $serverIp
     * @param string @serverPort
     *
     * @return bool
     */
    public function testConfiguration($serverIp, $serverPort)
    {
        if (extension_loaded('redis')) {
            if ($cacheTest = @fsockopen($serverIp, $serverPort)) {
                fclose($cacheTest); //on ferme la connexion
                $redis = new redis();
                return $redis->connect($serverIp, $serverPort);
                }
            }
        return false;
    }

    /**
     * Delete a memcache server (a deletion returns the number of rows deleted).
     *
     * @param int $serverId_server id (in database)
     *
     * @return bool
     */
    public function deleteServer($serverId)
    {
        $deletionSuccess = $this->connection->delete($this->tableName, array('id_redis_server' => $serverId));

        return 1 === $deletionSuccess;
    }

    /**
     * Get list of redis servers.
     *
     * @return array
     */
    public function getServers()
    {
        return $this->connection->fetchAll('SELECT * FROM ' . $this->tableName, array());
    }
}
