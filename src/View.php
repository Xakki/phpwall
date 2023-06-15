<?php

namespace Xakki\PHPWall;

use Exception;

class View
{
    const RULE_TYPE_MAP = [
        PHPWall::RULE_IP => 'IP',
        PHPWall::RULE_UA => 'UA',
        PHPWall::RULE_POST => 'POST',
        PHPWall::RULE_URL => 'URL',
    ];

    /** @var string */
    protected $secretRequest;
    /** @var string */
    protected $secretRequestRemove;
    /** @var Db */
    protected $conn;
    /** @var Cache */
    protected $cache;

    /** @var array<int, string> */
    private $trustList = [
        PHPWall::TRUST_WHITE_LIST => 'Search',
        PHPWall::TRUST_DEFAULT => '-',
        PHPWall::TRUST_CAPTCHA => 'CAPTCHA',
        PHPWall::TRUST_CONTROL => 'Control',
    ];
    /** @var PHPWall */
    private $owner;

    /**
     * @param PHPWall $owner
     * @param Db $conn
     * @param Cache $cache
     * @param string $secretRequest
     */
    public function __construct(PHPWall $owner, Db $conn, Cache $cache, $secretRequest)
    {
        $this->owner = $owner;
        $this->conn = $conn;
        $this->cache = $cache;
        $this->secretRequest = $secretRequest;
        $this->secretRequestRemove = $secretRequest . '-rm';
    }

    /**
     * @return void
     */
    public function dispatch()
    {
        if (isset($_GET['_logip'])) {
            $this->renderLogView((string)$_GET['_logip']);
        } else {
            $this->renderTabView();
        }
        exit('');
    }

    /**
     * @param string $ip
     * @return void
     */
    protected function renderLogView($ip)
    {
        $this->renderHeader();
        $this->printIpInfo($ip, $this->secretRequestRemove);

        try {
            $dataMain = $this->conn->getMainByIp($ip);
            if ($dataMain) {
                $this->printTable($this->prepareViewMainData([$dataMain]));
            }

            $data = $this->conn->getAllLogByIp($ip);
            $rows = [];
            foreach ($data as $r) {
                $rows[] = [
                    'id' => $r['id'],
                    'Date' => $r['create'],
                    'Try' => $r['try'],
                    'rule' => !empty(self::RULE_TYPE_MAP[$r['rule']]) ? self::RULE_TYPE_MAP[$r['rule']] : 'Unknown',
                    'data' => htmlspecialchars((string)$r['data']),
                ];
            }
            $this->printTable($rows);
        } catch (Exception $e) {
            echo "<div class=\"alert alert-danger\">Error: {$e->getMessage()}</div>";
        }
    }

    /**
     * @return void
     */
    protected function renderTabView()
    {
        $tab = isset($_GET['_tab']) ? $_GET['_tab'] : 'active';

        if (isset($_GET[$this->secretRequestRemove])) {
            if ($this->secretRequestRemove === 'CHANGE_ME') {
                exit('Please change the secretRequestRemove value in your configuration.');
            }
            $this->owner->setIpIsTrust((string)$_GET[$this->secretRequestRemove], PHPWall::TRUST_CONTROL);
            header('Location: ' . (string)(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : "?{$this->secretRequest}=1"), true, 301);
            exit();
        }

        $data = $this->fetchDataForTab($tab);

        $this->renderHeader();
        $this->printIpInfo($this->owner->getUserIp(), $this->secretRequestRemove);
        $this->renderTabs($tab, count($data));
        $this->printTable($this->prepareViewMainData($data));
    }

    /**
     * @param string $tab
     * @return array<int, array<string, mixed>>
     */
    protected function fetchDataForTab($tab)
    {
        switch ($tab) {
            case 'most':
                return $this->conn->getDataControlViewMost();
            case 'slep':
                return $this->conn->getDataControlViewSleep();
            case 'active':
            default:
                return $this->conn->getDataControlViewActive();
        }
    }

    /**
     * @return void
     */
    protected function renderHeader()
    {
        echo '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">';
        echo '<div class="container-fluid">' . "\n";
        echo '<a href="?' . $this->secretRequest . '=1"><h1>PHPWall</h1></a>';
    }

