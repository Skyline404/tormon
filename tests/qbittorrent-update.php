<?php
namespace QBitUpdateTest;
// Run with php -n: the API is simulated, no real torrents are changed.
foreach (array('CURLOPT_URL', 'CURLOPT_USERAGENT', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_POST', 'CURLOPT_VERBOSE', 'CURLOPT_HEADER', 'CURLOPT_POSTFIELDS', 'CURLINFO_RESPONSE_CODE', 'CURLOPT_COOKIE', 'CURLOPT_HTTPGET', 'CURLOPT_HTTP_VERSION', 'CURL_HTTP_VERSION_1_1', 'CURLOPT_HTTPHEADER') as $i => $name) if (!defined($name)) define($name, $i + 1);
class Database {
    public static $hash;
    public static function getAllSetting() { return array(array('torrentAddress'=>'http://mock', 'torrentLogin'=>'user', 'torrentPassword'=>'pass', 'pathToDownload'=>'', 'deleteOldFiles'=>false, 'deleteDistribution'=>true)); }
    public static function getTorrentDownloadPath($id) { return ''; }
    public static function getTorrentCategory($id) { return ''; }
    public static function getSetting($key) { return ''; }
    public static function updateHash($id, $hash) { self::$hash = $hash; }
    public static function clearWarnings($client) {}
}
function curl_init() { return (object)array('options'=>array(), 'code'=>200); }
function curl_setopt($ch, $key, $value) { $ch->options[$key]=$value; }
function curl_setopt_array($ch, $options) { foreach ($options as $k=>$v) curl_setopt($ch,$k,$v); }
function curl_getinfo($ch, $key) { return $ch->code; }
function curl_close($ch) {}
function curl_exec($ch) {
    global $scenario, $calls, $reads;
    $url=$ch->options[CURLOPT_URL];
    $calls[]=$url;
    $ch->code=200;
    if (strpos($url, '/auth/login')!==false) return "Set-Cookie: SID=test;\r\n";
    if (strpos($url, '/files?hash=old')!==false) return $scenario==='missing-old' ? '[]' : '[{"name":"old/ep1.mkv"},{"name":"old/ep2.mkv"},{"name":"old/ep3.mkv"}]';
    if (strpos($url, '/torrents/add')!==false) {
        $data=$ch->options[CURLOPT_POSTFIELDS];
        check($data['stopped']==='true' && $data['paused']==='true', 'Added on pause');
        return 'Ok.';
    }
    if (strpos($url, '/torrents/info')!==false) {
        check(strpos($url, 'tag=torrentmonitor-42')!==false, 'Exact topic tag lookup');
        return '[{"hash":"new"}]';
    }
    if (strpos($url, '/files?hash=new')!==false) {
        if (++$reads===1) return '[]';
        return '[{"name":"new/ep1.mkv","index":2},{"name":"new/ep2.mkv","index":4},{"name":"new/ep3.mkv","index":7},{"name":"new/ep4.mkv","index":9}]';
    }
    if (strpos($url, '/filePrio')!==false) {
        parse_str($ch->options[CURLOPT_POSTFIELDS], $data);
        check($data['hash']==='new' && $data['id']==='2|4|7' && $data['priority']==='0', 'Only three old episodes disabled');
        if ($scenario==='priority-failure') $ch->code=409;
    }
    if (strpos($url, '/start')!==false) check(Database::$hash==='new', 'Hash saved before start');
    return '';
}
function check($condition,$message) { if (!$condition) throw new Exception($message); }
eval('namespace QBitUpdateTest;'.substr(file_get_contents(__DIR__.'/../class/qBittorrent.class.php'), 5));
foreach (array('success','priority-failure','missing-old') as $scenario) {
    $calls=array(); $reads=0; Database::$hash=null;
    $result=qBittorrent::addNew(42,'http://mock/season.torrent','old','rutracker.org');
    $started=count(array_filter($calls,function($url) { return strpos($url,'/start')!==false; }));
    check($result['status']===($scenario==='success'), $scenario.' status');
    check($started===($scenario==='success' ? 1 : 0), $scenario.' start');
    if ($scenario!=='success') check(Database::$hash===null, 'Failure preserves old hash');
    echo $scenario." OK\n";
}
