<?php
class Database
{
    public function __construct($chatId)
    {
        $this->chatId = $chatId;
        $this->config = parse_ini_file('./worktimebot.ini.php', true);
        $dsn = 'mysql:dbname=' . $this->config['database']['db_name'] . ';host=' . $this->config['database']['db_host'] . ';port=' . $this->config['database']['db_port'] . ';charset=utf8mb4';
        $user = $this->config['database']['db_user'];
        $password = $this->config['database']['db_password'];
        
        try {
            $this->dbh = new \PDO($dsn, $user, $password);
        } catch (\PDOException $e) {
            echo 'Connection failed: ' . $e->getMessage();
        }
        $this->user = $this->getUser();
    }

    public function addUser()
    {
        $statement = $this->dbh->prepare('INSERT INTO ArbeitszeitUser (`telegramId`) VALUES (?)');
        $statement->execute(array($this->chatId));
        return $this->getUser();
    }

    public function getRunningUsers()
    {
        $statement = $this->dbh->prepare('SELECT telegramId FROM ArbeitszeitUser WHERE userStatus != "END"');
        $statement->execute();
        $user_ids = $statement->fetchAll(\PDO::FETCH_COLUMN, 0);
        return $user_ids;
    }

    public function getUser()
    {
        $statement = $this->dbh->prepare('SELECT id, userStatus, LetztesUpdate, hoursPerWeek, workingDays, hoursPerWeek/workingDays as hoursPerDay FROM ArbeitszeitUser WHERE `telegramId` = ?');
        $statement->execute(array($this->chatId));
        $user = $statement->fetchObject();
        if (!$user) {
            $user = $this->addUser();
        }
        return $user;
    }

    public function adjustWorkingTime($amount, $kind)
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
        $editStatement->bindParam(':currentId', $rowid, \PDO::PARAM_INT);
        $editStatement->bindParam(':amount', $amountInt, \PDO::PARAM_INT);
        $editStatement->execute();
    }

    public function getTimeData($dateFrom = 0, $dateTo = 0)
    {
        $statementGetDay = $this->dbh->prepare(
            'SELECT COUNT(*) as amount, id, CONCAT("[", GROUP_CONCAT(SUBSTRING(zeiten,2,LENGTH(zeiten)-2)), "]") as zeiten, SUM(adjustWorktime) as adjustWorktimeSum, SUM(adjustPausetime) as adjustPausetimeSum FROM ArbeitszeitTag WHERE userId = :userId AND datum BETWEEN :dateFrom AND :dateTo GROUP by userId'
        );
        $statementGetDay->bindParam(':userId', $this->user->id, \PDO::PARAM_INT);
        $dateCompareFrom = date('Y-m-d', strtotime("+" . $dateFrom . " days"));
        $statementGetDay->bindParam(':dateFrom', $dateCompareFrom, \PDO::PARAM_STR);
        $dateCompareTo = date('Y-m-d', strtotime("+" . ($dateTo + 1) . " days"));
        $statementGetDay->bindParam(':dateTo', $dateCompareTo, \PDO::PARAM_STR);
        $statementGetDay->execute();
        return $statementGetDay->fetchObject();
    }

    public function updateUser($user, $key, $value)
    {
        $editStatement = $this->dbh->prepare('UPDATE ArbeitszeitUser SET hoursPerWeek = :hoursPerWeek, workingDays = :workingDays WHERE telegramId= :chatId');
        $editStatement->bindParam(':chatId', $this->chatId, \PDO::PARAM_INT);

        $hoursPerWeek = $key === 'hoursPerWeek' ? $value : $user->hoursPerWeek;
        $editStatement->bindParam(
            ':hoursPerWeek',
            $hoursPerWeek,
            \PDO::PARAM_STR
        );

        $workingDays = $key === 'workingDays' ? $value : $user->workingDays;
        $editStatement->bindParam(
            ':workingDays',
            $workingDays,
            \PDO::PARAM_STR
        );
        $editStatement->execute();

        return $this->getUser();
    }

    function updateWorkingTime($mode)
    {
        $row = $this->getTimeData();
        if (isset($row->amount)) {
            $editStatement = $this->dbh->prepare('UPDATE ArbeitszeitTag SET zeiten = :zeiten WHERE id = :currentId');
            $editStatement->bindParam(':currentId', $row->id, \PDO::PARAM_INT);
            $currentTimes = json_decode($row->zeiten, true);
        } else {
            $editStatement = $this->dbh->prepare('INSERT INTO ArbeitszeitTag (userId, zeiten) VALUES (:userId, :zeiten)');
            $editStatement->bindParam(':userId', $this->user->id, \PDO::PARAM_INT);
            $currentTimes = array();
        }
        array_push($currentTimes, array(time() => array('mode' => $mode)));
        $currentTimesJson = json_encode($currentTimes);
        $editStatement->bindParam(':zeiten', $currentTimesJson, \PDO::PARAM_STR);
        $editStatement->execute();

        $userStatusUpdateStatement = $this->dbh->prepare('UPDATE ArbeitszeitUser SET userStatus = :userStatus WHERE id = :userId');
        $userStatusUpdateStatement->bindParam(':userId', $this->user->id, \PDO::PARAM_INT);
        $userStatusUpdateStatement->bindParam(':userStatus', $mode, \PDO::PARAM_STR);
        $userStatusUpdateStatement->execute();
    }
}
