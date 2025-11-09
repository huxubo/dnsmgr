<?php

namespace app\service;

use app\lib\DnsHelper;
use Exception;
use think\facade\Db;

class SubdomainDnsService
{
    public static function normalizeNsRecords(array $records): array
    {
        $result = [];
        foreach ($records as $record) {
            if (!is_string($record)) {
                continue;
            }
            $value = strtolower(trim($record));
            if ($value === '') {
                continue;
            }
            $value = rtrim($value, '.');
            if (!preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $value)) {
                throw new Exception('NS记录格式不正确：' . $record);
            }
            $result[] = $value . '.';
        }
        $result = array_values(array_unique($result));
        return $result;
    }

    public static function createRecords(array $subdomain, array $nsRecords): array
    {
        $nsRecords = self::normalizeNsRecords($nsRecords);
        if (empty($nsRecords)) {
            throw new Exception('请至少填写一个NS记录');
        }
        $context = self::getDnsContext($subdomain);
        self::removeExistingNsRecords($context['dns'], $subdomain['sub_name']);

        $recordIds = [];
        try {
            foreach ($nsRecords as $nsValue) {
                $recordId = $context['dns']->addDomainRecord(
                    $subdomain['sub_name'],
                    'NS',
                    $nsValue,
                    'default',
                    intval($context['root']['ttl']) ?: 600
                );
                if (!$recordId) {
                    throw new Exception('添加NS记录失败，' . $context['dns']->getError());
                }
                $recordIds[] = $recordId;
            }
        } catch (Exception $e) {
            foreach ($recordIds as $recordId) {
                try {
                    $context['dns']->deleteDomainRecord($recordId);
                } catch (Exception $ignored) {
                }
            }
            throw $e;
        }
        return $recordIds;
    }

    public static function deleteRecords(array $subdomain): void
    {
        $context = self::getDnsContext($subdomain);
        $recordIds = [];
        if (!empty($subdomain['record_ids'])) {
            $decoded = json_decode($subdomain['record_ids'], true);
            if (is_array($decoded)) {
                $recordIds = $decoded;
            }
        }
        foreach ($recordIds as $recordId) {
            try {
                $context['dns']->deleteDomainRecord($recordId);
            } catch (Exception $e) {
            }
        }
        self::removeExistingNsRecords($context['dns'], $subdomain['sub_name']);
    }

    private static function removeExistingNsRecords($dns, string $subName): void
    {
        try {
            $records = $dns->getSubDomainRecords($subName, 1, 100, 'NS');
        } catch (Exception $e) {
            return;
        }
        if (!is_array($records) || !isset($records['list'])) {
            return;
        }
        foreach ($records['list'] as $record) {
            if (!isset($record['Type']) || strtoupper($record['Type']) !== 'NS') {
                continue;
            }
            try {
                $dns->deleteDomainRecord($record['RecordId']);
            } catch (Exception $e) {
            }
        }
    }

    private static function getDnsContext(array $subdomain): array
    {
        $root = $subdomain['__root'] ?? Db::name('subdomain_root')->where('id', $subdomain['root_id'])->find();
        if (!$root) {
            throw new Exception('主域配置不存在');
        }
        if (isset($root['status']) && intval($root['status']) !== 1) {
            throw new Exception('主域已被禁用');
        }
        $domain = $subdomain['__domain'] ?? Db::name('domain')->where('id', $root['domain_id'])->find();
        if (!$domain) {
            throw new Exception('主域不存在');
        }
        $dns = DnsHelper::getModel($root['account_id'], $domain['name'], $domain['thirdid']);
        if (!$dns) {
            throw new Exception('DNS模块不存在');
        }
        return ['dns' => $dns, 'root' => $root, 'domain' => $domain];
    }
}
