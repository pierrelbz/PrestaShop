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

/**
 * This class require PECL REdis extension.
 */
class CacheRedisCore extends Cache
{
    /**
     * @var redis
     */
    protected $redis;
    /**
     * Maximum TTL for this bin from Drupal configuration.
     *
     * @var int
     */
    protected $maxTtl = 0;

    /**
     * Default lifetime for permanent items.
     * Approximatively 1 year.
     */
    const LIFETIME_PERM_DEFAULT = 31536000;
    /**
     * Default TTL for CACHE_PERMANENT items.
     *
     * See "Default lifetime for permanent items" section of README.txt
     * file for a comprehensive explaination of why this exists.
     *
     * @var int
     */
    protected $permTtl = self::LIFETIME_PERM_DEFAULT;

    /**
     * Flush permanent and volatile cached values
     *
     * @var string[]
     *   First value is permanent latest flush time and second value
     *   is volatile latest flush time
     */
    protected $flushCache = null;
    /**
     * @var bool Connection status
     */
    protected $is_connected = false;

    /**
     * Lastest cache flush KEY name
     */
    const LAST_FLUSH_KEY = '_last_flush';
    /**
     * Key components name separator
     */
    const KEY_SEPARATOR = ':';

    /**
     * @var string
     */
    static protected $globalPrefix;





