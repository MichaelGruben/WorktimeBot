<?php
error_reporting(E_ALL);
setlocale(LC_ALL, 'de_DE');
$config = parse_ini_file('../../../../config/worktimebot.ini', true);
if (!empty($_GET['teleToken']) && password_verify($config['application']['teleToken'], $_GET['teleToken'])) {
    $content = file_get_contents("php://input");
    $bot = new ArbeitszeitBot();
} else if ($argc > 1 && $argv[1] == 'checkTarget') {
    $bot = new ArbeitszeitBot('checkTarget');
} else {
    // echo json_encode($_GET)."\n";
    // echo json_encode($_POST)."\n";
    // echo json_encode($argv)."\n";
    echo 'Bratwurst';
}


class ArbeitszeitBot
{
    const TIMER_START = "\xe2\x96\xb6";
    const TIMER_PAUSE = "\xe2\x8f\xb8";
    const TIMER_STOP = "\xe2\x8f\xb9";

    const FORCE_REPLY_ADJUST_WORKTIME_PLUS = '+15 Minuten Arbeit';
    const FORCE_REPLY_ADJUST_WORKTIME_MINUS = '-15 Minuten Arbeit';
    const FORCE_REPLY_ADJUST_WORKTIME_CUSTOM = 'Sende mir deine Arbeitszeit-Korrektur als z.B. -1,5h oder 90m';
    const FORCE_REPLY_ADJUST_PAUSETIME_PLUS = '+15 Minuten Pause';
    const FORCE_REPLY_ADJUST_PAUSETIME_MINUS = '-15 Minuten Pause';
    const FORCE_REPLY_ADJUST_PAUSETIME_CUSTOM = 'Sende mir deine Pausenzeit-Korrektur als z.B. -1,5h oder 90m';

    const MENU = array(
        'reply_markup' => array(
            'keyboard' => array(
                array(
                    array('text' => self::TIMER_START),
                    array('text' => self::TIMER_PAUSE),
                    array('text' => self::TIMER_STOP),
                ),
                array(
                    array('text' => 'Tagesübersicht'),
                    array('text' => 'Korrektur')
                ),
                array(
                    array('text' => 'Statistik'),
                    array('text' => 'Einstellungen',)
                )
            ),
            'resize_keyboard' => true
        )
    );

    const CRON_TIMING = 15;
    const MAX_PAUSE_LENGTH = 60;

