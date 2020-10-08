<?php

require_once('Telegram.class.php');
require_once('Database.class.php');
require_once('Strings.class.php');
class ArbeitszeitBot
{
    const CRON_TIMING = 15;
    const MAX_PAUSE_LENGTH = 60;

    function __construct($cronMethod = null)
    {
        $this->config = parse_ini_file('./worktimebot.ini.php', true);
        $content = file_get_contents("php://input");
        error_log($content);
        $update = json_decode($content, TRUE);
        if ($update) {
            if (isset($update['callback_query'])) {
                $this->messageId = $update['callback_query']['message']['message_id'];
                $this->chatId = $update['callback_query']['message']['chat']['id'];
                $this->telegram = new Telegram($this->chatId, $this->messageId);
                $this->dbh = new Database($this->chatId);
                $this->telegram->sendCurlRequest(
                    'answerCallBackQuery',
                    json_encode(
                        array(
                            'callback_query_id' => $update['callback_query']['id']
                        )
                    )
                );
                $this->user = $this->dbh->getUser();
                switch ($update['callback_query']['data']) {
                    case 'setWorkingHours':
                        $this->telegram->sendMessage(
                            'sendMessage',
                            Strings::QUESTION_SETUP_WORKHOURS,
                            array(
                                'reply_markup' => array(
                                    'force_reply' => true
                                )
                            )
                        );
                        break;
                    case 'setWorkingDays':
                        $this->telegram->sendMessage(
                            'sendMessage',
                            Strings::QUESTION_SETUP_WORKDAYS,
                            array(
                                'reply_markup' => array('force_reply' => true)
                            )
                        );
                        break;
                    case 'adjustWorkPlus':
                        $this->dbh->adjustWorkingTime('15', 'worktime');
                        $this->telegram->sendMessage(
                            'sendMessage',
                            Strings::FORCE_REPLY_ADJUST_WORKTIME_PLUS
                        );
                        break;
                    case 'adjustWorkMinus':
                        $this->dbh->adjustWorkingTime('-15', 'worktime');
                        $this->telegram->sendMessage(
                            'sendMessage',
                            Strings::FORCE_REPLY_ADJUST_WORKTIME_MINUS
                        );
                        break;
                    case 'adjustWorkCustom':
                        $this->telegram->sendMessage(
                            'sendMessage',
                            Strings::FORCE_REPLY_ADJUST_WORKTIME_CUSTOM,
                            array(
                                'reply_markup' => array('force_reply' => true)
                            )
                        );
                        break;
                    case 'adjustPausePlus':
                        $this->dbh->adjustWorkingTime('15', 'pausetime');
                        $this->telegram->sendMessage(
                            'sendMessage',
                            Strings::FORCE_REPLY_ADJUST_PAUSETIME_PLUS
                        );
                        break;
                    case 'adjustPauseMinus':
                        $this->dbh->adjustWorkingTime('-15', 'pausetime');
                        $this->telegram->sendMessage(
                            'sendMessage',
                            Strings::FORCE_REPLY_ADJUST_PAUSETIME_MINUS
                        );
                        break;
                    case 'adjustPauseCustom':
                        $this->telegram->sendMessage(
                            'sendMessage',
                            Strings::FORCE_REPLY_ADJUST_PAUSETIME_CUSTOM,
                            array(
                                'reply_markup' => array('force_reply' => true)
                            )
                        );
                        break;
                }
            } else if (isset($update["message"]["reply_to_message"])) {
                $this->chatId = $update["message"]["from"]["id"];
                $this->messageId = $update["message"]['message_id'];
                $this->telegram = new Telegram($this->chatId, $this->messageId);
                $this->dbh = new Database($this->chatId);
                $this->user = $this->dbh->getUser();
                switch ($update["message"]["reply_to_message"]["text"]) {
                    case Strings::QUESTION_SETUP_WORKHOURS:
                        $this->user = $this->dbh->updateUser($this->user, 'hoursPerWeek', $update["message"]["text"]);
                        $this->telegram->sendMessage('sendMessage', sprintf(Strings::CONFIRM_SETUP_WORKHOURS, $this->user->hoursPerWeek), Strings::MENU);
                        break;
                    case Strings::QUESTION_SETUP_WORKDAYS:
                        $this->user = $this->dbh->updateUser($this->user, 'workingDays', $update["message"]["text"]);
                        $this->telegram->sendMessage('sendMessage', sprintf(Strings::CONFIRM_SETUP_WORKDAYS, $this->user->workingDays), Strings::MENU);
                        break;
                    case Strings::FORCE_REPLY_ADJUST_WORKTIME_CUSTOM:
                        $amount = $this->dbh->adjustWorkingTime($update["message"]["text"], 'worktime');
                        $this->telegram->sendMessage('sendMessage', sprintf(Strings::CONFIRM_UPDATE_WORKTIME, $amount), Strings::MENU);
                        break;
                    case Strings::FORCE_REPLY_ADJUST_PAUSETIME_CUSTOM:
                        $amount = $this->dbh->adjustWorkingTime($update["message"]["text"], 'pausetime');
                        $this->telegram->sendMessage('sendMessage', sprintf(Strings::CONFIRM_UPDATE_PAUSETIME, $amount), Strings::MENU);
                        break;
                    default:
                        $this->telegram->sendMessage('sendMessage', $update["message"]["reply_to_message"]["text"]);
                }
            } else {
                $this->chatId = $update["message"]["from"]["id"];
                $this->username = $update["message"]["from"]["first_name"];
                $this->messageId = $update["message"]['message_id'];
                $this->telegram = new Telegram($this->chatId, $this->messageId);
                $this->dbh = new Database($this->chatId);
                $this->user = $this->dbh->getUser();
                if (!empty($update["message"])) {
                    $this->handleMessage($update);
                }
            }
        } else if ($cronMethod == 'checkTarget') {
            $this->dbh = new Database();
            $chatIds = $this->dbh->getRunningUsers();
            foreach ($chatIds as $chatId) {
                $this->chatId = $chatId;
                $this->messageId = null;
                $this->telegram = new Telegram($this->chatId, $this->messageId);
                $this->dbh = new Database($this->chatId);
                $this->user = $this->dbh->getUser();
                $timedata = $this->getTodayTotalTimes();
                if (
                    ($this->user->hoursPerDay < $timedata->worksum) and
                    (($this->user->hoursPerDay + self::CRON_TIMING / 60) > $timedata->worksum) and
                    ($this->user->userStatus === 'START')
                ) {
                    $this->telegram->sendMessage('sendMessage', sprintf(Strings::HINT_WORKTIME, round($timedata->worksum, 1)));
                }
                if (
                    ($timedata->pausesum > self::MAX_PAUSE_LENGTH / 60) and
                    ((self::MAX_PAUSE_LENGTH / 60 + self::CRON_TIMING / 60) > $timedata->pausesum) and
                    ($this->user->userStatus === 'PAUSE')
                ) {
                    $this->telegram->sendMessage('sendMessage', sprintf(Strings::HINT_PAUSETIME, round($timedata->pausesum, 1)));
                }
            }
        }
    }

