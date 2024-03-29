<?php

declare(strict_types=1);

namespace Xakki\PhpWall;

use Exception;

class View
{
    public const TYPE_LIST = [
        PhpWall::RULE_IP => 'IP',
        PhpWall::RULE_UA => 'UA',
        PhpWall::RULE_POST => 'POST',
        PhpWall::RULE_URL => 'URL',
    ];
    protected string $secretRequest;
    protected string $secretRequestRemove;
    protected Db $conn;
    protected Cache $cache;

    private array $trustList = [
        PhpWall::TRUST_SEARCH => 'Search',
        PhpWall::TRUST_DEFAULT => '-',
        PhpWall::TRUST_CAPTCHA => 'CAPTCHA',
        PhpWall::TRUST_CONTROL => 'Control',
    ];
    private PhpWall $owner;

    public function __construct(PhpWall $owner, Db $conn, Cache $cache, string $secretRequest, string $secretRequestRemove)
    {
        $this->owner = $owner;
        $this->conn = $conn;
        $this->cache = $cache;
        $this->secretRequest = $secretRequest;
        $this->secretRequestRemove = $secretRequestRemove;

        $btrstr = '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">';
        $rmKey = $this->secretRequestRemove;
        if (isset($_GET['_logip'])) {
            echo $btrstr;
            echo '<a href="?' . $this->secretRequest . '=1"><h1>PHPWALL</h1></a>';

            $this->printIpInfo($_GET['_logip'], $rmKey);

            $dataMain = $this->conn->getMainByIp($_GET['_logip']);
            $this->printTable($this->prepareViewMainData([$dataMain]));

            $data = $this->conn->getAllLogByIp($_GET['_logip']);
            $rows = [];
            foreach ($data as $r) {
                $rows[] = [
                    'id' => $r['id'],
                    'Date' => $r['create'],
                    'Try' => $r['try'],
                    'rule' => self::TYPE_LIST[$r['rule']],
                    'data' => $r['data'],
                ];
            }
            $this->printTable($rows);
        } else {
            $baseUrl = '?' . $this->secretRequest . '=1&tt=' . time() . '&_tab=';
            $tab = $_GET['_tab'] ?? 'active';
            $tabList = [
                'active' => 'Active',
                'slep' => 'Sleep',
                'most' => 'Most',
            ];

            switch ($tab) {
                case 'most';
                    $data = $this->conn->getDataControlViewMost();
                    break;

                case 'slep';
                    $data = $this->conn->getDataControlViewSleep();
                    break;

                default;
                    $data = $this->conn->getDataControlViewActive();
            }

            if (isset($_GET[$rmKey])) {
                if ($rmKey == 'CHANGE_ME') {
                    exit('Change secretRequestRemove');
                }
                $this->owner->setIpIsTrust($_GET[$rmKey], PhpWall::TRUST_CONTROL);
                header('Location: ' . $_SERVER['HTTP_REFERER'], true, 301);
                exit();
            }

            echo $btrstr;
            echo '<a href="?' . $this->secretRequest . '=1"><h1>PHPWALL</h1></a>';

            $this->printIpInfo($this->owner->getUserIp(), $rmKey);

            echo '<div class="btn-group" role="group">';
            foreach ($tabList as $tK => $tR) {
                echo '<a type="button" class="btn btn-secondary ' . $tK . '" href="' . $baseUrl . $tK . '">'
                    . $tR . ($tK == $tab ? ' (' . count($data) . ')' : '') . '</a>';
            }
            echo '</div>';

            $this->printTable($this->prepareViewMainData($data));
        }

        exit('');
    }

    protected function printIpInfo(string $ip, string $rmKey): void
    {
        $ipInfo = $this->cache->getIpInfo($ip);
        if ($ipInfo) {
            echo '<p>IP info ' . $ipInfo['ip'] . ' <a href="?' . $this->secretRequest . '=1&tt=' . time() . '&' . $rmKey . '=' . $ipInfo['ip'] . '">X</a>';
            if ($ipInfo['time']) {
                echo '<span>  ' . date('Y-m-d H:i:s', $ipInfo['time'])
                    . ', bunTimeout: ' . $ipInfo['bunTimeout']
                    . ', cnt: ' . $ipInfo['cnt']
                    . ', trust: ' . $ipInfo['cnt'] . '</span>';
            }
            echo '</p>';
        }
    }

    protected function printTable(array $rows): void
    {
        echo '<table class="table table-striped table-hover"><thead class="thead-dark"><tr>';
        foreach ($rows[0] as $item => $v) {
            echo '<th>' . $item . '</th>';
        }
        echo '</tr></thead><tbody></tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $i) {
                echo '<td>' . $i . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    protected function prepareViewMainData(array $data): array
    {
        $rows = [];
        $exp = 0;
        $ddFr = $this->owner->getDosFr();
        $baseUrl = '?' . $this->secretRequest . '=1&tt=' . time() . '&';

        foreach ($data as $r) {
            $flag = false;
            try {
                $r['ip'] = Tools::convertIp2String($r['ip']);
                $flag = true;
                $exp = (int)$this->cache->getIpCacheBunTimeout($r['ip']);
            } catch (Exception $e) {
                $r['ip'] = $e->getMessage();
            }

            $row = [
                'Ip' => '<span class="' . ($ddFr < $r['request_session'] ? 'color:red;' : '') . '">' . $r['ip'] . '</span>',
                'Date' => 'Cr: ' . $r['create']
                    . '<br/>Up: ' . $r['update']
                    . '<br/>Exp: ' . $r['expire']
                    . '<br/>Exp cache: ' . date('Y-m-d H:i:s', strtotime($r['update']) + $exp),
                'Session rq' => $r['request_session'],
                'Total rq' => $r['request_total'],
                'Passed rq' => $r['request_bad'],
                'Bad days' => $r['request_bad_days'],
            ];
            if (!empty($r['trust'])) {
                $row['Is trust'] = isset($this->trustList[$r['trust']]) ? $this->trustList[$r['trust']] : '-';
            }
            if (!empty($r['host'])) {
                $row['Host'] = htmlspecialchars($r['host']);
            }
            if (!empty($r['ua'])) {
                $row['UA'] = htmlspecialchars($r['ua']);
            }

            $row['.'] = $flag ? '<a href="' . $baseUrl . '_logip=' . $r['ip'] . '">Logs</a>' : '';
            $row['..'] = $flag ? '<a href="' . $baseUrl . $this->secretRequestRemove . '=' . $r['ip'] . '">X</a>' : '';

            $rows[] = $row;
        }
        return $rows;
    }
}