    function __construct($cronMethod = null)
    {
        $this->config = parse_ini_file('../../../../config/worktimebot.ini', true);
        $this->connectDB();
        $content = file_get_contents("php://input");
        error_log($content);
        $update = json_decode($content, TRUE);
        if ($update) {
            if (isset($update['callback_query'])) {
                $this->sendCurlRequest(
                    'answerCallBackQuery',
                    json_encode(
                        array(
                            'callback_query_id' => $update['callback_query']['id']
                        )
                    )
                );
                $this->chatId = $update['callback_query']['message']['chat']['id'];
                $this->messageId = $update['callback_query']['message']['message_id'];
                $this->user = $this->getUser();
                switch ($update['callback_query']['data']) {
                    case 'setWorkingHours':
                        $this->sendMessage(
                            'sendMessage',
                            'Wie viele Stunden arbeitest du pro Woche?',
                            array(
                                'reply_markup' => array(
                                    'force_reply' => true
                                )
                            )
                        );
                        break;
                    case 'setWorkingDays':
                        $this->sendMessage(
                            'sendMessage',
                            'Wie viele Tage arbeitest du pro Woche?',
                            array(
                                'reply_markup' => array('force_reply' => true)
                            )
                        );
                        break;
                    case 'adjustWorkPlus':
                        $this->adjustWorkingTime('15', 'worktime');
                        $this->sendMessage(
                            'sendMessage',
                            self::FORCE_REPLY_ADJUST_WORKTIME_PLUS
                        );
                        break;
                    case 'adjustWorkMinus':
                        $this->adjustWorkingTime('-15', 'worktime');
                        $this->sendMessage(
                            'sendMessage',
                            self::FORCE_REPLY_ADJUST_WORKTIME_MINUS
                        );
                        break;
                    case 'adjustWorkCustom':
                        $this->sendMessage(
                            'sendMessage',
                            self::FORCE_REPLY_ADJUST_WORKTIME_CUSTOM,
                            array(
                                'reply_markup' => array('force_reply' => true)
                            )
                        );
                        break;
                    case 'adjustPausePlus':
                        $this->adjustWorkingTime('15', 'pausetime');
                        $this->sendMessage(
                            'sendMessage',
                            self::FORCE_REPLY_ADJUST_PAUSETIME_PLUS
                        );
                        break;
                    case 'adjustPauseMinus':
                        $this->adjustWorkingTime('-15', 'pausetime');
                        $this->sendMessage(
                            'sendMessage',
                            self::FORCE_REPLY_ADJUST_PAUSETIME_MINUS
                        );
                        break;
                    case 'adjustPauseCustom':
                        $this->sendMessage(
                            'sendMessage',
                            self::FORCE_REPLY_ADJUST_PAUSETIME_CUSTOM,
                            array(
                                'reply_markup' => array('force_reply' => true)
                            )
                        );
                        break;
                }
            } else if (isset($update["message"]["reply_to_message"])) {
                $this->chatId = $update["message"]["from"]["id"];
                $this->messageId = $update["message"]['message_id'];
                $this->user = $this->getUser();
                switch ($update["message"]["reply_to_message"]["text"]) {
                    case 'Wie viele Stunden arbeitest du pro Woche?':
                        $this->updateUser('hoursPerWeek', $update["message"]["text"]);
                        $this->sendMessage('sendMessage', 'Du arbeitest nun ' . $this->user->hoursPerWeek . ' Stunden pro Woche', self::MENU);
                        break;
                    case 'Wie viele Tage arbeitest du pro Woche?':
                        $this->updateUser('workingDays', $update["message"]["text"]);
                        $this->sendMessage('sendMessage', 'Du arbeitest nun ' . $this->user->workingDays . ' Tage pro Woche', self::MENU);
                        break;
                    case self::FORCE_REPLY_ADJUST_WORKTIME_CUSTOM:
                        $this->adjustWorkingTime($update["message"]["text"], 'worktime');
                        $this->sendMessage('sendMessage', 'Ich habe deine Arbeitszeit entsprechend aktualisieren', self::MENU);
                        break;
                    case self::FORCE_REPLY_ADJUST_PAUSETIME_CUSTOM:
                        $this->adjustWorkingTime($update["message"]["text"], 'pausetime');
                        $this->sendMessage('sendMessage', 'Ich habe deine Pausenzeit entsprechend aktualisieren', self::MENU);
                        break;
                    default:
                        $this->sendMessage('sendMessage', $update["message"]["reply_to_message"]["text"]);
                }
            } else {
                $this->chatId = $update["message"]["from"]["id"];
                $this->username = $update["message"]["from"]["first_name"];
                $this->user = $this->getUser();
                $this->messageId = $update["message"]['message_id'];
                if (!empty($update["message"])) {
                    $this->handleMessage($update);
                }
            }
        } else if ($cronMethod == 'checkTarget') {
            $chatIds = $this->getRunningUsers();
            foreach ($chatIds as $chatId) {
                $this->chatId = $chatId;
                $this->messageId = null;
                $this->user = $this->getUser();
                $timedata = $this->getTodayTotalTimes();
                if (
                    ($this->user->hoursPerDay < $timedata->worksum) and
                    (($this->user->hoursPerDay + self::CRON_TIMING / 60) > $timedata->worksum) and
                    ($this->user->userStatus === 'START')
                ) {
                    $this->sendMessage('sendMessage', 'Du arbeitest bereits ' . round($timedata->worksum, 1) . ' Stunden. Ab jetzt machst du Überstunden.');
                }
                if (
                    ($timedata->pausesum > self::MAX_PAUSE_LENGTH / 60) and
                    ((self::MAX_PAUSE_LENGTH / 60 + self::CRON_TIMING / 60) > $timedata->pausesum) and
                    ($this->user->userStatus === 'PAUSE')
                ) {
                    $this->sendMessage('sendMessage', 'Du machst bereits ' . round($timedata->pausesum, 1) . ' Stunden pause. Arbeitest du schon wieder?.');
                }
            }
        }
    }