    /**
     * @param string $currentTab
     * @param int $dataCount
     * @return void
     */
    protected function renderTabs($currentTab, $dataCount)
    {
        $baseUrl = '?' . $this->secretRequest . '=1&tt=' . time() . '&_tab=';
        $tabList = [
            'active' => 'Active',
            'slep' => 'Sleep',
            'most' => 'Most',
        ];

        echo '<div class="btn-group" role="group">';
        foreach ($tabList as $tabKey => $tabName) {
            $isActive = $tabKey === $currentTab;
            $countBadge = $isActive ? " ({$dataCount})" : '';
            echo '<a type="button" class="btn btn-secondary ' . ($isActive ? 'active' : '') . '" href="' . $baseUrl . $tabKey . '">'
                . $tabName . $countBadge . '</a>';
        }
        echo '</div>';
    }

    /**
     * @param string $ip
     * @param string $rmKey
     * @return void
     */
    protected function printIpInfo($ip, $rmKey)
    {
        $ipInfo = $this->cache->getIpInfo($ip);
        if (!$ipInfo) {
            return;
        }

        $removeLink = '?' . $this->secretRequest . '=1&tt=' . time() . '&' . $rmKey . '=' . urlencode($ipInfo['ip']);
        echo '<p>IP info: ' . htmlspecialchars($ipInfo['ip']) . " <a href=\"{$removeLink}\">[Remove Trust]</a>";

        if ($ipInfo['time']) {
            echo '<span>  ' . date('Y-m-d H:i:s', (int)$ipInfo['time'])
                . ', bunTimeout: ' . (int)$ipInfo['bunTimeout']
                . ', cnt: ' . (int)$ipInfo['cnt']
                . ', trust: ' . (int)$ipInfo['trust'] . '</span>';
        }
        echo '</p>';
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return void
     */
    protected function printTable(array $rows)
    {
        if (empty($rows)) {
            echo '<div class="alert alert-info">No data to display.</div>';
            return;
        }

        echo '<table class="table table-striped table-hover"><thead class="thead-dark"><tr>';
        foreach (array_keys($rows[0]) as $header) {
            echo '<th>' . htmlspecialchars($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . $cell . '</td>'; // Data is pre-escaped in prepareViewMainData
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @return array<int, array<string, string>>
     */
    protected function prepareViewMainData(array $data)
    {
        $rows = [];
        $ddFr = $this->owner->getDosFr();
        $baseUrl = '?' . $this->secretRequest . '=1&tt=' . time() . '&';

        foreach ($data as $r) {
            $ipStr = $r['ip'];
            $isIpValid = true;
            $exp = $this->cache->getIpCacheBunTimeout($ipStr);

            $sessionRqStyle = ($ddFr < (int)$r['request_session']) ? 'style="color:red;"' : '';

            $row = [
                'Ip' => "<span {$sessionRqStyle}>" . htmlspecialchars($ipStr) . '</span>',
                'Dates' => 'Cr: ' . $r['create']
                    . '<br/>Up: ' . $r['update']
                    . '<br/>Exp cache: ' . ($isIpValid ? date('Y-m-d H:i:s', strtotime((string)$r['update']) + $exp) : 'N/A'),
                'Session rq' => (string)(int)$r['request_session'],
                'Total rq' => (string)(int)$r['request_total'],
                'Passed rq' => (string)(int)$r['request_bad'],
                'Bad days' => (string)(int)$r['request_bad_days'],
                'Is trust' => isset($this->trustList[(int)$r['trust']]) ? $this->trustList[(int)$r['trust']] : '-',
                'Host' => htmlspecialchars((string)(isset($r['host']) ? $r['host'] : '')),
                'UA' => htmlspecialchars((string)(isset($r['ua']) ? $r['ua'] : '')),
                'Actions' => $isIpValid ? '<a href="' . $baseUrl . '_logip=' . urlencode($ipStr) . '">Logs</a>' : '',
                'Remove' => $isIpValid ? '<a href="' . $baseUrl . $this->secretRequestRemove . '=' . urlencode($ipStr) . '">X</a>' : '',
            ];

            $rows[] = $row;
        }
        return $rows;
    }


    /**
     * Highlights a word within a text string by wrapping it in <b> tags.
     * The text is escaped to prevent XSS.
     *
     * @param string $text The text to search within.
     * @param string $word The word to highlight.
     * @return string The text with the highlighted word.
     */
    public static function highLight($text, $word)
    {
        $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $escapedWord = htmlspecialchars($word, ENT_QUOTES, 'UTF-8');

        if (empty($escapedWord)) {
            return $escapedText;
        }

        return str_replace($escapedWord, '<b>' . $escapedWord . '</b>', $escapedText);
    }
}
