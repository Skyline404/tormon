<?php
class qBittorrent
{
    #добавляем новую закачку в torrent-клиент, обновляем hash в базе
    public static function addNew($id, $file, $hash, $tracker, $old_files = array())
    {
        #получаем настройки из базы
        $settings = Database::getAllSetting();
        foreach ($settings as $row)
        {
        	extract($row);
        }

        $individualPath = Database::getTorrentDownloadPath($id);
        if ( ! empty($individualPath))
            $pathToDownload = $individualPath;

        $category = Database::getTorrentCategory($id);
        if (empty($category))
            $category = Database::getSetting('qbitCategory');

        $data = array('username' => $torrentLogin, 'password' => $torrentPassword);

        //Авторизация
        $MainCurl = curl_init();
        curl_setopt_array($MainCurl, array(
            CURLOPT_URL => $torrentAddress."/api/v2/auth/login",
            CURLOPT_USERAGENT => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_VERBOSE => true,
            CURLOPT_HEADER => true,
            CURLOPT_POSTFIELDS => http_build_query($data)
        ));
    
        $response = curl_exec($MainCurl);
        $httpCode = curl_getinfo($MainCurl, CURLINFO_RESPONSE_CODE);

        preg_match_all("/(QBT_)?SID(_\d+)?=(.*);/", $response, $match);

        if (($httpCode != 200 && $httpCode != 204) || empty($match[0][0]))
        {
            curl_close($MainCurl);
            $return['status'] = FALSE;
            $return['msg'] = 'log_passwd';
            return $return;
        }

        $cookie = $match[0][0];
        curl_setopt($MainCurl, CURLOPT_COOKIE, $cookie);
        curl_setopt($MainCurl, CURLOPT_HEADER, false);

        if ( ! empty($hash))
        {
            // Пытаемся получить список старых файлов перед удалением старого торрента
            curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress."/api/v2/torrents/files?hash=".urlencode($hash));
            curl_setopt($MainCurl, CURLOPT_HTTPGET, true);
            $old_files_response = curl_exec($MainCurl);
            $old_files_json = json_decode($old_files_response, true);
            if (is_array($old_files_json) && !empty($old_files_json)) {
                $old_files = array();
                foreach ($old_files_json as $of) {
                    $old_files[] = basename($of['name']);
                }
            }
            if (empty($old_files)) {
                // Без списка старых серий обновление включило бы повторную загрузку всего сезона.
                curl_close($MainCurl);
                return array('status' => FALSE, 'msg' => 'add_fail');
            }
            curl_setopt($MainCurl, CURLOPT_POST, true);

            $data = array(
                'hashes' => $hash,
                'deleteFiles' => 'false'
            );
            curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress."/api/v2/torrents/delete");
            curl_setopt($MainCurl, CURLOPT_POSTFIELDS, http_build_query($data));

