<?php
/**
 * tracing binance notices
 */

use \Workerman\Worker;
use \Workerman\Timer;

const REDIS_HOST = '127.0.0.1';
const REDIS_PORT = '6379';

$worker = new Worker();
$worker->onWorkerStart = function ($worker) {
    $redis = new Redis();
    $redis->connect(REDIS_HOST, REDIS_PORT);

    Timer::add(1, function () use ($redis) {
        $url = 'https://www.binance.com/bapi/composite/v1/public/cms/article/list/query?type=1&pageSize=20&pageNo=1';
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.85 Safari/537.36',
            'Origin: https://www.binance.com',
            'clienttype: web',
            'lang: en',
        ];
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPGET => true,
        ));
        $response = curl_exec($curl);
        curl_close($curl);

        // 处理响应
        if ($response) {
            $resp_array = json_decode($response, true);
        } else {
            echo 'request error: '. $response ."\n";
            return 0;
        }
        
       

        if (!isset($resp_array['data']) || !isset($resp_array['data']['catalogs'])) {
            echo date('Y-m-d H:i:s') .' data format changed ' ."\n";
            return 0;
        }
        $announce_lists = $resp_array['data']['catalogs'];

        $url = 'https://www.binance.com/en/support/announcement/';
        foreach ($announce_lists as $catalogs) {
            //new listed coins
            if (!in_array($catalogs['catalogId'], [48, 49, 50, 161, 157])) continue;

            $mark_key = 'binance:online:'. $catalogs['catalogId'];
            $last_id = $redis->get($mark_key);
            if (empty($last_id) || !is_numeric($last_id)) {
                $last_id = 0;
            }

            if (!isset($catalogs['articles']) || !is_array($catalogs['articles'])) {
                echo date('Y-m-d H:i:s') .' articles key changed '."\n";
                continue;
            }

            $announce_data = [];
            $new_last_id = $last_id;
            foreach ($catalogs['articles'] as $row) {
                if ($row['id'] <= $last_id) continue;

                if ($new_last_id < $row['id']) {
                    $new_last_id = $row['id'];
                }

                $detail_url = $url . $row['code'];
                $announce_data[] = [
                    'announce_address' => $detail_url,
                    'title' => $row['title'],
                    'showtime' => $row['releaseDate'] / 1000, //time in seconds
                ];
            }

            if (!empty($announce_data)) {

                //TODO do your trading logic
            }

            if ($new_last_id > $last_id) {
                $redis->setex($mark_key, 2592000, $new_last_id);
            }
        }
    });
};

Worker::runAll();