    function connectDB()
    {
        $dsn = 'mysql:dbname=' . $this->config['database']['db_name'] . ';host=' . $this->config['database']['db_host'] . ';port=' . $this->config['database']['db_port'] . ';charset=utf8mb4';
        $user = $this->config['database']['db_user'];
        $password = $this->config['database']['db_password'];

        try {
            $this->dbh = new PDO($dsn, $user, $password);
        } catch (PDOException $e) {
            echo 'Connection failed: ' . $e->getMessage();
        }
    }

    function handleMessage($update, $method = 'sendMessage')
    {
        $this->user = $this->getUser();

        switch ($update['message']['text']) {
            case self::TIMER_START:
                if (in_array($this->user->userStatus, array('PAUSE', 'END'))) {
                    $this->answer = 'Na dann, viel Spaß!' . $this->getStatusIcon('START');
                    $this->updateWorkingTime('START');
                } else {
                    $this->answer = 'Du arbeitest bereits!' . $this->getStatusIcon($this->user->userStatus);
                }
                break;
            case self::TIMER_PAUSE:
                if (in_array($this->user->userStatus, array('START', 'CONTINUE'))) {
                    $this->answer = 'Erhol dich gut!' . $this->getStatusIcon('PAUSE');
                    $this->updateWorkingTime('PAUSE');
                } else {
                    $this->answer = 'Du machst bereits Pause oder hast deine Arbeit beendet!' . $this->getStatusIcon($this->user->userStatus);
                }
                break;
            case 'Weiter machen':
                if (in_array($this->user->userStatus, array('PAUSE'))) {
                    $this->answer = 'Ich hoffe, du hast dich gut erholt!' . $this->getStatusIcon('CONTINUE');
                    $this->updateWorkingTime('CONTINUE');
                } else {
                    $this->answer = 'Du arbeitest bereits oder hast deine Arbeit beendet!' . $this->getStatusIcon($this->user->userStatus);
                }
                break;
            case self::TIMER_STOP:
                if (in_array($this->user->userStatus, array('START', 'CONTINUE', 'PAUSE'))) {
                    $this->answer = 'Du hast heute viel geschafft!' . $this->getStatusIcon('END');
                    $this->updateWorkingTime('END');
                } else {
                    $this->answer = 'Du hast deine Arbeit bereits beendet!' . $this->getStatusIcon($this->user->userStatus);
                }
                break;
            case 'Tagesübersicht':
                $this->sendChatAction('typing');
                $this->sendDayOverview();
                break;
            case 'Korrektur':
                $this->sendMessage(
                    'sendMessage',
                    'Möchtest du deine heutigen Zeiten korrigieren?',
                    array(
                        'reply_markup' => array(
                            'inline_keyboard' => array(
                                array(
                                    array(
                                        'text' => 'Arbeit +15 Minuten',
                                        'callback_data' => 'adjustWorkPlus'
                                    ),
                                    array(
                                        'text' => '-15 Minuten',
                                        'callback_data' => 'adjustWorkMinus'
                                    ),
                                    array(
                                        'text' => "eigene\nAngabe",
                                        'callback_data' => 'adjustWorkCustom'
                                    ),
                                ),
                                array(
                                    array(
                                        'text' => 'Pause +15 Minuten',
                                        'callback_data' => 'adjustPausePlus'
                                    ),
                                    array(
                                        'text' => '-15 Minuten',
                                        'callback_data' => 'adjustPauseMinus'
                                    ),
                                    array(
                                        'text' => "eigene\nAngabe",
                                        'callback_data' => 'adjustPauseCustom'
                                    ),
                                )
                            )
                        )
                    )
                );
                break;
            case 'Statistik':
                $this->sendChatAction('typing');
                $this->sendStatistic();
                break;
            case 'Einstellungen':
                $this->sendMessage(
                    'sendMessage',
                    'Welche Einstellung möchtest du ändern?',
                    array(
                        'reply_markup' => array(
                            'inline_keyboard' => array(
                                array(
                                    array(
                                        'text' => $this->user->hoursPerWeek . ' Arbeitsstunden/Woche',
                                        'callback_data' => 'setWorkingHours'
                                    ),
                                    array(
                                        'text' => $this->user->workingDays . ' Arbeitstage/Woche',
                                        'callback_data' => 'setWorkingDays'
                                    ),
                                )
                            )
                        )
                    )
                );
                break;
            default:
                // $this->sendCurlRequest('answerCallBackQuery', json_encode(array('callback_query_id' => time())));
                // $this->answer = json_encode($update);
                $this->answer = 'Ich weis leider nicht, was du meinst. Bitte wähle einen der Buttons!';
        }
        if (isset($this->answer)) {
            $this->sendMessage(
                $method,
                $this->answer,
                self::MENU
            );
        }
    }

