CREATE TABLE IF NOT EXISTS `dnsmgr_config` (
  `key` varchar(32) NOT NULL,
  `value` TEXT DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_dmtask` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `did` int(11) unsigned NOT NULL,
  `rr` varchar(128) NOT NULL,
  `recordid` varchar(60) NOT NULL,
  `type` tinyint(1) NOT NULL DEFAULT 0,
  `main_value` varchar(128) DEFAULT NULL,
  `backup_value` varchar(128) DEFAULT NULL,
  `checktype` tinyint(1) NOT NULL DEFAULT 0,
  `checkurl` varchar(512) DEFAULT NULL,
  `tcpport` int(5) DEFAULT NULL,
  `frequency` tinyint(5) NOT NULL,
  `cycle` tinyint(5) NOT NULL DEFAULT 3,
  `timeout` tinyint(5) NOT NULL DEFAULT 2,
  `remark` varchar(100) DEFAULT NULL,
  `addtime` int(11) NOT NULL DEFAULT 0,
  `checktime` int(11) NOT NULL DEFAULT 0,
  `checknexttime` int(11) NOT NULL DEFAULT 0,
  `switchtime` int(11) NOT NULL DEFAULT 0,
  `errcount` tinyint(5) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `recordinfo` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `did` (`did`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_dmlog` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `taskid` int(11) unsigned NOT NULL,
  `action` tinyint(4) NOT NULL DEFAULT 0,
  `errmsg` varchar(100) DEFAULT NULL,
  `date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `taskid` (`taskid`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_optimizeip` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `did` int(11) unsigned NOT NULL,
  `rr` varchar(128) NOT NULL,
  `type` tinyint(1) NOT NULL DEFAULT 0,
  `ip_type` varchar(10) NOT NULL,
  `cdn_type` tinyint(5) NOT NULL DEFAULT 1,
  `recordnum` tinyint(5) NOT NULL DEFAULT 2,
  `ttl` int(5) NOT NULL DEFAULT 600,
  `remark` varchar(100) DEFAULT NULL,
  `addtime` datetime NOT NULL,
  `updatetime` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `errmsg` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `did` (`did`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `dnsmgr_domain`
ADD COLUMN `remark` varchar(100) DEFAULT NULL;

ALTER TABLE `dnsmgr_dmtask`
ADD COLUMN `proxy` tinyint(1) NOT NULL DEFAULT 0;

ALTER TABLE `dnsmgr_user`
ADD COLUMN `totp_open` tinyint(1) NOT NULL DEFAULT '0',
ADD COLUMN `totp_secret` varchar(100) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `dnsmgr_cert_account` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `type` varchar(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `config` text DEFAULT NULL,
  `ext` text DEFAULT NULL,
  `remark` varchar(100) DEFAULT NULL,
  `deploy` tinyint(1) NOT NULL DEFAULT '0',
  `addtime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_cert_order` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `aid` int(11) unsigned NOT NULL,
  `keytype` varchar(20) DEFAULT NULL,
  `keysize` varchar(20) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `updatetime` datetime DEFAULT NULL,
  `processid` varchar(32) DEFAULT NULL,
  `issuetime` datetime DEFAULT NULL,
  `expiretime` datetime DEFAULT NULL,
  `issuer` varchar(100) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `error` varchar(300) DEFAULT NULL,
  `isauto` tinyint(1) NOT NULL DEFAULT '0',
  `retry` tinyint(4) NOT NULL DEFAULT '0',
  `retry2` tinyint(4) NOT NULL DEFAULT '0',
  `retrytime` datetime DEFAULT NULL,
  `islock` tinyint(1) NOT NULL DEFAULT '0',
  `locktime` datetime DEFAULT NULL,
  `issend` tinyint(1) NOT NULL DEFAULT '0',
  `info` text DEFAULT NULL,
  `dns` text DEFAULT NULL,
  `fullchain` text DEFAULT NULL,
  `privatekey` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_cert_domain` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `oid` int(11) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL,
  `sort` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `oid` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_cert_deploy` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `aid` int(11) unsigned NOT NULL,
  `oid` int(11) unsigned NOT NULL,
  `issuetime` datetime DEFAULT NULL,
  `config` text DEFAULT NULL,
  `remark` varchar(100) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `lasttime` datetime DEFAULT NULL,
  `processid` varchar(32) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `error` varchar(300) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `retry` tinyint(4) NOT NULL DEFAULT '0',
  `retrytime` datetime DEFAULT NULL,
  `islock` tinyint(1) NOT NULL DEFAULT '0',
  `locktime` datetime DEFAULT NULL,
  `issend` tinyint(1) NOT NULL DEFAULT '0',
  `info` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_cert_cname` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `domain` varchar(255) NOT NULL,
  `did` int(11) unsigned NOT NULL,
  `rr` varchar(128) NOT NULL,
  `addtime` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `dnsmgr_account`
ADD COLUMN `proxy` tinyint(1) NOT NULL DEFAULT '0';

ALTER TABLE `dnsmgr_dmtask`
ADD COLUMN `cdn` tinyint(1) NOT NULL DEFAULT 0;

ALTER TABLE `dnsmgr_domain`
ADD COLUMN `is_notice` tinyint(1) NOT NULL DEFAULT '0',
ADD COLUMN `regtime` datetime DEFAULT NULL,
ADD COLUMN `expiretime` datetime DEFAULT NULL,
ADD COLUMN `checktime` datetime DEFAULT NULL,
ADD COLUMN `noticetime` datetime DEFAULT NULL,
ADD COLUMN `checkstatus` tinyint(1) NOT NULL DEFAULT '0';

CREATE TABLE IF NOT EXISTS `dnsmgr_sctask` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `did` int(11) unsigned NOT NULL,
  `rr` varchar(128) NOT NULL,
  `recordid` varchar(60) NOT NULL,
  `type` tinyint(1) NOT NULL DEFAULT 0,
  `cycle` tinyint(1) NOT NULL DEFAULT 0,
  `switchtype` tinyint(1) NOT NULL DEFAULT 0,
  `switchdate` varchar(10) DEFAULT NULL,
  `switchtime` varchar(20) DEFAULT NULL,
  `value` varchar(128) DEFAULT NULL,
  `line` varchar(20) DEFAULT NULL,
  `addtime` int(11) NOT NULL DEFAULT 0,
  `updatetime` int(11) NOT NULL DEFAULT 0,
  `nexttime` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `recordinfo` varchar(200) DEFAULT NULL,
  `remark` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `did` (`did`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `dnsmgr_user`
ADD COLUMN `email` varchar(128) DEFAULT NULL,
ADD COLUMN `email_verified` tinyint(1) NOT NULL DEFAULT '0',
ADD COLUMN `verify_token` varchar(64) DEFAULT NULL,
ADD COLUMN `verify_sent_at` datetime DEFAULT NULL,
ADD COLUMN `subdomain_quota` int(11) NOT NULL DEFAULT '0';

ALTER TABLE `dnsmgr_user`
ADD KEY `email` (`email`);

CREATE TABLE IF NOT EXISTS `dnsmgr_subdomain_root` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `name` varchar(255) NOT NULL,
  `domain_id` int(11) unsigned NOT NULL,
  `account_id` int(11) unsigned NOT NULL,
  `ttl` int(11) NOT NULL DEFAULT '600',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `remark` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_subdomain` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `user_id` int(11) unsigned NOT NULL,
  `root_id` int(11) unsigned NOT NULL,
  `account_id` int(11) unsigned NOT NULL,
  `domain_id` int(11) unsigned NOT NULL,
  `sub_name` varchar(128) NOT NULL,
  `full_domain` varchar(255) NOT NULL,
  `ns_records` text NOT NULL,
  `record_ids` text DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `audit_reason` varchar(255) DEFAULT NULL,
  `expire_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `approved_at` datetime DEFAULT NULL,
  `transfer_token` varchar(64) DEFAULT NULL,
  `transfer_expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_full_domain` (`full_domain`),
  KEY `user_id` (`user_id`),
  KEY `root_id` (`root_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `dnsmgr_config` (`key`, `value`) VALUES
('subdomain_auto_approve', '0'),
('subdomain_default_days', '365'),
('subdomain_initial_quota', '3'),
('subdomain_enabled', '1');
