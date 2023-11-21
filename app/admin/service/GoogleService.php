<?php

namespace app\admin\service;


use googleAuth\GoogleAuthenticator;
use think\admin\model\SystemUser;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\db\exception\PDOException;
use think\Exception;
use think\Request;

/**
 * 谷歌令牌
 * Class Users
 * @package app\admin\controller
 */
class GoogleService
{
    /**
     * 指定当前数据表
     * @var string
     */
    public $table = 'system_user';

    public static function instance(): self
    {
        return new self();
    }

    /**
     * 检测是否绑定令牌
     * @param int $uid 账号ID
     * @return boolean
     */
    public function isBind(int $uid): bool
    {
        $google_is_bind = SystemUser::where(['id' => $uid])->value('google_is_bind');
        return $google_is_bind === 1;
    }

    /**
     * 绑定谷歌验证
     * @param int $uid 账号ID
     * @return boolean|array
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     * @throws PDOException
     */
    public function getBindUrl(int $uid)
    {
        $googleAuth = new GoogleAuthenticator();
        $secret = SystemUser::where(['id' => $uid])
            ->field('google_secret,google_url,username')->find();
        $re = [];
        $uname = $secret['username'];
        if (!$secret['google_secret']) {
            $secret = $googleAuth->createSecret();  //谷歌密钥
            if ($secret) {
                $qrCodeUrl = $googleAuth->getQRCodeGoogleUrl('global-qd@' . \request()->rootDomain() . '@' . $uname, $secret); //谷歌二维码
                //$oneCode = $googleAuth->getCode($secret);
                SystemUser::where(['id' => $uid])->update([
                    'google_secret' => $secret,
                    'google_url' => $qrCodeUrl,
                    'google_is_bind' => 0
                ]);
                $re['google_secret'] = $secret;
                $re['google_url'] = $qrCodeUrl;
            } else {
                return false;
            }
        } else {
            $re['google_secret'] = $secret['google_secret'];
            $re['google_url'] = $secret['google_url'];
        }
        return $re;
    }

    /**
     * 设置为已绑定
     * @param int $uid 账号ID
     */
    public function setBind(int $uid)
    {
        return SystemUser::where(['id' => $uid])->update([
            'google_is_bind' => 1
        ]);
    }

    /**
     *  谷歌验证
     * @param int $uid 账号ID
     * @param string $code 谷歌验证码
     * @return boolean
     */
    public function checkCode(int $uid, string $code): bool
    {
        $googleAuth = new GoogleAuthenticator();
        $secret = SystemUser::where(['id' => $uid])->value('google_secret');
        // 2 = 2*30sec clock tolerance
        return $googleAuth->verifyCode($secret, $code, 2);
    }
}