<?php

class Strings {
    const TIMER_START = "\xe2\x96\xb6";
    const TIMER_PAUSE = "\xe2\x8f\xb8";
    const TIMER_STOP = "\xe2\x8f\xb9";

    const FORCE_REPLY_ADJUST_WORKTIME_PLUS = '+15 Minuten Arbeit';
    const FORCE_REPLY_ADJUST_WORKTIME_MINUS = '-15 Minuten Arbeit';
    const FORCE_REPLY_ADJUST_WORKTIME_CUSTOM = 'Sende mir deine Arbeitszeit-Korrektur als z.B. -1,5h oder 90m';
    const FORCE_REPLY_ADJUST_PAUSETIME_PLUS = '+15 Minuten Pause';
    const FORCE_REPLY_ADJUST_PAUSETIME_MINUS = '-15 Minuten Pause';
    const FORCE_REPLY_ADJUST_PAUSETIME_CUSTOM = 'Sende mir deine Pausenzeit-Korrektur als z.B. -1,5h oder 90m';

    const QUESTION_SETUP_WORKHOURS = 'Wie viele Stunden arbeitest du pro Woche?';
    const QUESTION_SETUP_WORKDAYS = 'Wie viele Tage arbeitest du pro Woche?';

    const CONFIRM_SETUP_WORKHOURS = 'Du arbeitest nun %d Stunden pro Woche';
    const CONFIRM_SETUP_WORKDAYS = 'Du arbeitest nun %d Tage pro Woche';
    const CONFIRM_UPDATE_WORKTIME = 'Ich habe deine Arbeitszeit entsprechend um %d Minuten aktualisiert';
    const CONFIRM_UPDATE_PAUSETIME = 'Ich habe deine Pausenzeit entsprechend um %d Minuten aktualisiert';

    const HINT_WORKTIME = 'Du arbeitest bereits %f Stunden. Ab jetzt machst du Überstunden.';
    const HINT_PAUSETIME = 'Du machst bereits seit %f Stunden Pause. Arbeitest du schon wieder?.';
    const HINT_NO_TIME_DATA = 'Du hast heute noch keine Arbeitszeit gebucht. Leider kann ich so nichts korrigieren.';

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

    public function __construct($lang = 'de') {

    }


}