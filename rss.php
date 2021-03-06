<?php
/**
 * Created by PhpStorm.
 * User: fengchang
 * Date: 2017/6/7
 * Time: 下午10:49
 */

require 'config.php';
require 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use FeedWriter\ATOM;

date_default_timezone_set('Asia/Hong_Kong');

$log = new Logger('readhub-rss');
$log->pushHandler(new RotatingFileHandler(LOG_PATH, LOG_KEEP_DAYS, Logger::INFO));

$redis = null;
if (ENABLE_CACHE) {
    $redis = new Redis();
    $redis->pconnect(REDIS_HOST, REDIS_PORT);
}

$feedItems = [];

if (isset($_GET['channel'])) {
    if ($_GET['channel'] === 'all')
        $channels = array_keys(CHANNEL_CONFIG);
    else
        $channels = explode(",", $_GET['channel']);
} else {
    $channels = ['topics'];
}

foreach ($channels as $channel) {
    if (!array_key_exists($channel, CHANNEL_CONFIG))
        continue;
    $channelItems = [];
    if (ENABLE_CACHE) {
        $channelJson = $redis->get($channel);
        if ($channelJson !== FALSE) {
            $channelItems = unserialize($channelJson);
            $log->addInfo("CACHE: GET channel $channel from Redis");
        }
    }
    if (empty($channelItems)) {
        $className = CHANNEL_CONFIG[$channel]['className'];
        $api = new $className;
        $channelItems = $api->getData();
        if (ENABLE_CACHE) {
            $redis->set($channel, serialize($channelItems), CACHE_EXPIRE);
            $log->addInfo("CACHE: SET channel $channel to Redis");
        }
    }
    $feedItems = array_merge($feedItems, $channelItems);
}

usort($feedItems, "compareItem");

$feed = initFeed();
foreach ($feedItems as $item)
    $feed->addItem($item);

$feed->printFeed();

/**
 * @return ATOM
 */
function initFeed()
{
    $feed = new ATOM();
    $feed->setTitle('Readbub');
    $feed->setDescription('A RSS feed for Readhub');
    $feed->setLink('https://readhub.me/');
    $feed->setDate(new DateTime());
    $feed->setImage('https://cdn.readhub.me/static/assets/png/readhub_logo.png');
    $feed->setChannelElement('author', 'fengchang@bayescafe.com');
    $feed->setSelfLink(RSS_URL);

    return $feed;
}

function compareItem($a, $b)
{
    $aDate = $a->getElements()['updated'];
    $bDate = $b->getElements()['updated'];
    if ($aDate == $bDate) {
        return 0;
    } else {
        return $aDate < $bDate ? 1 : 0;
    }
}