    public function __construct()
    {
        $this->connect();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Connect to redis server.
     */
    public function connect()
    {
        if (class_exists('Redis') && extension_loaded('redis')) {
            $this->redis = new Redis();
        } else {
            return;
        }

        $servers = self::getRedisServers();
        if (!$servers) {
            return;
        }
        foreach ($servers as $server) {
            $this->redis->pconnect($server['ip'], $server['port']);
            if (isset($server['base'])) {
                $this->redis->select($server['base']);
            }


        }

        $this->is_connected = true;
    }



    protected  function getMaxTtl(){
        return $this->maxTtl;

    }
    /**
     * Get TTL for CACHE_PERMANENT items.
     *
     * @return int
     *   Lifetime in seconds.
     */
    public function getPermTtl()
    {
        return $this->permTtl;
    }
    /**
     * @see Cache::_set()
     */
    protected function _set($key, $value,  $expire = CACHE_PERMANENT)
    {
        if (!$this->is_connected) {
            return false;
        }

        $hash   = $this->createEntryHash($key, $value, $expire);
        $result = $this->redis->hMSet($key, $hash);
        if ($result === false) {
            $this->setAdjustTableCacheSize(true);
        }

        return $result;




    }

    /**
     * Create cache entry
     *
     * @param string $cid
     * @param mixed $data
     *
     * @return array
     */
    protected function createEntryHash($cid, $data, $expire = 1)
    {
        list($flushPerm, $flushVolatile) = $this->getLastFlushTime();
        $validityThreshold = $flushPerm;


        $time = $this->getValidChecksum($validityThreshold);

        $hash = array(
            'cid'     => $cid,
            'created' => $time,
            'expire'  => $expire,
        );

        // Let Redis handle the data types itself.
        if (!is_string($data)) {
            $hash['data'] = serialize($data);
            $hash['serialized'] = 1;
        } else {
            $hash['data'] = $data;
            $hash['serialized'] = 0;
        }

        return $hash;
    }


    /**
     * Expand cache entry from fetched data
     *
     * @param array $values
     *   Raw values fetched from Redis server data
     *
     * @return array
     *   Or FALSE if entry is invalid
     */
    protected function expandEntry(array $values, $flushPerm, $flushVolatile)
    {
        // Check for entry being valid.
        if (empty($values['cid'])) {
            return;
        }

        // This ensures backward compatibility with older version of
        // this module's data still stored in Redis.
        if (isset($values['expire'])) {
            $expire = (int)$values['expire'];
            // Ensure the entry is valid and have not expired.
            if ($expire !== CACHE_PERMANENT && $expire !== CACHE_TEMPORARY && $expire <= time()) {
                return false;
            }
        }

        // Ensure the entry does not predate the last flush time.
        if ($this->allowTemporaryFlush && !empty($values['volatile'])) {
            $validityThreshold = max(array($flushPerm, $flushVolatile));
        } else {
            $validityThreshold = $flushPerm;
        }

        if ($values['created'] <= $validityThreshold) {
            return false;
        }

        $entry = (object)$values;

        // Reduce the checksum to the real timestamp part
        $entry->created = (int)$entry->created;

        if ($entry->serialized) {
            $entry->data = unserialize($entry->data);
        }

        return $entry;
    }





    /**
     * Get global default prefix
     *
     * @return string
     */
    static public function getDefaultPrefix($key = '')
    {
        $ret = 'prestashop';

        if($key){
            return $ret . self::KEY_SEPARATOR  . $key;
        }else{
            return $ret;
        }




    }

    /**
     * Get latest flush time
     *
     * @return string[]
     *   First value is the latest flush time for permanent entries checksum,
     *   second value is the latest flush time for volatile entries checksum.
     */
    public function getLastFlushTime()
    {
        if (!$this->is_connected) {
            return false;
        }

        if (!$this->flushCache) {
            $key    = $this->get(self::LAST_FLUSH_KEY);
            if($key){
                $key = $this->getDefaultPrefix($key);
                $values = $this->redis->hmget($key, array("permanent", "volatile"));
                if (empty($values) || !is_array($values)) {
                    $ret = array(0, 0);
                } else {
                    if (empty($values['permanent'])) {
                        $values['permanent'] = 0;
                    }
                    if (empty($values['volatile'])) {
                        $values['volatile'] = 0;
                    }
                    $ret = array($values['permanent'], $values['volatile']);
                }

                $this->flushCache = $ret;
            }      else{
                $this->flushCache =  array(0,0);;
                $this->setLastFlushTime(true, true);
            }

            // At the very first hit, we might not have the timestamps set, thus
            // we need to create them to avoid our entry being considered as
            // invalid
            if (!$this->flushCache[0]) {
                $this->setLastFlushTime(true, true);
            } else if (!$this->flushCache[1]) {
                $this->setLastFlushTime(false, true);
            }

        }

        return $this->flushCache;
    }

    /**
     * Set last flush time
     *
     * @param string $permanent
     * @param string $volatile
     */
    public function setLastFlushTime($permanent = false, $volatile = false)
    {
        list($flushPerm, $flushVolatile) = $this->getLastFlushTime();

        $checksum = $this->getValidChecksum(
            max(array(
                $flushPerm,
                $flushVolatile,
                $permanent,
                time(),
            ))
        );

        if ($permanent) {
            $this->setLastFlushTimeFor($checksum, false);
            $this->setLastFlushTimeFor($checksum, true);
            $this->flushCache = array($checksum, $checksum);
        } else if ($volatile) {
            $this->setLastFlushTimeFor($checksum, true);
            $this->flushCache = array($flushPerm, $checksum);
        }
    }

    public function setLastFlushTimeFor($time, $volatile = false)
    {

        $key    = $this->getDefaultPrefix(self::LAST_FLUSH_KEY);

        if ($volatile) {
            $this->redis->hset($key, 'volatile', $time);
        } else {
            $this->redis->hMSet($key, array(
                'permanent' => $time,
                'volatile' => $time,
            ));
        }
    }
    /**
     * @see Cache::_get()
     */
    protected function _get($key)
    {
        if (!$this->is_connected) {
            return false;
        }
        $value = $this->redis->get($key);
        if(!is_array($value)){
            return false;
        }
        return $this->expandEntry($value);
    }

    /**
     * @see Cache::_exists()
     */
    protected function _exists($key)
    {
        if (!$this->is_connected) {
            return false;
        }

        return $this->redis->get($key) !== false;
    }

    /**
     * @see Cache::_delete()
     */
    protected function _delete($key)
    {
        if (!$this->is_connected) {
            return false;
        }

        return $this->redis->unlink($key);
    }

    /**
     * @see Cache::_writeKeys()
     */
    protected function _writeKeys()
    {
        if (!$this->is_connected) {
            return false;
        }

        return true;
    }

    /**
     * @see Cache::flush()
     */
    public function flush()
    {
        if (!$this->is_connected) {
            return false;
        }

        return $this->redis->flush();
    }

    /**
     * Store a data in cache.
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     *
     * @return bool
     */
    public function set($key, $value, $ttl = 0)
    {
        $key = $this->getDefaultPrefix($key);
        return $this->_set($key, $value, $ttl);
    }

    /**
     * Retrieve a data from cache.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function get($key)
    {
        $key = $this->getDefaultPrefix($key);
        return $this->_get($key);
    }

    /**
     * Check if a data is cached.
     *
     * @param string $key
     *
     * @return bool
     */
    public function exists($key)
    {
        $key = $this->getDefaultPrefix($key);
        return $this->_exists($key);
    }

    /**
     * Delete one or several data from cache (* joker can be used, but avoid it !)
     * 	E.g.: delete('*'); delete('my_prefix_*'); delete('my_key_name');.
     *
     * @param string $key
     *
     * @return bool
     */
    public function delete($key)
    {
        $key = $this->getDefaultPrefix($key);
        if ($key == '*') {
            $this->flush();
        } elseif (strpos($key, '*') === false) {
            $this->_delete($key);
        } else {
            // Get keys (this code comes from Doctrine 2 project)
            $pattern = str_replace('\\*', '.*', preg_quote($key));
            $servers = $this->getRedisServers();
            if (is_array($servers) && count($servers) > 0 && method_exists('redis', 'getStats')) {
                $all_slabs = $this->redis->getStats('slabs');
            }

            if (isset($all_slabs) && is_array($all_slabs)) {
                foreach ($all_slabs as $server => $slabs) {
                    if (is_array($slabs)) {
                        foreach (array_keys($slabs) as $i => $slab_id) {
                            // $slab_id is not an int but a string, using the key instead ?

                            if (is_int($i)) {
                                $dump = $this->redis->getStats('cachedump', (int) $i);
                                if ($dump) {
                                    foreach ($dump as $entries) {
                                        if ($entries) {
                                            foreach ($entries as $key => $data) {
                                                if (preg_match('#^' . $pattern . '$#', $key)) {
                                                    $this->_delete($key);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Close connection to memcache server.
     *
     * @return bool
     */
    protected function close()
    {
        if (!$this->is_connected) {
            return false;
        }

        return $this->redis->close();
    }




    /**
     * Add a memcache server.
     *
     * @param string $ip
     * @param int $port
     * @param int $weight
     */
    public static function addServer($ip, $port, $weight)
    {
        return Db::getInstance()->execute('INSERT INTO ' . _DB_PREFIX_ . 'redis_servers (ip, port, weight) VALUES(\'' . pSQL($ip) . '\', ' . (int) $port . ', ' . (int) $weight . ')', false);
    }

    /**
     * Get list of redis servers.
     *
     * @return array
     */
    public static function getRedisServers()
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'redis_servers', true, false);
    }

    /**
     * Delete a memcache server.
     *
     * @param int $id_server
     */
    public static function deleteServer($id_server)
    {
        return Db::getInstance()->execute('DELETE FROM ' . _DB_PREFIX_ . 'redis_servers WHERE id_redis_server=' . (int) $id_server);
    }


    /**
     * From the given timestamp build an incremental safe time-based identifier.
     *
     * Due to potential accidental cache wipes, when a server goes down in the
     * cluster or when a server triggers its LRU algorithm wipe-out, keys that
     * matches flush or tags checksum might be dropped.
     *
     * Per default, each new inserted tag will trigger a checksum computation to
     * be stored in the Redis server as a timestamp. In order to ensure a checksum
     * validity a simple comparison between the tag checksum and the cache entry
     * checksum will tell us if the entry pre-dates the current checksum or not,
     * thus telling us its state. The main problem we experience is that Redis
     * is being so fast it is able to create and drop entries at same second,
     * sometime even the same micro second. The only safe way to avoid conflicts
     * is to checksum using an arbitrary computed number (a sequence).
     *
     * Drupal core does exactly this thus tags checksums are additions of each tag
     * individual checksum; each tag checksum is a independent arbitrary serial
     * that gets incremented starting with 0 (no invalidation done yet) to n (n
     * invalidations) which grows over time. This way the checksum computation
     * always rises and we have a sensible default that works in all cases.
     *
     * This model works as long as you can ensure consistency for the serial
     * storage over time. Nevertheless, as explained upper, in our case this
     * serial might be dropped at some point for various valid technical reasons:
     * if we start over to 0, we may accidentally compute a checksum which already
     * existed in the past and make invalid entries turn back to valid again.
     *
     * In order to prevent this behavior, using a timestamp as part of the serial
     * ensures that we won't experience this problem in a time range wider than a
     * single second, which is safe enough for us. But using timestamp creates a
     * new problem: Redis is so fast that we can set or delete hundreds of entries
     * easily during the same second: an entry created then invalidated the same
     * second will create false positives (entry is being considered as valid) -
     * note that depending on the check algorithm, false negative may also happen
     * the same way. Therefore we need to have an abitrary serial value to be
     * incremented in order to enforce our checks to be more strict.
     *
     * The solution to both the first (the need for a time based checksum in case
     * of checksum data being dropped) and the second (the need to have an
     * arbitrary predictible serial value to avoid false positives or negatives)
     * we are combining the two: every checksum will be built this way:
     *
     *   UNIXTIMESTAMP.SERIAL
     *
     * For example:
     *
     *   1429789217.017
     *
     * will reprensent the 17th invalidation of the 1429789217 exact second which
     * happened while writing this documentation. The next tag being invalidated
     * the same second will then have this checksum:
     *
     *   1429789217.018
     *
     * And so on...
     *
     * In order to make it consitent with PHP string and float comparison we need
     * to set fixed precision over the decimal, and store as a string to avoid
     * possible float precision problems when comparing.
     *
     * This algorithm is not fully failsafe, but allows us to proceed to 1000
     * operations on the same checksum during the same second, which is a
     * sufficiently great value to reduce the conflict probability to almost
     * zero for most uses cases.
     *
     * @param int|string $timestamp
     *   "TIMESTAMP[.INCREMENT]" string
     *
     * @return string
     *   The next "TIMESTAMP.INCREMENT" string.
     */
    public function getNextIncrement($timestamp = null)
    {
        if (!$timestamp) {
            return time() . '.000';
        }

        if (false !== ($pos = strpos($timestamp, '.'))) {
            $inc = substr($timestamp, $pos + 1, 3);

            return ((int)$timestamp) . '.' . str_pad($inc + 1, 3, '0', STR_PAD_LEFT);
        }

        return $timestamp . '.000';
    }

    /**
     * Get valid checksum
     *
     * @param int|string $previous
     *   "TIMESTAMP[.INCREMENT]" string
     *
     * @return string
     *   The next "TIMESTAMP.INCREMENT" string.
     *
     * @see Redis_Cache::getNextIncrement()
     */
    public function getValidChecksum($previous = null)
    {
        if (time() === (int)$previous) {
            return $this->getNextIncrement($previous);
        } else {
            return $this->getNextIncrement();
        }
    }
}
