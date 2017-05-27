<?php

//-------- Список серверов --------//

// Нельзя давать серверам одинаковые назвния
// Формат: $server[] = 'IP_сервера:Порт_сервера:Название_сервера';
//
$server[] = '185.74.4.117:25565:Industrial';
//$server[] = '185.74.4.117:25567:Magic RPG';
$server[] = '185.74.4.117:25566:Mini Games 1';
$server[] = '185.74.4.117:25570:Mini Games 2';
$server[] = '185.74.4.117:25568:SkyBlock';
//$server[] = '185.74.4.117:25569:Classic';

//-------- Настройки --------//

$cache_file = 'monitoring.json';		// Путь до расположения кэша. Файл сам не создастся!!!
$check_time = 29;						// Промежуток между проверками онлайна сервера (в секундах) (мысленно умножить на кол-во серверов) НЕ СТАВИТЬ МЕНЬШЕ 1.1 !!!
$time_out = 3;							// Время ожидания ответа сервера (если он выключен)
$offline_percent = 0;					// Сколько процентов бара будет заполнено, если сервер выключен
$big_request = true;					// Проверять все сервера сразу за один запрос к скрипту (иначе по-очереди)
$result_cache = 'cache.json';			// Кэш результатов

//-------- Внутренние переменные --------//

$full_online = 0;
$cur_time = time();
$iter = -1;
$allserv_max = 0;
$allserv_online = false;

//-------- Считывание основных данных --------//

$cache = json_decode(file_get_contents($cache_file), true);

// Если произошла ошибка при считывании, то завершаемся, а не засоряем файл кеша испорчанными данными.
if ($cache === NULL || (!isset($cache['comStats'][1])) || (!isset($cache['comStats'][2])) || (!isset($cache['comStats'][3])) || (!isset($cache['comStats'][4])))
{
	http_response_code(500);
	exit;
}

// Считываем данные из кэша
$cur_online = $cache['comStats'][1]; // Полный онлайн
$record_online = $cache['comStats'][2]; // Рекорд онлайна
$last_check = $cache['comStats'][3]; // Время последнего запроса данных
$start_check = $cache['comStats'][4]; // Номер сервера, с которого нужно начать проверку

$interval = $cur_time-$last_check;

//-------- Вывод кэша --------//

header("Content-type: text/html");
echo file_get_contents($result_cache);
// Разрываем соединение fastcgi
session_write_close();
fastcgi_finish_request();

//-------- Сохранение текущего времени --------//

if($interval >= $check_time)
{
	// Сразу зписываем текущее время в кэш, чтобы одновременно 10 скриптов не обращались к серверу Minecraft и сайт не вис
	$new_cache = $cache;
	$new_cache['comStats'][3] = $cur_time;
	file_put_contents($cache_file, json_encode($new_cache));
	
	//-------- Обращение к серверу и построение массива --------//
	
	foreach($server as $e) {
		$iter += 1;
		list($host, $port, $name) = explode(":", $e);
		//-------- Получение переменных --------//
		if($big_request || ($start_check == $iter)) {
			// Если прошло достаточно времени, то делаем проверку сервера
			if( $socket = @fsockopen('tcp://'.$host, $port, $erno, $erstr, $time_out) ) {
				@fwrite($socket, "\xFE");
				$data = @fread($socket, 1024);
				if( strpos($data,"\x00\x00") != 0 ) {
					$info = explode("\x00\x00", $data);
					$info = str_replace("\x00", '', $info);
					$players_online = $info[4];
					$players_max = $info[5];
					$server_online = 1;
				} else {
					$info = explode("\xA7", $data);
					$info = str_replace("\x00", '', $info);
					$players_online = $info[1];
					$players_max = $info[2];
					$server_online = 1;
				}
				@fclose($socket);
			} else {
				$players_online = 0;
				$players_max = 0;
				$server_online = 0;
			}
			unset($data);
			unset($info);
			
			// Записывем номер следующего сервера в очереди для проверки
			$check_server = $iter+1;
			if( $check_server >= count($server) ) {
				$check_server = 0;
			}
		} else {
			// Если не прошло достаточно времени для новой проверки, достаём данные из кэша
			$players_online = $cache[$name][1];
			$players_max = $cache[$name][2];
			$server_online = $cache[$name][3];
		}
		// Пересчитывем полный онлайн
		$full_online += $players_online;
		// Подготавливаем данные о серверах для записи в кэш
		$new_cache[$name] = array(
			1 => $players_online,
			2 => $players_max,
			3 => $server_online
		);
		
		if ($server_online === 1) //сервера считаются включенными если включен хотя бы один сервер
			$allserv_online = true;
		
		$allserv_max += $players_max; //суммируем максимальное количество игроков
	}
	
	//-------- Запись общей статистики --------//
	
	// Пересчитываем рекордный онлайн
	if($full_online > $record_online) {
		$record_online = $full_online;
	}
	// Формируем общий кэш
	$new_cache['comStats'] = array(
		1 => $full_online,	// Полный онлайн
		2 => $record_online,	// Рекорд онлайна
		3 => $cur_time,	// Время последнего запроса данных
		4 => $check_server	// Номер следующего сервера для проверки
	);
	// Записываем кэш
	if ($record_online != 0) // Если рекордный онлайн стал нулевым, то наверняка произошла какая-то ошибка
		file_put_contents($cache_file, json_encode($new_cache));
	
	//-------- Запись кэшей -------//
	
	$echo_data = '<html>
    <head>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <div class="container">
            <div class="logo"></div>
            <div class="info">
                <div class="enum"><center><b>Лаунчер:</b> <a target="_blank" href="http://icraft.uz/info-play.html#start">Скачать</a></center></div>
                <div class="enum odd"><center><b>Статус серверов:</b> <span class="'.($allserv_online ? 'on' : 'off').'">'.($allserv_online ? 'Включены' : 'Выключены').'</span></center></div>
                <div class="enum"><center><b>Количество серверов:</b> '.count($server).'</center></div>
				<div class="enum odd"><center><b>Игроков онлайн:</b> <span class="'.($allserv_online ? 'on' : 'off').'">'.$full_online.' / '.$allserv_max.'</span></center></div>
                <div class="enum"><center><b>Рекордный онлайн:</b> <span>'.$record_online.'</span></center></div>
            </div>
        </div>
    </body>
</html>';
	file_put_contents($result_cache, $echo_data);
}

?>