    function handleMessage($update, $method = 'sendMessage')
    {
        $this->user = $this->dbh->getUser();

        switch ($update['message']['text']) {
            case Strings::TIMER_START:
                if (in_array($this->user->userStatus, array('PAUSE', 'END'))) {
                    $this->answer = 'Na dann, viel Spaß!' . $this->getStatusIcon('START');
                    $this->dbh->updateWorkingTime('START');
                } else {
                    $this->answer = 'Du arbeitest bereits!' . $this->getStatusIcon($this->user->userStatus);
                }
                break;
            case Strings::TIMER_PAUSE:
                if (in_array($this->user->userStatus, array('START', 'CONTINUE'))) {
                    $this->answer = 'Erhol dich gut!' . $this->getStatusIcon('PAUSE');
                    $this->dbh->updateWorkingTime('PAUSE');
                } else {
                    $this->answer = 'Du machst bereits Pause oder hast deine Arbeit beendet!' . $this->getStatusIcon($this->user->userStatus);
                }
                break;
            case 'Weiter machen':
                if (in_array($this->user->userStatus, array('PAUSE'))) {
                    $this->answer = 'Ich hoffe, du hast dich gut erholt!' . $this->getStatusIcon('CONTINUE');
                    $this->dbh->updateWorkingTime('CONTINUE');
                } else {
                    $this->answer = 'Du arbeitest bereits oder hast deine Arbeit beendet!' . $this->getStatusIcon($this->user->userStatus);
                }
                break;
            case Strings::TIMER_STOP:
                if (in_array($this->user->userStatus, array('START', 'CONTINUE', 'PAUSE'))) {
                    $this->answer = 'Du hast heute viel geschafft!' . $this->getStatusIcon('END');
                    $this->dbh->updateWorkingTime('END');
                } else {
                    $this->answer = 'Du hast deine Arbeit bereits beendet!' . $this->getStatusIcon($this->user->userStatus);
                }
                break;
            case 'Tagesübersicht':
                $this->telegram->sendChatAction('typing');
                $this->sendDayOverview();
                break;
            case 'Korrektur':
                $this->telegram->sendMessage(
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
                $this->telegram->sendChatAction('typing');
                $this->sendStatistic();
                break;
            case 'Einstellungen':
                $this->telegram->sendMessage(
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
            $this->telegram->sendMessage(
                $method,
                $this->answer,
                Strings::MENU
            );
        }
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
            'FreeSans.ttf',
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
            'FreeSans.ttf',
            'Pause: ' . round($this->user->hoursPerWeek / $this->user->workingDays * $worktimes['today']['pause'], 1) . ' Stunden'
        );
        imagettftext($image, 15, 0, $barPadding + 20, $imageHeight - 10, $colorBlack, 'FreeSans.ttf', 'Heute');
        imagettftext(
            $image,
            20,
            5,
            $barPadding - 30,
            40,
            $colorRed,
            'FreeSans.ttf',
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
            'FreeSans.ttf',
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
            'FreeSans.ttf',
            'Pause: ' . round($this->user->hoursPerWeek * $worktimes['week']['pause'], 1) . ' Stunden'
        );
        imagettftext($image, 15, 0, $barWidth + 5 * $barPadding + 20, $imageHeight - 10, $colorBlack, 'FreeSans.ttf', 'Woche');
        imagettftext($image, 20, 5, $barWidth + 4 * $barPadding, 40, $colorRed, 'FreeSans.ttf', 'Zielzeit: ' . number_format($this->user->hoursPerWeek, 1, ',', '.') . ' Stunden');
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
        imagepng($image, 'images/workingtimeBotStats/' . $this->chatId . $currentTime . '.png');

        // Release memory
        imageDestroy($image);

        $payload = json_encode(
            array_merge(
                array(
                    'chat_id' => $this->chatId,
                    'photo' => 'https://baunach-erleben.de/bots/worktimebot/images/workingtimeBotStats/' . $this->chatId . $currentTime . '.png',
                    'disable_notification' => false
                )
            )
        );
        $this->telegram->sendCurlRequest('sendPhoto', $payload);
        unlink('images/workingtimeBotStats/' . $this->chatId . $currentTime . '.png');
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
        $timedata = $this->dbh->getTimeData();
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
                imagettftext($image, 15, 0, $xpos - 20, 125 + $barHeight, $colorBlack, 'FreeSans.ttf', date('H:i', $timestamp));
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
                imagettftext($image, 15, 0, $newxpos - 20, 125 + $barHeight, $colorBlack, 'FreeSans.ttf', date('H:i', $timestamp));
            }
            $lasttime = $timestamp;
            $laststate = current($time)['mode'];
            $xpos = $newxpos;
        }
        imagettftext($image, 15, 0, 30, 150 + $barHeight, $colorBlack, 'FreeSans.ttf', 'Arbeitszeitkorrektur: ' . $timedata->adjustWorktimeSum . " Minuten");
        imagettftext($image, 15, 0, 430, 150 + $barHeight, $colorBlack, 'FreeSans.ttf', 'Pausenzeitkorrektur: ' . $timedata->adjustPausetimeSum . " Minuten");

        $currentTime = time();

        // Set type of image and send the output
        imagepng($image, 'images/workingtimeBotStats/' . $this->chatId . $currentTime . '.png');

        // Release memory
        imageDestroy($image);

        $payload = json_encode(
            array_merge(
                array(
                    'chat_id' => $this->chatId,
                    'photo' => 'https://baunach-erleben.de/bots/worktimebot/images/workingtimeBotStats/' . $this->chatId . $currentTime . '.png',
                    'disable_notification' => false
                )
            )
        );
        $this->telegram->sendCurlRequest('sendPhoto', $payload);
        unlink('images/workingtimeBotStats/' . $this->chatId . $currentTime . '.png');
        $this->answer = 'Das ist deine heutige Zeitübersicht. Orange ist arbeit - Grün ist Pause.';
    }

    function getTodayTotalTimes()
    {
        $worksum = 0;
        $pausesum = 0;
        $lastState = 'UNKNOWN';
        $lastTimeStamp = strtotime('today');
        $slotCount = 0;
        $timedata = $this->dbh->getTimeData();
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

        $timesums = new \stdClass();
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
        $weektimes = $this->dbh->getTimeData(-date('N') + 1);
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
}
