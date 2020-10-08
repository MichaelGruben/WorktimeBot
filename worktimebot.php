<?php
require_once('ArbeitszeitBot.class.php');

error_reporting(E_ALL);
setlocale(LC_ALL, 'de_DE');
$config = parse_ini_file('worktimebot.ini.php', true);
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