    function getRunningUsers()
    {
        $statement = $this->dbh->prepare('SELECT telegramId FROM ArbeitszeitUser WHERE userStatus != "END"');
        $statement->execute();
        $user_ids = $statement->fetchAll(PDO::FETCH_COLUMN, 0);
        return $user_ids;
    }

    function getUser()
    {
        $statement = $this->dbh->prepare('SELECT id, userStatus, LetztesUpdate, hoursPerWeek, workingDays, hoursPerWeek/workingDays as hoursPerDay FROM ArbeitszeitUser WHERE `telegramId` = ?');
        $statement->execute(array($this->chatId));
        $user = $statement->fetchObject();
        if (!$user) {
            $user = $this->addUser();
        }
        return $user;
    }

    function addUser()
    {
        $statement = $this->dbh->prepare('INSERT INTO ArbeitszeitUser (`telegramId`) VALUES (?)');
        $statement->execute(array($this->chatId));
        return $this->getUser();
    }

    function sendCurlRequest($method, $payload)
    {
        $ch = curl_init('https://api.telegram.org/bot1273940781:AAFArzw3KQ3OYXt3vJFRiGuOJjbb5J9WEZo/' . $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        # Return response instead of printing.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        # Send request.
        $result = curl_exec($ch);
        curl_close($ch);
        # Print response.
        echo $result;
        // echo $result;
    }

    function updateWorkingTime($mode)
    {
        $row = $this->getTimeData();
        if (isset($row->amount)) {
            $editStatement = $this->dbh->prepare('UPDATE ArbeitszeitTag SET zeiten = :zeiten WHERE id = :currentId');
            $editStatement->bindParam(':currentId', $row->id, PDO::PARAM_INT);
            $currentTimes = json_decode($row->zeiten, true);
        } else {
            $editStatement = $this->dbh->prepare('INSERT INTO ArbeitszeitTag (userId, zeiten) VALUES (:userId, :zeiten)');
            $editStatement->bindParam(':userId', $this->user->id, PDO::PARAM_INT);
            $currentTimes = array();
        }
        array_push($currentTimes, array(time() => array('mode' => $mode)));
        $currentTimesJson = json_encode($currentTimes);
        $editStatement->bindParam(':zeiten', $currentTimesJson, PDO::PARAM_STR);
        $editStatement->execute();

        $userStatusUpdateStatement = $this->dbh->prepare('UPDATE ArbeitszeitUser SET userStatus = :userStatus WHERE id = :userId');
        $userStatusUpdateStatement->bindParam(':userId', $this->user->id, PDO::PARAM_INT);
        $userStatusUpdateStatement->bindParam(':userStatus', $mode, PDO::PARAM_STR);
        $userStatusUpdateStatement->execute();
    }

    function adjustWorkingTime($amount, $kind)
    {
        $row = $this->getTimeData();
        if (strpos($amount, 'h') || strpos($amount, 'tunde')) {
            $amount *= 60;
        }
        if ($kind === 'worktime') {
            $editStatement = $this->dbh->prepare('UPDATE ArbeitszeitTag SET adjustWorktime = :amount WHERE id = :currentId');
            $amountInt = $row->adjustWorktimeSum + intval($amount);
        } else if ($kind === 'pausetime') {
            $editStatement = $this->dbh->prepare('UPDATE ArbeitszeitTag SET adjustPausetime = :amount WHERE id = :currentId');
            $amountInt = $row->adjustPausetimeSum + intval($amount);
        } else {
            return false;
        }
        $rowid = $row->id;
        $editStatement->bindParam(':currentId', $rowid, PDO::PARAM_INT);
        $editStatement->bindParam(':amount', $amountInt, PDO::PARAM_INT);
        $editStatement->execute();
    }

    function updateUser($key, $value)
    {

        $editStatement = $this->dbh->prepare('UPDATE ArbeitszeitUser SET hoursPerWeek = :hoursPerWeek, workingDays = :workingDays WHERE telegramId= :chatId');
        $editStatement->bindParam(':chatId', $this->chatId, PDO::PARAM_INT);

        $hoursPerWeek = $key === 'hoursPerWeek' ? $value : $this->user->hoursPerWeek;
        $editStatement->bindParam(
            ':hoursPerWeek',
            $hoursPerWeek,
            PDO::PARAM_STR
        );

        $workingDays = $key === 'workingDays' ? $value : $this->user->workingDays;
        $editStatement->bindParam(
            ':workingDays',
            $workingDays,
            PDO::PARAM_STR
        );
        $editStatement->execute();

        $this->user = $this->getUser();
    }

    function getTimeData($dateFrom = 0, $dateTo = 0)
    {
        $statementGetDay = $this->dbh->prepare(
            'SELECT COUNT(*) as amount, id, CONCAT("[", GROUP_CONCAT(SUBSTRING(zeiten,2,LENGTH(zeiten)-2)), "]") as zeiten, SUM(adjustWorktime) as adjustWorktimeSum, SUM(adjustPausetime) as adjustPausetimeSum FROM ArbeitszeitTag WHERE userId = :userId AND datum BETWEEN :dateFrom AND :dateTo GROUP by userId'
        );
        $statementGetDay->bindParam(':userId', $this->user->id, PDO::PARAM_INT);
        $dateCompareFrom = date('Y-m-d', strtotime("+" . $dateFrom . " days"));
        $statementGetDay->bindParam(':dateFrom', $dateCompareFrom, PDO::PARAM_STR);
        $dateCompareTo = date('Y-m-d', strtotime("+" . ($dateTo + 1) . " days"));
        $statementGetDay->bindParam(':dateTo', $dateCompareTo, PDO::PARAM_STR);
        $statementGetDay->execute();
        return $statementGetDay->fetchObject();
    }

    function getStatusIcon($status)
    {
        switch ($status) {
            case 'START':
                $currentStatus = "\xF0\x9F\x9A\x80";
                break;
            case 'PAUSE':
                $currentStatus = "\xF0\x9F\x9A\xA7";
                break;
            case 'CONTINUE':
                $currentStatus = "\xF0\x9F\x94\x83";
                break;
            case 'END':
                $currentStatus = "\xF0\x9F\x9A\xAB";
                break;
            default:
                $currentStatus = "\xE2\x81\x89";
        }
        return "\n(Dein aktueller Status: " . $currentStatus . ")";
    }

    /**
     * this needs $this->chatId and $this->messageId
     */
    function sendMessage($method = 'sendMessage', $answer = '', $additionalOptions = array())
    {
        $payload = json_encode(
            array_merge(
                array(
                    'chat_id' => $this->chatId,
                    'message_id' => $this->messageId,
                    'text' => $answer
                ),
                $additionalOptions
            )
        );
        $this->sendCurlRequest($method, $payload);
    }

    function sendStatistic()
    {
        // Create an image with the specified dimensions
        $imageWidth = 600;
        $imageHeight = 800;
        $barWidth = 100;
        $barPadding = 50;
        $topGap = 50;
        $image = imageCreate($imageWidth, $imageHeight);

        // Create a color (this first call to imageColorAllocate
        //  also automatically sets the image background color)
        imageColorAllocate($image, 255, 255, 255);
        $colorRed = imageColorAllocate($image, 255, 0, 0);
        $colorWork = imageColorAllocate($image, 255, 200, 0);
        $colorPause = imageColorAllocate($image, 0, 255, 0);
        $colorBlack = imageColorAllocate($image, 0, 0, 0);
        $colorBlue = imageColorAllocate($image, 200, 200, 255);
        imagesetthickness($image, 3);

        $worktimes = $this->getWorktimes();
        /**
         * TODAY
         */
        imageFilledRectangle($image, 0, $topGap - 5, $barWidth + 2 * $barPadding, $topGap, $colorRed);

        $fullHeight = $imageHeight - $topGap;
        //today -- work
        $workheight = $imageHeight - $fullHeight * $worktimes['today']['work'];
        imageFilledRectangle(
            $image,
            $barPadding,
            $imageHeight,
            $barWidth + $barPadding,
            $workheight,
            $colorWork
        );
        imagettftext(
            $image,
            20,
            5,
            $barPadding - 30,
            $imageHeight - 30,
            $colorRed,
            './FreeSans.ttf',
            'Arbeit: ' . round($this->user->hoursPerWeek / $this->user->workingDays * $worktimes['today']['work'], 1) . ' Stunden'
        );
        //today -- pause
        $pauseheight = $workheight - $fullHeight * $worktimes['today']['pause'];
        imageFilledRectangle($image, $barPadding, $workheight, $barWidth + $barPadding, $pauseheight, $colorPause);
        imagettftext(
            $image,
            20,
            5,
            $barPadding - 30,
            $workheight - 10,
            $colorRed,
            './FreeSans.ttf',
            'Pause: ' . round($this->user->hoursPerWeek / $this->user->workingDays * $worktimes['today']['pause'], 1) . ' Stunden'
        );
        imagettftext($image, 15, 0, $barPadding + 20, $imageHeight - 10, $colorBlack, './FreeSans.ttf', 'Heute');
        imagettftext(
            $image,
            20,
            5,
            $barPadding - 30,
            40,
            $colorRed,
            './FreeSans.ttf',
            'Zielzeit: ' . $this->user->hoursPerWeek / $this->user->workingDays . ' Stunden'
        );

        /**
         * WEEK
         */
        imageFilledRectangle($image, $barWidth + 4 * $barPadding, $topGap - 5, 2 * $barWidth + 6 * $barPadding, $topGap, $colorRed);

        //week -- work
        $workheight = $imageHeight - $fullHeight * $worktimes['week']['work'];
        imageFilledRectangle($image, $barWidth + 5 * $barPadding, $imageHeight, 2 * $barWidth + 5 * $barPadding, $workheight, $colorWork);
        imagettftext(
            $image,
            20,
            5,
            $barWidth + 4 * $barPadding,
            $imageHeight - 30,
            $colorRed,
            './FreeSans.ttf',
            'Arbeit: ' . round($this->user->hoursPerWeek * $worktimes['week']['work'], 1) . ' Stunden'
        );
        //week -- pause
        $pauseheight = $workheight - $fullHeight * $worktimes['week']['pause'];
        imageFilledRectangle($image, $barWidth + 5 * $barPadding, $workheight, 2 * $barWidth + 5 * $barPadding, $pauseheight, $colorPause);
        imagettftext(
            $image,
            20,
            5,
            $barWidth + 4 * $barPadding,
            $workheight - 10,
            $colorRed,
            './FreeSans.ttf',
            'Pause: ' . round($this->user->hoursPerWeek * $worktimes['week']['pause'], 1) . ' Stunden'
        );
        imagettftext($image, 15, 0, $barWidth + 5 * $barPadding + 20, $imageHeight - 10, $colorBlack, './FreeSans.ttf', 'Woche');
        imagettftext($image, 20, 5, $barWidth + 4 * $barPadding, 40, $colorRed, './FreeSans.ttf', 'Zielzeit: ' . number_format($this->user->hoursPerWeek, 1, ',', '.') . ' Stunden');
        for ($day = 1; $day < $this->user->workingDays; $day++) {
            imageline(
                $image,
                $barWidth + 4 * $barPadding,
                $imageHeight - ($fullHeight / $this->user->workingDays * $day),
                2 * $barWidth + 6 * $barPadding,
                $imageHeight - ($fullHeight / $this->user->workingDays * $day),
                (date('N') == $day) ? $colorBlue : $colorBlack
            );
        }

        $currentTime = time();

        // Set type of image and send the output
        imagepng($image, './images/workingtimeBotStats/' . $this->chatId . $currentTime . '.png');

        // Release memory
        imageDestroy($image);

        $payload = json_encode(
            array_merge(
                array(
                    'chat_id' => $this->chatId,
                    'photo' => 'https://baunach-erleben.de/images/workingtimeBotStats/' . $this->chatId . $currentTime . '.png',
                    'disable_notification' => false
                )
            )
        );
        $this->sendCurlRequest('sendPhoto', $payload);
        unlink('./images/workingtimeBotStats/' . $this->chatId . $currentTime . '.png');
    }

    function sendDayOverview()
    {
        // Create an image with the specified dimensions
        $imageWidth = 1000;
        $imageHeight = 300;
        $barHeight = 100;
        $image = imageCreate($imageWidth, $imageHeight);

        // Create a color (this first call to imageColorAllocate
        //  also automatically sets the image background color)
        imageColorAllocate($image, 255, 255, 255);
        $colorWork = imageColorAllocate($image, 255, 200, 0);
        $colorPause = imageColorAllocate($image, 0, 255, 0);
        $colorBlack = imageColorAllocate($image, 0, 0, 0);
        imagesetthickness($image, 3);
        $timedata = $this->getTimeData();
        $times = json_decode($timedata->zeiten, true);

        $xpos = 50;
        $laststate = 'UNDEFINED';
        $newxpos = 0;
        $starttime = key($times[array_key_first($times)]);
        $lasttime = $starttime;
        $endtime = key($times[array_key_last($times)]);
        $timediff = $endtime - $starttime;
        $secondlength = ($imageWidth - 100) / ($timediff + 1);
        foreach ($times as $time) {
            $timestamp = key($time);
            $newxpos = $xpos + ($timestamp - $lasttime) * $secondlength;
            if ($newxpos == 50) {
                imagettftext($image, 15, 0, $xpos - 20, 125 + $barHeight, $colorBlack, './FreeSans.ttf', date('H:i', $timestamp));
            }
            if (in_array($laststate, array('START', 'CONTINUE', 'PAUSE'))) {
                switch ($laststate) {
                    case 'START':
                    case 'CONTINUE':
                        $currentColor = $colorWork;
                        break;
                    case 'PAUSE':
                        $currentColor = $colorPause;
                        break;
                    default:
                        $currentColor = $colorBlack;
                }
                imagefilledrectangle($image, $xpos, 100 + $barHeight, $newxpos, 100, $currentColor);
                imagettftext($image, 15, 0, $newxpos - 20, 125 + $barHeight, $colorBlack, './FreeSans.ttf', date('H:i', $timestamp));
            }
            $lasttime = $timestamp;
            $laststate = current($time)['mode'];
            $xpos = $newxpos;
        }
        imagettftext($image, 15, 0, 30, 150 + $barHeight, $colorBlack, './FreeSans.ttf', 'Arbeitszeitkorrektur: ' . $timedata->adjustWorktimeSum . " Minuten");
        imagettftext($image, 15, 0, 430, 150 + $barHeight, $colorBlack, './FreeSans.ttf', 'Pausenzeitkorrektur: ' . $timedata->adjustPausetimeSum . " Minuten");

        $currentTime = time();

        // Set type of image and send the output
        imagepng($image, './images/workingtimeBotStats/' . $this->chatId . $currentTime . '.png');

        // Release memory
        imageDestroy($image);

        $payload = json_encode(
            array_merge(
                array(
                    'chat_id' => $this->chatId,
                    'photo' => 'https://baunach-erleben.de/images/workingtimeBotStats/' . $this->chatId . $currentTime . '.png',
                    'disable_notification' => false
                )
            )
        );
        $this->sendCurlRequest('sendPhoto', $payload);
        unlink('./images/workingtimeBotStats/' . $this->chatId . $currentTime . '.png');
        $this->answer = 'Das ist deine heutige Zeitübersicht. Orange ist arbeit - Grün ist Pause.';
    }

    function getTodayTotalTimes()
    {
        $worksum = 0;
        $pausesum = 0;
        $lastState = 'UNKNOWN';
        $lastTimeStamp = strtotime('today');
        $slotCount = 0;
        $timedata = $this->getTimeData();
        foreach (json_decode($timedata->zeiten, true) as $timeslotData) {
            $timeStamp = key($timeslotData);
            $timediff = $timeStamp - $lastTimeStamp;
            if ($lastState !== 'UNKNOWN') {
                switch ($lastState) {
                    case 'START':
                    case 'CONTINUE':
                        $worksum += $timediff / 3600;
                        break;
                    case 'PAUSE':
                        $pausesum += $timediff / 3600;
                        break;
                }
            }
            $lastState = current($timeslotData)['mode'];
            $lastTimeStamp = $timeStamp;
            $slotCount++;
        }
        $timediff = time() - $lastTimeStamp;
        switch ($this->user->userStatus) {
            case 'START':
            case 'CONTINUE':
                $worksum += $timediff / 3600;
                break;
            case 'PAUSE':
                $pausesum += $timediff / 3600;
                break;
        }
        $worksum += $timedata->adjustWorktimeSum / 60;
        $pausesum += $timedata->adjustPausetimeSum / 60;

        $timesums = new stdClass();
        $timesums->worksum = $worksum;
        $timesums->pausesum = $pausesum;
        return $timesums;
    }

    function getWorktimes()
    {
        $timesums = $this->getTodayTotalTimes();

        $this->answer = 'Heute hast du ' . round($timesums->worksum, 1) . ' Stunden gearbeitet und ' . round($timesums->pausesum, 1) . ' Stunden pausiert.' . $this->getStatusIcon($this->user->userStatus);

        $hoursperday = $this->user->hoursPerWeek / $this->user->workingDays;
        $worksumToday = $timesums->worksum;
        $pausesumToday = $timesums->pausesum;

        $worksum = 0;
        $pausesum = 0;
        $lastState = 'UNKNOWN';
        $lastTimeStamp = strtotime('today');
        $slotCount = 0;
        $weektimes = $this->getTimeData(-date('N') + 1);
        foreach (json_decode($weektimes->zeiten, true) as $timeslotData) {
            $timeStamp = key($timeslotData);
            $timediff = $timeStamp - $lastTimeStamp;
            if ($lastState !== 'UNKNOWN') {
                switch ($lastState) {
                    case 'START':
                    case 'CONTINUE':
                        $worksum += $timediff / 3600;
                        break;
                    case 'PAUSE':
                        $pausesum += $timediff / 3600;
                        break;
                }
            }
            $lastState = current($timeslotData)['mode'];
            $lastTimeStamp = $timeStamp;
            $slotCount++;
        }
        $timediff = time() - $lastTimeStamp;
        switch ($this->user->userStatus) {
            case 'START':
            case 'CONTINUE':
                $worksum += $timediff / 3600;
                break;
            case 'PAUSE':
                $pausesum += $timediff / 3600;
                break;
        }
        $worksum += $weektimes->adjustWorktimeSum / 60;
        $pausesum += $weektimes->adjustPausetimeSum / 60;

        return array(
            'today' => array('work' => $worksumToday / $hoursperday, 'pause' => $pausesumToday / $hoursperday),
            'week' => array('work' => $worksum / $this->user->hoursPerWeek, 'pause' => $pausesum / $this->user->hoursPerWeek),
            'total' => array('work' => 0.8, 'pause' => 0.1)
        );
    }

    function sendChatAction($action)
    {
        $payload = json_encode(
            array_merge(
                array(
                    'chat_id' => $this->chatId,
                    'action' => $action
                )
            )
        );
        $this->sendCurlRequest('sendChatAction', $payload);
    }
}
