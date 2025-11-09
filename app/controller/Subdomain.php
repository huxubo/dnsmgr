<?php

namespace app\controller;

use app\BaseController;
use app\service\SubdomainDnsService;
use Exception;
use think\facade\Cache;
use think\facade\Db;
use think\facade\View;

class Subdomain extends BaseController
{
    public const STATUS_PENDING = 0;
    public const STATUS_ACTIVE = 1;
    public const STATUS_REJECTED = 2;
    public const STATUS_EXPIRED = 3;
    public const STATUS_REVOKED = 4;

    public function index()
    {
        $user = $this->request->user;
        if (empty($user) || $user['type'] !== 'user') {
            return $this->alert('error', '无权限');
        }
        $roots = Db::name('subdomain_root')->alias('A')
            ->join('domain B', 'A.domain_id = B.id')
            ->field('A.id,A.name,A.ttl,A.remark,B.name as domain_name')
            ->where('A.status', 1)
            ->order('A.id', 'asc')
            ->select();
        $quota = intval($user['subdomain_quota']);
        $used = Db::name('subdomain')->where('user_id', $user['id'])->whereIn('status', [self::STATUS_PENDING, self::STATUS_ACTIVE])->count();
        View::assign('roots', $roots);
        View::assign('quota', $quota);
        View::assign('used', $used);
        View::assign('autoApprove', config_get('subdomain_auto_approve', '0'));
        View::assign('defaultDays', config_get('subdomain_default_days', '365'));
        View::assign('enabled', config_get('subdomain_enabled', '1'));
        return view();
    }

    public function user_data()
    {
        $user = $this->request->user;
        if (empty($user) || $user['type'] !== 'user') {
            return json(['total' => 0, 'rows' => []]);
        }
        $offset = input('post.offset/d', 0);
        $limit = input('post.limit/d', 10);
        $query = Db::name('subdomain')->alias('A')
            ->leftJoin('subdomain_root B', 'A.root_id = B.id')
            ->field('A.*,B.name as root_name');
        $query->where('A.user_id', $user['id']);
        $total = $query->count();
        if ($limit > 0) {
            $query->limit($offset, $limit);
        }
        $rows = $query->order('A.id', 'desc')->select();
        $data = [];
        foreach ($rows as $row) {
            $row['ns_records'] = $this->decodeJson($row['ns_records']);
            $row['record_ids'] = $this->decodeJson($row['record_ids']);
            $row['status_text'] = $this->statusText($row['status']);
            $data[] = $row;
        }
        $usedCount = Db::name('subdomain')->where('user_id', $user['id'])->whereIn('status', [self::STATUS_PENDING, self::STATUS_ACTIVE])->count();
        return json(['total' => $total, 'rows' => $data, 'used' => $usedCount]);
    }

