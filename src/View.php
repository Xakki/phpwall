<?php

declare(strict_types=1);

namespace Xakki\PHPWall;

use Exception;

class View
{
    public const TYPE_LIST = [
        PHPWall::RULE_IP => 'IP',
        PHPWall::RULE_UA => 'UA',
        PHPWall::RULE_POST => 'POST',
        PHPWall::RULE_URL => 'URL',
    ];
    protected string $secretRequest;
    protected string $secretRequestRemove;
    protected int $bunTimeout;
    protected Connection $conn;
    protected Cache $cache;
    private array $viewMainHead = ['IP', 'Date', 'Expire', 'Bun rq', 'Total rq', 'Bad rq', 'Bad days', 'Is trust', 'Host', 'ua', '.', '.'];
    private array $trustList = [
        PHPWall::TRUST_SEARCH => 'Search',
        PHPWall::TRUST_DEFAULT => '-',
        PHPWall::TRUST_CAPTCHA => 'CAPTCHA',
        PHPWall::TRUST_CONTROL => 'Control',
    ];
    private PHPWall $owner;

    public function __construct(PHPWall $owner, Connection $conn, Cache $cache, string $secretRequest, string $secretRequestRemove, int $bunTimeout)
    {
        $this->owner = $owner;
        $this->conn = $conn;
        $this->cache = $cache;
        $this->secretRequest = $secretRequest;
        $this->secretRequestRemove = $secretRequestRemove;
        $this->bunTimeout = $bunTimeout;

        $btrstr = '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">';
        $rmKey = $this->secretRequestRemove;
        if (isset($_GET['_logip'])) {
            $ip = Tools::convertIp2Number($_GET['_logip']);

            echo $btrstr;
            echo '<a href="?' . $this->secretRequest . '=1"><h1>PHPWALL</h1></a>';
            echo '<h2>IP Log ' . $_GET['_logip'] . '</h2>';

            $data1 = $this->conn->selectAllSql(PHPWall::TABLE_MAIN, ['ip' => $ip]);
            $this->printTable($this->viewMainHead, $this->prepareViewMainData($data1));

            $data = $this->conn->selectAllSql(PHPWall::TABLE_LOG, ['ip' => $ip]);
            $head = ['id', 'Date', 'Try', 'rule', 'data'];
            $rows = [];
            foreach ($data as $r) {
                $rows[] = [
                    $r['id'],
                    $r['create'],
                    $r['try'],
                    self::TYPE_LIST[$r['rule']],
                    $r['data'],
                ];
            }
            $this->printTable($head, $rows);
        } else {
            $data = $this->owner->getDataControlView(isset($_GET['_inactiveIp']));

            if (isset($_GET[$rmKey])) {
                if ($rmKey == 'CHANGE_ME') {
                    exit('Change secretRequestRemove');
                }
                $this->owner->setTrustIp($_GET[$rmKey], PHPWall::TRUST_CONTROL);
                header('Location: ' . $_SERVER['HTTP_REFERER'], true, 301);
                exit();
            }
            echo $btrstr;
            echo '<a href="?' . $this->secretRequest . '=1"><h1>PHPWALL</h1></a>';
            echo '<p>Timeout for unblock: ' . $this->bunTimeout . 'sec.</p>';
            echo '<p>Count ip: ' . count($data) . '.</p>';

            echo '<div class="btn-group" role="group">
              <a type="button" class="btn btn-secondary' . (isset($_GET['_inactiveIp']) ? ' active' : '') . '" href="?' . $this->secretRequest . '=1&tt=' . time() . '">Active</a>
              <a type="button" class="btn btn-secondary' . (isset($_GET['_inactiveIp']) ? '' : ' active') . '" href="?' . $this->secretRequest . '=1&tt=' . time() . '&_inactiveIp=1">Sleep</a>
            </div>';

            $this->printTable($this->viewMainHead, $this->prepareViewMainData($data));

            $ipInfo = $this->owner->getIpInfo();
            if ($ipInfo) {
                echo '<h3>IP info ' . $ipInfo['ip'] . ' <a href="?' . $this->secretRequest . '=1&tt=' . time() . '&' . $rmKey . '=' . $ipInfo['ip'] . '">X</a></h3><pre>';
                echo '<p>' . date('Y-m-d H:i:s', $ipInfo['time']) . ' - ' . $ipInfo['cnt'] . '</p>';
            }
            echo '</pre>';
        }

        exit('');
    }

    protected function printTable(array $head, array $rows): void
    {
        echo '<table class="table table-striped table-hover"><thead class="thead-dark"><tr>';
        foreach ($head as $item) {
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
        foreach ($data as $r) {
            $flag = false;
            try {
                $r['ip'] = Tools::convertIp2String($r['ip']);
                $flag = true;
            } catch (Exception $e) {
                $r['ip'] = $e->getMessage();
            }
            $rows[] = [
                $r['ip'],
                $r['create'] . '<br/>' . $r['update'],
                date('m-d H:i', $this->owner->calculateTimeOut($r)),
                $r['request_session'],
                $r['request_total'],
                $r['request_bad'],
                $r['request_bad_days'],
                isset($this->trustList[$r['trust']]) ? $this->trustList[$r['trust']] : '',
                !empty($r['host']) ? htmlspecialchars($r['host']) : '',
                !empty($r['ua']) ? htmlspecialchars($r['ua']) : '',
                $flag ? '<a href="?' . $this->secretRequest . '=1&tt=' . time() . '&_logip=' . $r['ip'] . '">Logs</a>' : '',
                $flag ? '<a href="?' . $this->secretRequest . '=1&tt=' . time() . '&' . $this->secretRequestRemove . '=' . $r['ip'] . '">X</a>' : '',
            ];
        }
        return $rows;
    }
}
