<?php

namespace app\service;

use app\controller\Subdomain as SubdomainController;
use Exception;
use think\facade\Db;

class SubdomainService
{
    public function execute(): bool
    {
        $list = Db::name('subdomain')
            ->where('status', SubdomainController::STATUS_ACTIVE)
            ->whereNotNull('expire_at')
            ->whereTime('expire_at', '<', date('Y-m-d H:i:s'))
            ->select();
        if ($list->isEmpty()) {
            return false;
        }
        foreach ($list as $row) {
            $this->expireOne($row);
        }
        return true;
    }

    private function expireOne(array $row): void
    {
        $root = Db::name('subdomain_root')->where('id', $row['root_id'])->find();
        $domain = Db::name('domain')->where('id', $row['domain_id'])->find();
        if (!$root || !$domain) {
            Db::name('subdomain')->where('id', $row['id'])->update([
                'status' => SubdomainController::STATUS_EXPIRED,
                'record_ids' => null,
                'updated_at' => date('Y-m-d H:i:s'),
                'audit_reason' => '关联主域缺失，自动标记过期',
            ]);
            return;
        }
        try {
            SubdomainDnsService::deleteRecords(array_merge($row, ['__root' => $root, '__domain' => $domain]));
        } catch (Exception $e) {
        }
        Db::name('subdomain')->where('id', $row['id'])->update([
            'status' => SubdomainController::STATUS_EXPIRED,
            'record_ids' => null,
            'updated_at' => date('Y-m-d H:i:s'),
            'audit_reason' => '到期自动释放',
        ]);
        Db::name('log')->insert([
            'uid' => $row['user_id'],
            'action' => '子域到期自动释放',
            'domain' => $row['full_domain'],
            'data' => '系统任务自动处理',
            'addtime' => date('Y-m-d H:i:s'),
        ]);
    }
}