    public function apply()
    {
        $user = $this->request->user;
        if (empty($user) || $user['type'] !== 'user') {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (config_get('subdomain_enabled', '1') !== '1') {
            return json(['code' => -1, 'msg' => '子域分发功能已关闭']);
        }
        $rootId = input('post.root_id/d');
        $subName = strtolower(trim(input('post.sub_name', '', 'trim')));
        $nsList = input('post.ns/a', []);
        if (empty($rootId) || empty($subName) || empty($nsList)) {
            return json(['code' => -1, 'msg' => '请完善申请信息']);
        }
        if (!$this->validateSubName($subName)) {
            return json(['code' => -1, 'msg' => '子域格式不正确']);
        }
        $root = Db::name('subdomain_root')->where('id', $rootId)->find();
        if (!$root || intval($root['status']) !== 1) {
            return json(['code' => -1, 'msg' => '主域不可用']);
        }
        $domain = Db::name('domain')->where('id', $root['domain_id'])->find();
        if (!$domain) {
            return json(['code' => -1, 'msg' => '主域不存在']);
        }
        $fullDomain = $subName . '.' . $root['name'];
        $exists = Db::name('subdomain')->where('full_domain', $fullDomain)->find();
        $reuseId = null;
        if ($exists) {
            $existsStatus = intval($exists['status']);
            if (in_array($existsStatus, [self::STATUS_REVOKED, self::STATUS_EXPIRED])) {
                $reuseId = $exists['id'];
            } else {
                return json(['code' => -1, 'msg' => '该子域已被占用']);
            }
        }
        $quota = intval($user['subdomain_quota']);
        if ($quota > 0) {
            $used = Db::name('subdomain')->where('user_id', $user['id'])->whereIn('status', [self::STATUS_PENDING, self::STATUS_ACTIVE])->count();
            if ($used >= $quota) {
                return json(['code' => -1, 'msg' => '已达到子域申请配额上限']);
            }
        }
        try {
            $normalizedNs = SubdomainDnsService::normalizeNsRecords($nsList);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
        if (count($normalizedNs) < 2) {
            return json(['code' => -1, 'msg' => '请至少填写两个NS记录']);
        }

        $autoApprove = config_get('subdomain_auto_approve', '0') === '1';
        $defaultDays = intval(config_get('subdomain_default_days', 365));
        $now = date('Y-m-d H:i:s');
        $expireAt = $defaultDays > 0 ? date('Y-m-d H:i:s', time() + $defaultDays * 86400) : null;

        $data = [
            'user_id' => $user['id'],
            'root_id' => $root['id'],
            'account_id' => $root['account_id'],
            'domain_id' => $root['domain_id'],
            'sub_name' => $subName,
            'full_domain' => $fullDomain,
            'ns_records' => json_encode($normalizedNs, JSON_UNESCAPED_UNICODE),
            'record_ids' => null,
            'status' => self::STATUS_PENDING,
            'audit_reason' => null,
            'expire_at' => $expireAt,
            'created_at' => $now,
            'updated_at' => $now,
            'approved_at' => null,
            'transfer_token' => null,
            'transfer_expires_at' => null,
        ];

        Db::startTrans();
        try {
            if ($reuseId !== null) {
                Db::name('subdomain')->where('id', $reuseId)->update($data);
                $id = $reuseId;
            } else {
                $id = Db::name('subdomain')->insertGetId($data);
            }
            if ($autoApprove) {
                $recordIds = SubdomainDnsService::createRecords(array_merge($data, ['id' => $id, '__root' => $root, '__domain' => $domain]), $normalizedNs);
                Db::name('subdomain')->where('id', $id)->update([
                    'status' => self::STATUS_ACTIVE,
                    'record_ids' => json_encode($recordIds, JSON_UNESCAPED_UNICODE),
                    'approved_at' => $now,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'audit_reason' => null,
                ]);
                $message = '申请成功，系统已自动审核通过';
            } else {
                $message = '申请已提交，请等待管理员审核';
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            if (isset($id)) {
                Db::name('subdomain')->where('id', $id)->update(['audit_reason' => $e->getMessage(), 'updated_at' => date('Y-m-d H:i:s')]);
            }
            return json(['code' => -1, 'msg' => '申请失败，' . $e->getMessage()]);
        }

        $this->addLog($user['id'], '申请子域', '子域：' . $fullDomain, $fullDomain);
        return json(['code' => 0, 'msg' => $message]);
    }

    public function update()
    {
        $user = $this->request->user;
        if (empty($user) || $user['type'] !== 'user') {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        $id = input('post.id/d');
        $nsList = input('post.ns/a', []);
        if (empty($id) || empty($nsList)) {
            return json(['code' => -1, 'msg' => '参数不完整']);
        }
        $subdomain = Db::name('subdomain')->where('id', $id)->where('user_id', $user['id'])->find();
        if (!$subdomain) {
            return json(['code' => -1, 'msg' => '子域不存在']);
        }
        if (intval($subdomain['status']) !== self::STATUS_ACTIVE) {
            return json(['code' => -1, 'msg' => '仅可调整已生效的子域']);
        }
        try {
            $normalizedNs = SubdomainDnsService::normalizeNsRecords($nsList);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
        if (count($normalizedNs) < 2) {
            return json(['code' => -1, 'msg' => '请至少填写两个NS记录']);
        }
        $root = Db::name('subdomain_root')->where('id', $subdomain['root_id'])->find();
        $domain = Db::name('domain')->where('id', $root['domain_id'])->find();
        Db::startTrans();
        try {
            $recordIds = SubdomainDnsService::createRecords(array_merge($subdomain, ['__root' => $root, '__domain' => $domain]), $normalizedNs);
            Db::name('subdomain')->where('id', $id)->update([
                'ns_records' => json_encode($normalizedNs, JSON_UNESCAPED_UNICODE),
                'record_ids' => json_encode($recordIds, JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            return json(['code' => -1, 'msg' => '更新失败，' . $e->getMessage()]);
        }
        $this->addLog($user['id'], '更新子域NS', '子域：' . $subdomain['full_domain'], $subdomain['full_domain']);
        return json(['code' => 0, 'msg' => '更新成功']);
    }

    public function cancel()
    {
        $user = $this->request->user;
        if (empty($user) || $user['type'] !== 'user') {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        $id = input('post.id/d');
        if (empty($id)) {
            return json(['code' => -1, 'msg' => '参数不完整']);
        }
        $subdomain = Db::name('subdomain')->where('id', $id)->where('user_id', $user['id'])->find();
        if (!$subdomain) {
            return json(['code' => -1, 'msg' => '子域不存在']);
        }
        $status = intval($subdomain['status']);
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_ACTIVE])) {
            return json(['code' => -1, 'msg' => '当前状态不可取消']);
        }
        $root = Db::name('subdomain_root')->where('id', $subdomain['root_id'])->find();
        $domain = Db::name('domain')->where('id', $root['domain_id'])->find();
        Db::startTrans();
        try {
            if ($status === self::STATUS_ACTIVE) {
                SubdomainDnsService::deleteRecords(array_merge($subdomain, ['__root' => $root, '__domain' => $domain]));
            }
            Db::name('subdomain')->where('id', $id)->update([
                'status' => self::STATUS_REVOKED,
                'record_ids' => null,
                'updated_at' => date('Y-m-d H:i:s'),
                'audit_reason' => '用户主动取消',
                'transfer_token' => null,
                'transfer_expires_at' => null,
            ]);
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            return json(['code' => -1, 'msg' => '取消失败，' . $e->getMessage()]);
        }
        $this->addLog($user['id'], '取消子域', '子域：' . $subdomain['full_domain'], $subdomain['full_domain']);
        return json(['code' => 0, 'msg' => '已取消子域']);
    }

    public function transfer_create()
    {
        $user = $this->request->user;
        if (empty($user) || $user['type'] !== 'user') {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        $id = input('post.id/d');
        if (empty($id)) {
            return json(['code' => -1, 'msg' => '参数不完整']);
        }
        $subdomain = Db::name('subdomain')->where('id', $id)->where('user_id', $user['id'])->find();
        if (!$subdomain) {
            return json(['code' => -1, 'msg' => '子域不存在']);
        }
        if (intval($subdomain['status']) !== self::STATUS_ACTIVE) {
            return json(['code' => -1, 'msg' => '仅可转移已生效的子域']);
        }
        $token = getSid();
        $expires = date('Y-m-d H:i:s', time() + 86400);
        Db::name('subdomain')->where('id', $id)->update([
            'transfer_token' => $token,
            'transfer_expires_at' => $expires,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return json(['code' => 0, 'msg' => '转移码已生成，有效期24小时', 'token' => $token, 'expires' => $expires]);
    }

    public function transfer_cancel()
    {
        $user = $this->request->user;
        if (empty($user) || $user['type'] !== 'user') {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        $id = input('post.id/d');
        if (empty($id)) {
            return json(['code' => -1, 'msg' => '参数不完整']);
        }
        $subdomain = Db::name('subdomain')->where('id', $id)->where('user_id', $user['id'])->find();
        if (!$subdomain) {
            return json(['code' => -1, 'msg' => '子域不存在']);
        }
        Db::name('subdomain')->where('id', $id)->update([
            'transfer_token' => null,
            'transfer_expires_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return json(['code' => 0, 'msg' => '已取消转移']);
    }

    public function transfer_accept()
    {
        $user = $this->request->user;
        if (empty($user) || $user['type'] !== 'user') {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        $token = input('post.token', null, 'trim');
        if (empty($token)) {
            return json(['code' => -1, 'msg' => '请输入转移码']);
        }
        $subdomain = Db::name('subdomain')->where('transfer_token', $token)->find();
        if (!$subdomain) {
            return json(['code' => -1, 'msg' => '转移码无效']);
        }
        if (!empty($subdomain['transfer_expires_at']) && strtotime($subdomain['transfer_expires_at']) < time()) {
            return json(['code' => -1, 'msg' => '转移码已过期']);
        }
        if (intval($subdomain['status']) !== self::STATUS_ACTIVE) {
            return json(['code' => -1, 'msg' => '当前子域不可转移']);
        }
        if (intval($subdomain['user_id']) === intval($user['id'])) {
            return json(['code' => -1, 'msg' => '无需向自己转移']);
        }
        $quota = intval($user['subdomain_quota']);
        if ($quota > 0) {
            $used = Db::name('subdomain')->where('user_id', $user['id'])->whereIn('status', [self::STATUS_PENDING, self::STATUS_ACTIVE])->count();
            if ($used >= $quota) {
                return json(['code' => -1, 'msg' => '已达到子域申请配额上限']);
            }
        }
        Db::name('subdomain')->where('id', $subdomain['id'])->update([
            'user_id' => $user['id'],
            'transfer_token' => null,
            'transfer_expires_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->addLog($subdomain['user_id'], '转出子域', '转移给用户ID：' . $user['id'], $subdomain['full_domain']);
        $this->addLog($user['id'], '接收子域', '来自用户ID：' . $subdomain['user_id'], $subdomain['full_domain']);
        return json(['code' => 0, 'msg' => '转移成功']);
    }

    public function admin()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $domains = Db::name('domain')->alias('A')
            ->join('account B', 'A.aid = B.id')
            ->field('A.id,A.name,B.id as account_id,B.type,B.remark as account_remark')
            ->order('A.name', 'asc')
            ->select();
        View::assign('domains', $domains);
        View::assign('autoApprove', config_get('subdomain_auto_approve', '0'));
        View::assign('defaultDays', config_get('subdomain_default_days', '365'));
        View::assign('initialQuota', config_get('subdomain_initial_quota', '3'));
        View::assign('enabled', config_get('subdomain_enabled', '1'));
        return view();
    }

    public function admin_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $status = input('post.status', null, 'trim');
        $keyword = input('post.keyword', null, 'trim');
        $offset = input('post.offset/d', 0);
        $limit = input('post.limit/d', 10);
        $query = Db::name('subdomain')->alias('A')
            ->leftJoin('subdomain_root B', 'A.root_id = B.id')
            ->leftJoin('user C', 'A.user_id = C.id')
            ->field('A.*,B.name as root_name,C.username');
        if ($status !== null && $status !== '') {
            $query->where('A.status', intval($status));
        }
        if (!empty($keyword)) {
            $query->whereLike('A.full_domain|C.username', '%' . $keyword . '%');
        }
        $total = $query->count();
        if ($limit > 0) {
            $query->limit($offset, $limit);
        }
        $rows = $query->order('A.id', 'desc')->select();
        foreach ($rows as &$row) {
            $row['ns_records'] = $this->decodeJson($row['ns_records']);
            $row['record_ids'] = $this->decodeJson($row['record_ids']);
            $row['status_text'] = $this->statusText($row['status']);
        }
        return json(['total' => $total, 'rows' => $rows]);
    }

    public function approve()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $id = input('post.id/d');
        if (empty($id)) return json(['code' => -1, 'msg' => '参数不完整']);
        $subdomain = Db::name('subdomain')->where('id', $id)->find();
        if (!$subdomain) return json(['code' => -1, 'msg' => '子域不存在']);
        if (intval($subdomain['status']) === self::STATUS_ACTIVE) return json(['code' => -1, 'msg' => '该子域已审核']);
        $nsRecords = $this->decodeJson($subdomain['ns_records']);
        if (empty($nsRecords)) return json(['code' => -1, 'msg' => '未填写NS记录']);
        $root = Db::name('subdomain_root')->where('id', $subdomain['root_id'])->find();
        $domain = Db::name('domain')->where('id', $root['domain_id'])->find();
        $now = date('Y-m-d H:i:s');
        try {
            $recordIds = SubdomainDnsService::createRecords(array_merge($subdomain, ['__root' => $root, '__domain' => $domain]), $nsRecords);
            if (empty($subdomain['expire_at'])) {
                $defaultDays = intval(config_get('subdomain_default_days', 365));
                $expire = $defaultDays > 0 ? date('Y-m-d H:i:s', time() + $defaultDays * 86400) : null;
            } else {
                $expire = $subdomain['expire_at'];
            }
            Db::name('subdomain')->where('id', $id)->update([
                'status' => self::STATUS_ACTIVE,
                'record_ids' => json_encode($recordIds, JSON_UNESCAPED_UNICODE),
                'approved_at' => $now,
                'updated_at' => $now,
                'audit_reason' => null,
                'expire_at' => $expire,
            ]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => '审核失败，' . $e->getMessage()]);
        }
        $this->addLog($subdomain['user_id'], '管理员审核子域', '子域：' . $subdomain['full_domain'], $subdomain['full_domain']);
        return json(['code' => 0, 'msg' => '审核通过']);
    }

    public function reject()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $id = input('post.id/d');
        $reason = input('post.reason', '管理员拒绝', 'trim');
        if (empty($id)) return json(['code' => -1, 'msg' => '参数不完整']);
        $subdomain = Db::name('subdomain')->where('id', $id)->find();
        if (!$subdomain) return json(['code' => -1, 'msg' => '子域不存在']);
        if (intval($subdomain['status']) === self::STATUS_ACTIVE) {
            return json(['code' => -1, 'msg' => '已生效的子域请使用注销操作']);
        }
        Db::name('subdomain')->where('id', $id)->update([
            'status' => self::STATUS_REJECTED,
            'audit_reason' => $reason,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->addLog($subdomain['user_id'], '管理员拒绝子域', $reason, $subdomain['full_domain']);
        return json(['code' => 0, 'msg' => '已拒绝']);
    }

    public function revoke()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $id = input('post.id/d');
        $reason = input('post.reason', '管理员注销', 'trim');
        if (empty($id)) return json(['code' => -1, 'msg' => '参数不完整']);
        $subdomain = Db::name('subdomain')->where('id', $id)->find();
        if (!$subdomain) return json(['code' => -1, 'msg' => '子域不存在']);
        $root = Db::name('subdomain_root')->where('id', $subdomain['root_id'])->find();
        $domain = Db::name('domain')->where('id', $root['domain_id'])->find();
        try {
            SubdomainDnsService::deleteRecords(array_merge($subdomain, ['__root' => $root, '__domain' => $domain]));
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => '注销失败，' . $e->getMessage()]);
        }
        Db::name('subdomain')->where('id', $id)->update([
            'status' => self::STATUS_REVOKED,
            'record_ids' => null,
            'audit_reason' => $reason,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->addLog($subdomain['user_id'], '管理员注销子域', $reason, $subdomain['full_domain']);
        return json(['code' => 0, 'msg' => '已注销']);
    }

    public function renew()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $id = input('post.id/d');
        $expire = input('post.expire_at', null, 'trim');
        if (empty($id) || empty($expire)) return json(['code' => -1, 'msg' => '参数不完整']);
        if (strtotime($expire) === false) return json(['code' => -1, 'msg' => '到期时间格式不正确']);
        Db::name('subdomain')->where('id', $id)->update([
            'expire_at' => $expire,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return json(['code' => 0, 'msg' => '已更新到期时间']);
    }

    public function root_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $list = Db::name('subdomain_root')->alias('A')
            ->join('domain B', 'A.domain_id = B.id')
            ->field('A.*,B.name as domain_name')
            ->order('A.id', 'asc')
            ->select();
        return json(['total' => count($list), 'rows' => $list]);
    }

    public function root_op()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $act = input('param.act');
        if ($act === 'get') {
            $id = input('post.id/d');
            $row = Db::name('subdomain_root')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '记录不存在']);
            return json(['code' => 0, 'data' => $row]);
        } elseif ($act === 'add') {
            $name = strtolower(trim(input('post.name')));
            $domainId = input('post.domain_id/d');
            $ttl = input('post.ttl/d', 600);
            $status = input('post.status/d', 1);
            $remark = input('post.remark', null, 'trim');
            if (empty($name) || empty($domainId)) return json(['code' => -1, 'msg' => '请完善信息']);
            if (!filter_var('http://' . $name, FILTER_VALIDATE_URL)) return json(['code' => -1, 'msg' => '主域格式不正确']);
            if (Db::name('subdomain_root')->where('name', $name)->find()) return json(['code' => -1, 'msg' => '主域已存在']);
            $domain = Db::name('domain')->where('id', $domainId)->find();
            if (!$domain) return json(['code' => -1, 'msg' => '关联主域不存在']);
            Db::name('subdomain_root')->insert([
                'name' => $name,
                'domain_id' => $domainId,
                'account_id' => $domain['aid'],
                'ttl' => $ttl,
                'status' => $status,
                'remark' => $remark,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return json(['code' => 0, 'msg' => '添加成功']);
        } elseif ($act === 'edit') {
            $id = input('post.id/d');
            $name = strtolower(trim(input('post.name')));
            $domainId = input('post.domain_id/d');
            $ttl = input('post.ttl/d', 600);
            $status = input('post.status/d', 1);
            $remark = input('post.remark', null, 'trim');
            if (empty($id) || empty($name) || empty($domainId)) return json(['code' => -1, 'msg' => '请完善信息']);
            if (!filter_var('http://' . $name, FILTER_VALIDATE_URL)) return json(['code' => -1, 'msg' => '主域格式不正确']);
            if (Db::name('subdomain_root')->where('name', $name)->where('id', '<>', $id)->find()) return json(['code' => -1, 'msg' => '主域已存在']);
            $domain = Db::name('domain')->where('id', $domainId)->find();
            if (!$domain) return json(['code' => -1, 'msg' => '关联主域不存在']);
            Db::name('subdomain_root')->where('id', $id)->update([
                'name' => $name,
                'domain_id' => $domainId,
                'account_id' => $domain['aid'],
                'ttl' => $ttl,
                'status' => $status,
                'remark' => $remark,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return json(['code' => 0, 'msg' => '修改成功']);
        } elseif ($act === 'del') {
            $id = input('post.id/d');
            if (empty($id)) return json(['code' => -1, 'msg' => '参数不完整']);
            $count = Db::name('subdomain')->where('root_id', $id)->whereIn('status', [self::STATUS_PENDING, self::STATUS_ACTIVE])->count();
            if ($count > 0) return json(['code' => -1, 'msg' => '该主域下存在子域，无法删除']);
            Db::name('subdomain_root')->where('id', $id)->delete();
            return json(['code' => 0, 'msg' => '删除成功']);
        }
        return json(['code' => -3, 'msg' => '不支持的操作']);
    }

    public function settings()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        View::assign('autoApprove', config_get('subdomain_auto_approve', '0'));
        View::assign('defaultDays', config_get('subdomain_default_days', '365'));
        View::assign('initialQuota', config_get('subdomain_initial_quota', '3'));
        View::assign('enabled', config_get('subdomain_enabled', '1'));
        return view();
    }

    public function save_settings()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $auto = input('post.auto_approve/d', 0) ? '1' : '0';
        $days = max(0, intval(input('post.default_days/d', 365)));
        $quota = max(0, intval(input('post.initial_quota/d', 3)));
        $enabled = input('post.enabled/d', 1) ? '1' : '0';
        config_set('subdomain_auto_approve', $auto);
        config_set('subdomain_default_days', (string)$days);
        config_set('subdomain_initial_quota', (string)$quota);
        config_set('subdomain_enabled', $enabled);
        Cache::delete('configs');
        return json(['code' => 0, 'msg' => '设置已保存']);
    }

    private function validateSubName(string $name): bool
    {
        if ($name === '' || strlen($name) > 120) {
            return false;
        }
        $labels = explode('.', $name);
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }
        return true;
    }

    private function decodeJson($value)
    {
        if (empty($value)) {
            return [];
        }
        $data = json_decode($value, true);
        return is_array($data) ? $data : [];
    }

    private function statusText($status): string
    {
        return match (intval($status)) {
            self::STATUS_PENDING => '待审核',
            self::STATUS_ACTIVE => '已生效',
            self::STATUS_REJECTED => '已拒绝',
            self::STATUS_EXPIRED => '已过期',
            self::STATUS_REVOKED => '已注销',
            default => '未知',
        };
    }

    private function addLog($uid, $action, $data = '', $domain = ''): void
    {
        Db::name('log')->insert([
            'uid' => $uid,
            'action' => $action,
            'domain' => $domain,
            'data' => $data,
            'addtime' => date('Y-m-d H:i:s'),
        ]);
    }
}
