<?php

namespace app\controller;

use app\BaseController;
use app\utils\MsgNotice;
use Exception;
use think\facade\Db;

class Auth extends BaseController
{

    public function login()
    {
        $login_limit_count = 5; //登录失败次数
        $login_limit_file = app()->getRuntimePath() . '@login.lock';

        if ($this->request->islogin) {
            return redirect('/');
        }

        if ($this->request->isAjax()) {
            $username = input('post.username', null, 'trim');
            $password = input('post.password', null, 'trim');
            $code = input('post.code', null, 'trim');

            if (empty($username) || empty($password)) {
                return json(['code' => -1, 'msg' => '用户名或密码不能为空']);
            }
            if (config_get('vcode', '1') == '1' && !captcha_check($code)) {
                return json(['code' => -1, 'msg' => '验证码错误', 'vcode' => 1]);
            }
            if (file_exists($login_limit_file)) {
                $login_limit = unserialize(file_get_contents($login_limit_file));
                if ($login_limit['count'] >= $login_limit_count && $login_limit['time'] > time() - 7200) {
                    return json(['code' => -1, 'msg' => '多次登录失败，暂时禁止登录。可删除/runtime/@login.lock文件解除限制', 'vcode' => 1]);
                }
            }
            $user = Db::name('user')->where('username', $username)->find();
            if (!$user && filter_var($username, FILTER_VALIDATE_EMAIL)) {
                $user = Db::name('user')->where('email', $username)->find();
            }
            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] == 0) return json(['code' => -1, 'msg' => '此用户已被封禁', 'vcode' => 1]);
                if (!empty($user['email']) && intval($user['email_verified']) !== 1) {
                    return json(['code' => -1, 'msg' => '邮箱未验证，请先完成邮箱验证', 'need_verify' => 1, 'email' => $user['email'], 'vcode' => 1]);
                }
                if (isset($user['totp_open']) && $user['totp_open'] == 1 && !empty($user['totp_secret'])) {
                    session('pre_login_user', $user['id']);
                    if (file_exists($login_limit_file)) {
                        unlink($login_limit_file);
                    }
                    return json(['code' => -1, 'msg' => '需要验证动态口令', 'vcode' => 2]);
                }
                $this->loginUser($user);
                if (file_exists($login_limit_file)) {
                    unlink($login_limit_file);
                }
                return json(['code' => 0]);
            } else {
                if ($user) {
                    Db::name('log')->insert(['uid' => $user['id'], 'action' => '登录失败', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s")]);
                    if (isset($user['totp_open']) && $user['totp_open'] == 1 && !empty($user['totp_secret'])) {
                        return json(['code' => -1, 'msg' => '用户名或密码错误', 'vcode' => 1]);
                    }
                }
                if (!file_exists($login_limit_file)) {
                    $login_limit = ['count' => 0, 'time' => 0];
                }
                $login_limit['count']++;
                $login_limit['time'] = time();
                file_put_contents($login_limit_file, serialize($login_limit));
                $retry_times = $login_limit_count - $login_limit['count'];
                if ($retry_times == 0) {
                    return json(['code' => -1, 'msg' => '多次登录失败，暂时禁止登录。可删除/runtime/@login.lock文件解除限制', 'vcode' => 1]);
                } else {
                    return json(['code' => -1, 'msg' => '用户名或密码错误，你还可以尝试' . $retry_times . '次', 'vcode' => 1]);
                }
            }
        }

        return view();
    }

    public function register()
    {
        if ($this->request->islogin) {
            return redirect('/');
        }
        if (config_get('subdomain_enabled', '1') !== '1') {
            if ($this->request->isAjax()) {
                return json(['code' => -1, 'msg' => '当前未开放注册']);
            }
            return $this->alert('error', '当前未开放注册', '/login');
        }

        if ($this->request->isAjax()) {
            $username = input('post.username', null, 'trim');
            $email = input('post.email', null, 'trim');
            $password = input('post.password', null, 'trim');
            $confirm = input('post.confirm', null, 'trim');

            if (empty($username) || empty($email) || empty($password) || empty($confirm)) {
                return json(['code' => -1, 'msg' => '请完整填写注册信息']);
            }
            if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
                return json(['code' => -1, 'msg' => '用户名需为3-32位字母、数字或下划线']);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return json(['code' => -1, 'msg' => '邮箱格式不正确']);
            }
            if (strlen($password) < 8) {
                return json(['code' => -1, 'msg' => '密码至少需要8位']);
            }
            if ($password !== $confirm) {
                return json(['code' => -1, 'msg' => '两次输入的密码不一致']);
            }
            if (Db::name('user')->where('username', $username)->find()) {
                return json(['code' => -1, 'msg' => '用户名已存在']);
            }
            if (Db::name('user')->where('email', $email)->find()) {
                return json(['code' => -1, 'msg' => '邮箱已被使用']);
            }

            $quota = intval(config_get('subdomain_initial_quota', 0));
            $now = date('Y-m-d H:i:s');

            Db::startTrans();
            try {
                $uid = Db::name('user')->insertGetId([
                    'username' => $username,
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'is_api' => 0,
                    'apikey' => null,
                    'level' => 1,
                    'regtime' => $now,
                    'lasttime' => null,
                    'totp_open' => 0,
                    'totp_secret' => null,
                    'status' => 1,
                    'email_verified' => 0,
                    'verify_token' => null,
                    'verify_sent_at' => null,
                    'subdomain_quota' => $quota,
                ]);
                Db::name('log')->insert([
                    'uid' => $uid,
                    'action' => '用户注册',
                    'data' => 'IP:' . $this->clientip,
                    'addtime' => $now,
                ]);
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                return json(['code' => -1, 'msg' => '注册失败，' . $e->getMessage()]);
            }

            $user = Db::name('user')->where('id', $uid)->find();
            $mailResult = $this->sendVerificationEmail($user);
            if ($mailResult === true) {
                return json(['code' => 0, 'msg' => '注册成功，验证邮件已发送，请查收']);
            }
            return json(['code' => 0, 'msg' => '注册成功，但邮件发送失败：' . $mailResult]);
        }

        return view();
    }

    public function resendVerification()
    {
        if (!$this->request->isAjax()) {
            return json(['code' => -1, 'msg' => '不支持的请求方式']);
        }
        $account = input('post.account', null, 'trim');
        if (empty($account)) {
            return json(['code' => -1, 'msg' => '请输入邮箱或用户名']);
        }
        $user = Db::name('user')->where('email', $account)->find();
        if (!$user) {
            $user = Db::name('user')->where('username', $account)->find();
        }
        if (!$user) {
            return json(['code' => -1, 'msg' => '未找到对应的用户']);
        }
        if (empty($user['email'])) {
            return json(['code' => -1, 'msg' => '该用户未绑定邮箱']);
        }
        if (intval($user['email_verified']) === 1) {
            return json(['code' => -1, 'msg' => '该邮箱已完成验证']);
        }
        if (!empty($user['verify_sent_at']) && strtotime($user['verify_sent_at']) > time() - 300) {
            return json(['code' => -1, 'msg' => '请稍后再试，验证邮件发送频率不得少于5分钟']);
        }

        $result = $this->sendVerificationEmail($user);
        if ($result === true) {
            return json(['code' => 0, 'msg' => '验证邮件已重新发送']);
        }
        return json(['code' => -1, 'msg' => '邮件发送失败：' . $result]);
    }

    public function verifyEmail()
    {
        $token = input('get.token', null, 'trim');
        if (empty($token)) {
            return $this->alert('error', '验证链接无效', '/login');
        }
        $user = Db::name('user')->where('verify_token', $token)->find();
        if (!$user) {
            return $this->alert('error', '验证链接已失效或用户不存在', '/login');
        }
        if (intval($user['email_verified']) === 1) {
            return $this->alert('success', '邮箱已完成验证，请登录', '/login');
        }
        Db::name('user')->where('id', $user['id'])->update([
            'email_verified' => 1,
            'verify_token' => null,
            'verify_sent_at' => null,
        ]);
        Db::name('log')->insert([
            'uid' => $user['id'],
            'action' => '邮箱验证',
            'data' => 'IP:' . $this->clientip,
            'addtime' => date('Y-m-d H:i:s'),
        ]);
        return $this->alert('success', '邮箱验证成功，请登录', '/login');
    }

    public function totp()
    {
        $uid = session('pre_login_user');
        if (empty($uid)) return json(['code' => -1, 'msg' => '请重新登录']);
        $code = input('post.code');
        if (empty($code)) return json(['code' => -1, 'msg' => '请输入动态口令']);
        $user = Db::name('user')->where('id', $uid)->find();
        if (!$user) return json(['code' => -1, 'msg' => '用户不存在']);
        if ($user['totp_open'] == 0 || empty($user['totp_secret'])) return json(['code' => -1, 'msg' => '未开启TOTP二次验证']);
        try {
            $totp = \app\lib\TOTP::create($user['totp_secret']);
            if (!$totp->verify($code)) {
                return json(['code' => -1, 'msg' => '动态口令错误']);
            }
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
        $this->loginUser($user);
        session('pre_login_user', null);
        return json(['code' => 0]);
    }

    public function logout()
    {
        cookie('user_token', null);
        return redirect('/login');
    }

    public function quicklogin()
    {
        $domain = input('get.domain', null, 'trim');
        $timestamp = input('get.timestamp', null, 'trim');
        $token = input('get.token', null, 'trim');
        $sign = input('get.sign', null, 'trim');
        if (empty($domain) || empty($timestamp) || empty($token) || empty($sign)) {
            return $this->alert('error', '参数错误');
        }
        if ($timestamp < time() - 300 || $timestamp > time() + 300) {
            return $this->alert('error', '时间戳无效');
        }
        if (md5(config_get('sys_key') . $domain . $timestamp . $token . config_get('sys_key')) !== $sign) {
            return $this->alert('error', '签名错误');
        }
        if ($token != cache('quicklogin_' . $domain)) {
            return $this->alert('error', 'Token无效');
        }
        $row = Db::name('domain')->where('name', $domain)->find();
        if (!$row) {
            return $this->alert('error', '该域名不存在');
        }
        if (!$row['is_sso']) {
            return $this->alert('error', '该域名不支持快捷登录');
        }

        $this->loginDomain($row);
        return redirect('/record/' . $row['id']);
    }

    private function loginUser($user)
    {
        Db::name('log')->insert(['uid' => $user['id'], 'action' => '登录后台', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s")]);
        DB::name('user')->where('id', $user['id'])->update(['lasttime' => date("Y-m-d H:i:s")]);
        $session = md5($user['id'] . $user['password']);
        $expiretime = time() + 2562000;
        $token = authcode("user\t{$user['id']}\t{$session}\t{$expiretime}", 'ENCODE', config_get('sys_key'));
        cookie('user_token', $token, ['expire' => $expiretime, 'httponly' => true]);
    }

    private function loginDomain($row)
    {
        Db::name('log')->insert(['uid' => 0, 'action' => '域名快捷登录', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s"), 'domain' => $row['name']]);
        $session = md5($row['id'] . $row['name']);
        $expiretime = time() + 2562000;
        $token = authcode("domain\t{$row['id']}\t{$session}\t{$expiretime}", 'ENCODE', config_get('sys_key'));
        cookie('user_token', $token, ['expire' => $expiretime, 'httponly' => true]);
    }

    private function sendVerificationEmail(array $user)
    {
        if (empty($user['email'])) {
            return '未设置邮箱地址';
        }
        $token = getSid();
        $now = date('Y-m-d H:i:s');
        Db::name('user')->where('id', $user['id'])->update([
            'verify_token' => $token,
            'verify_sent_at' => $now,
        ]);
        $verifyUrl = $this->request->root(true) . '/verify-email?token=' . urlencode($token);
        $subject = '邮箱验证通知';
        $content = '尊敬的用户，您好：<br/>请点击以下链接完成邮箱验证：<br/><a href="' . $verifyUrl . '">' . $verifyUrl . '</a><br/><br/>如果无法点击，请将链接复制到浏览器打开。<br/><br/>本次验证请求来自 IP：' . $this->clientip . '<br/>时间：' . $now;
        $result = MsgNotice::send_mail($user['email'], $subject, $content);
        if ($result === true) {
            return true;
        }
        return is_string($result) ? $result : '邮件发送失败';
    }

    public function verifycode()
    {
        return captcha();
    }
}