            if ($tracker == 'lostfilm.tv' || $tracker == 'lostfilm-mirror' ||  $tracker == 'baibako.tv' || $tracker == 'newstudio.tv')
            {
                if ($deleteOldFiles)
                    $data['deleteFiles'] = 'true';
                #удяляем существующую закачку из torrent-клиента
                if ($deleteDistribution)
                    curl_exec($MainCurl);
            }
            else
            {
                #удяляем существующую закачку из torrent-клиента
                curl_exec($MainCurl);
            }
        }
        
        // Постоянная метка темы позволяет найти именно нашу раздачу, в том числе при повторной попытке.
        $torrentTag = 'torrentmonitor-'.$id;

        //Формируется тело запроса
        // autoTMM=true заставляет qBit игнорировать savepath — отключаем если путь задан
        $data = array(
            'urls'        => $file,
            'tags'        => $torrentTag,
            'paused'      => 'true', // qBittorrent 4.x
            'stopped'     => 'true', // qBittorrent 5.x
            'autoTMM'     => empty($pathToDownload) ? 'true' : 'false',
            'savepath'    => $pathToDownload,
            'root_folder' => 'true',
            
        );
        if (!empty($category))
            $data['category'] = $category;
        
        //формируется заголовок запроса
        $request_headers = array(
            "Cookie: ".$cookie
        );
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $torrentAddress."/api/v2/torrents/add",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_VERBOSE => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => $request_headers,
            CURLOPT_COOKIE => $cookie,
            CURLOPT_POSTFIELDS => $data
        ));
        $addResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        //ожидаем код 200 при успешном добавлении нового и код 202, если торрент уже существует
        if ($httpCode >= 200 && $httpCode <= 204) {
            // qBittorrent 5.x возвращает хэш прямо в теле ответа
            $addData = json_decode($addResponse, true);
            if ( ! empty($addData['added_torrent_ids'][0]))
            {
                $hashNew = $addData['added_torrent_ids'][0];
            }
            else
            {
                // Не используем последний добавленный торрент: его мог добавить другой клиент.
                $hashNew = null;
                for ($attempt = 0; $attempt < 10; $attempt++) {
                    curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress.'/api/v2/torrents/info?'.http_build_query(array('tag' => $torrentTag)));
                    curl_setopt($MainCurl, CURLOPT_HTTPGET, true);
                    $rdata = json_decode(curl_exec($MainCurl), true);
                    if (is_array($rdata) && count($rdata) === 1 && !empty($rdata[0]['hash'])) {
                        $hashNew = $rdata[0]['hash'];
                        break;
                    }
                    sleep(1);
                }
            }

            if (empty($hashNew)) {
                curl_close($MainCurl);
                return array('status' => FALSE, 'msg' => 'add_fail');
            }

            if (!empty($old_files)) {
                $new_files = null;
                for ($attempt = 0; $attempt < 10; $attempt++) {
                    curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress."/api/v2/torrents/files?hash=".urlencode($hashNew));
                    curl_setopt($MainCurl, CURLOPT_HTTPGET, true);
                    $new_files = json_decode(curl_exec($MainCurl), true);
                    if (is_array($new_files) && !empty($new_files))
                        break;
                    sleep(1);
                }
                if (empty($new_files) || !is_array($new_files)) {
                    curl_close($MainCurl);
                    return array('status' => FALSE, 'msg' => 'add_fail');
                }

                if (is_array($new_files)) {
                    $prio_ids = array();
                    foreach ($new_files as $f) {
                        $is_old = false;
                        foreach ($old_files as $old_file) {
                            if (substr($f['name'], -strlen($old_file)) === $old_file) {
                                $is_old = true;
                                break;
                            }
                        }
                        if ($is_old) {
                            $prio_ids[] = $f['index'];
                        }
                    }
                    if (!empty($prio_ids)) {
                        $prio_data = array(
                            'hash' => $hashNew,
                            'id' => implode('|', $prio_ids),
                            'priority' => 0
                        );
                        curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress."/api/v2/torrents/filePrio");
                        curl_setopt($MainCurl, CURLOPT_POST, true);
                        curl_setopt($MainCurl, CURLOPT_POSTFIELDS, http_build_query($prio_data));
                        curl_exec($MainCurl);
                        if (curl_getinfo($MainCurl, CURLINFO_RESPONSE_CODE) !== 200) {
                            curl_close($MainCurl);
                            return array('status' => FALSE, 'msg' => 'add_fail');
                        }
                    }
                }
                curl_setopt($MainCurl, CURLOPT_POST, true);
                curl_setopt($MainCurl, CURLOPT_POSTFIELDS, '');
            }

            #обновляем hash только после успешного применения приоритетов
            Database::updateHash($id, $hashNew);

            // Форсированно снимаем с паузы
            curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress."/api/v2/torrents/start");
            curl_setopt($MainCurl, CURLOPT_POST, true);
            curl_setopt($MainCurl, CURLOPT_POSTFIELDS, http_build_query(array('hashes' => $hashNew)));
            curl_exec($MainCurl);

            //сбрасываем варнинг
            Database::clearWarnings('qBittorrent');
            $return['status'] = TRUE;
            $return['hash'] = $hashNew;
        } else {
            $return['status'] = FALSE;
            $return['msg'] = 'add_fail';
        }

        //выход
        curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress."/api/v2/auth/logout");
        curl_exec($MainCurl);
        curl_close($MainCurl);

        return $return;
    }

    #получаем поле comment торрента по его hash (для api-интеграции)
    #повторяет попытки, чтобы справиться с race condition: торрент может ещё не появиться в qBit
    public static function getTorrentComment($hash, $retries = 4, $delay = 3)
    {
        $settings = Database::getAllSetting();
        foreach ($settings as $row)
            extract($row);


        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $torrentAddress.'/api/v2/auth/login',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HEADER         => true,
            CURLOPT_POSTFIELDS     => http_build_query(['username' => $torrentLogin, 'password' => $torrentPassword]),
        ]);

        $response = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        preg_match_all('/(QBT_)?SID(_\d+)?=(.*);/', $response, $match);

        if (($httpCode != 200 && $httpCode != 204) || empty($match[0][0])) {
            curl_close($ch);
            return null;
        }

        $cookie = $match[0][0];
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPGET, true);

        $comment = null;
        $hashLower = strtolower($hash);
        for ($i = 0; $i < $retries; $i++) {
            if ($i > 0)
                sleep($delay);

            curl_setopt($ch, CURLOPT_URL, $torrentAddress.'/api/v2/torrents/properties?hash='.urlencode($hashLower));
            $response = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($httpCode === 200 && !empty($response)) {
                $data = json_decode($response, true);
                if (!empty($data['comment'])) {
                    $comment = $data['comment'];
                    break;
                }
            }
        }

        curl_setopt($ch, CURLOPT_URL, $torrentAddress.'/api/v2/auth/logout');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_exec($ch);
        curl_close($ch);

        return $comment;
    }

    #удаляем раздачу из torrent-клиента (без добавления новой)
    public static function remove($hash)
    {
        $settings = Database::getAllSetting();
        foreach ($settings as $row)
        {
            extract($row);
        }


        $data = array('username' => $torrentLogin, 'password' => $torrentPassword);

        $MainCurl = curl_init();
        curl_setopt_array($MainCurl, array(
            CURLOPT_URL => $torrentAddress."/api/v2/auth/login",
            CURLOPT_USERAGENT => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:51.0) Gecko/20100101 Firefox/51.0",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HEADER => true,
            CURLOPT_POSTFIELDS => http_build_query($data)
        ));

        $response = curl_exec($MainCurl);
        $httpCode = curl_getinfo($MainCurl, CURLINFO_RESPONSE_CODE);
        preg_match_all("/(QBT_)?SID(_\d+)?=(.*);/", $response, $match);

        if (($httpCode != 200 && $httpCode != 204) || empty($match[0][0]))
        {
            curl_close($MainCurl);
            return array('status' => FALSE, 'msg' => 'log_passwd');
        }

        $cookie = $match[0][0];
        curl_setopt($MainCurl, CURLOPT_COOKIE, $cookie);
        curl_setopt($MainCurl, CURLOPT_HEADER, false);

        $data = array(
            'hashes' => $hash,
            'deleteFiles' => ! empty($deleteOldFiles) ? 'true' : 'false'
        );
        curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress."/api/v2/torrents/delete");
        curl_setopt($MainCurl, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_exec($MainCurl);

        Database::clearWarnings('qBittorrent');

        curl_setopt($MainCurl, CURLOPT_URL, $torrentAddress."/api/v2/auth/logout");
        curl_exec($MainCurl);
        curl_close($MainCurl);

        return array('status' => TRUE);
    }
}
?>
