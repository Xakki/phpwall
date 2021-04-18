CREATE TABLE IF NOT EXISTS iplist (
    `ip` varbinary(16) NOT NULL,
    `create` datetime NOT NULL,
    `update` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    `request_total` int(11),
    `request_session` int(11),
    `request_bad` int(11),
    `request_bad_days` int(11),
    `request_bad_days_up` date,
    `trust` tinyint(1),
    `host` varchar(128),
    `ua` varchar(255),
    PRIMARY KEY (`ip`),
    KEY `update` (`update`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS iplog (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `create` datetime NOT NULL,
    `ip` varbinary(16) NOT NULL,
    `rule` tinyint(1),
    `data` varchar(255),
    `try` int(11),
    PRIMARY KEY (`id`),
    KEY `ip` (`ip`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;