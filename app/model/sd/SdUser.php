<?php

namespace app\model\sd;

use Exception;
use think\admin\model\SystemUser;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\db\exception\PDOException;
use think\facade\Validate;
use think\admin\Model;

class SdUser extends Model
{
    protected array $rule = [
        'tel' => 'require',
        'username' => 'require',
        //'username' => 'require|length:3,15',
        'pwd' => 'require|length:6,16',
        '__token__' => 'token',
    ];
    protected array $info = [
        'tel.require' => '手机号不能为空！',
        //'tel.mobile' => '手机号格式错误！',
        'username.length' => '用户名长度为3-10字符！',
        'username.require' => '用户名不能为空！',
        'pwd.require' => '密码不能为空！',
        'pwd.length' => '密码长度为6-16位字符！',
        '__token__' => '令牌已过期，请刷新页面再试！',
    ];

    protected function initialize(): void
    {
        parent::initialize();
        $this->info['tel.require'] = lang('tel_none');
        $this->info['username.length'] = lang('username_len');
        $this->info['username.require'] = lang('username_none');
        $this->info['pwd.require'] = lang('login_pass');
        $this->info['pwd.length'] = lang('pwd_length');
    }




    private $_agent_id = 0;

    /**
     * 获取当前域名代理id
     * @return int
     */
    public function get_agent_id()
    {
        if ($this->_agent_id) return $this->_agent_id;
        $aid = 0;
        $sub = request()->subDomain();
        if ($sub) {
            $user = SystemUser::where('username', $sub)->find();
            if (!empty($user) && $user['authorize'] == 2) {
                $aid = $user['id'];
            }
        }
        $this->_agent_id = intval($aid);
        return $this->_agent_id;
    }

    /**
     * 检查用户是否和代理地址匹配
     * @return bool
     */
    public function check_user_is_agent_id($uid)
    {
        $aid = $this->get_agent_id();
        $u_aid = self::where('id', $uid)->value('agent_id');
        return $u_aid == $aid;
    }

    /**
     * 充值审核通过
     * @param int $oid
     * @param int $source 来源0=系统操作，1=支付回掉
     * @return bool
     */
    public function recharge_success($oid, $source = 0)
    {
        $oinfo = SdRecharge::find($oid);
        $user = self::where('id', $oinfo['uid'])->find();
        //累计充值金额
        $all_recharge = $oinfo['num'] + SdRecharge::where([
                'uid' => $oinfo['uid'],
                'status' => 2
            ])->sum('num');
        //判断是否要升级VIP
        $new_vip_level = 0;
        $level_list = SdLevel::field('level,`num`')
            ->where('num', '>', 0)
            ->order('level desc')->select();
        foreach ($level_list as $v) {
            if ($v['num'] <= $all_recharge) {
                $new_vip_level = $v['level'];
                break;
            }
        }
        if ($new_vip_level > 0) {
            if ($new_vip_level <= $user['level']) {
                $new_vip_level = 0;
            }
        }
        //判断是否为第一次充值， 如果是 那么清空账户余额！
        $is_first = SdRecharge::where([
            'uid' => $oinfo['uid'],
            'status' => 2
        ])->value('id');
		$this->startTrans();
        if (!$is_first) {
            //扣除体验金
            $ba = config('free_balance');
            //self::where('id', $oinfo['uid'])->update([
            //'balance' => 0,
            //'balance' => Db::raw('balance-' . $ba),
            //'freeze_balance' => 0,
            //'deal_error' => 0,
            //'deal_reward_count' => 0,
            //'deal_count' => 0,
            //'deal_time' => 0,
            //'lixibao_balance' => 0,
            //'lixibao_dj_balance' => 0,
            //'is_clean_free' => 0,
            //]);
            //self::where('id', $oinfo['uid'])->setDec('balance', config('free_balance'));
            //Db::name('xy_convey')->where(['uid' => $oinfo['uid']])->where('status', 0)->update(['status' => 4]);
            //Db::name('xy_lixibao')->where(['uid' => $oinfo['uid']])->delete();
            //Db::name('xy_convey')->where(['uid' => $oinfo['uid']])->delete();
            //SdBalanceLog::where(['uid' => $oinfo['uid']])->delete();
            //SdBalanceLog::where(['sid' => $oinfo['uid']])->delete();
            //Db::name('xy_reward_log')->where(['uid' => $oinfo['uid']])->delete();
            //Db::name('xy_reward_log')->where(['sid' => $oinfo['uid']])->delete();
        }

        $status = 2;
        $upArr = ['endtime' => time(), 'status' => $status];
        if ($source == 1) {
            $upArr['pay_status'] = 1;
        }
        $res = Db::name('xy_recharge')
            ->where('id', $oid)
            ->update($upArr);
        if ($new_vip_level > 0) {
            $res3 = self::where('id', $oinfo['uid'])
                ->update(['level' => $new_vip_level]);
        } else $res3 = true;
        $res1 = Db::name($this->table)->where('id', $oinfo['uid'])
            ->setInc('balance', $oinfo['num']);
        $res4 = self::where('id', $oinfo['uid'])
            ->update([
                'all_recharge_num' => Db::raw('all_recharge_num+' . $oinfo['num']),
                'all_recharge_count' => Db::raw('all_recharge_count+1'),
            ]);

        $res2 = SdBalanceLog::insert(['uid' => $oinfo['uid'], 'oid' => $oid, 'num' => $oinfo['num'], 'type' => 1, 'status' => 1, 'addtime' => time()]);
        //推荐人给钱 ///第一次才给
        if (!$is_first) {
            $t_money = floatval($oinfo['num'] * config('invite_recharge_money'));
            if ($t_money > 0) {
	            Db::name($this->table)->where('id', $user['parent_id'])
                    ->setInc('balance', $t_money);
                SdBalanceLog::insert(['uid' => $user['parent_id'], 'oid' => $oid, 'num' => $t_money, 'type' => 5, 'status' => 1, 'addtime' => time()]);
            }
        }
        if ($res && $res1 && $res3 && $res4) {
			$this->commit();
            return true;
        }
		$this->rollback();
        return false;
    }

    /**
     * 添加会员
     *
     * @param string $tel
     * @param string $user_name
     * @param string $pwd
     * @param int $parent_id
     * @param string $token
     * @param string $pwd2
     * @param string $ip
     * @param int $agent_id
     * @return array
     * @throws PDOException|Exception
     */
    public function add_user($tel, $user_name, $pwd, $parent_id, $token = '', $pwd2 = '', $agent_id = 0, $ip = '')
    {
        $tmp = self::where(['tel' => $tel])->count();
        if ($tmp) {
            return ['code' => 1, 'info' => lang('zhbcz')];
        }
        $tmp = self::where(['username' => $user_name])->count();
        if ($tmp) {
            return ['code' => 1, 'info' => lang('username_exists')];
        }
        if (!$user_name) $user_name = get_username();
        $data = [
            'tel' => $tel,
            'username' => $user_name,
            'pwd' => $pwd,
            'parent_id' => $parent_id,
        ];
        if ($token) $data['__token__'] = $token;

        //验证表单
        $validate = Validate::make($this,$this->rule, $this->info);
        if (!$validate->check($data)) {
            return ['code' => 1, 'info' => $validate->getError()];
        }
        if ($parent_id) {
            $parent_id = self::where('id', $parent_id)->value('id');
            if (!$parent_id) {
                return ['code' => 1, 'info' => lang('sjidbcz')];
            }
        }
        $data['ip'] = $ip;
        $ip_register_number = intval(config('ip_register_number'));
        if ($ip_register_number > 0 && $ip) {
            $uIpId = self::where('ip', $ip)->count('id');
            if ($uIpId >= $ip_register_number) {
                return ['code' => 1, 'info' => lang('reg_ip_error')];
            }
        }

        $salt = rand(0, 99999);  //生成盐
        $invite_code = self::create_invite_code();//生成邀请码

        $ft = config('free_balance_time');
        $ft = floatval($ft) * 3600;
        //给体验账户加体验金
        $data['agent_id'] = $agent_id;
        $data['level'] = 1;
        $data['is_clean_free'] = time() + $ft;
        $data['balance'] = config('free_balance');


        $data['pwd'] = sha1($pwd . $salt . config('pwd_str'));
        $data['salt'] = $salt;
        $data['addtime'] = time();
        $data['invite_code'] = $invite_code;
        if ($pwd2) {
            $salt2 = rand(0, 99999);  //生成盐
            $data['pwd2'] = sha1($pwd2 . $salt2 . config('pwd_str'));
            $data['salt2'] = $salt2;
        }
        //return ['code' => 1, 'info' => lang('czsb'),'ddd'=>$data];
        //开启事务
        unset($data['__token__']);
        $this->startTrans();
        $res = self::insertGetId($data);
        if ($parent_id) {
            $res2 = self::where('id', $data['parent_id'])->update([
                'childs' => Db::raw('childs+1'),
                'deal_reward_count' => Db::raw('deal_reward_count+' . config('deal_reward_count')),
                'balance' => Db::raw('balance+' . config('invite_one_money'))
            ]);
            SdBalanceLog::insert([
                'uid' => $data['parent_id'],
                'sid' => $res,
                'oid' => '',
                'num' => config('invite_one_money'),
                'type' => 5,
                'status' => 1,
                'addtime' => time()
            ]);
        } else {
            $res2 = true;
        }
        //生成二维码
        self::create_qrcode($invite_code, $res);
        if ($res && $res2) {
            //生成关系链
            $this->update_user_invites($res);
            // 提交事务
            $this->commit();
            //更新用户service_id
            $s = $this->get_user_service_id($res);
            if (!empty($s['id'])) {
                self::where('id', $res)
                    ->update(['agent_id' => $s['id']]);
            }

            return ['code' => 0, 'info' => lang('czcg'), 'id' => $res];
        } else
            // 回滚事务
            $this->rollback();
        return ['code' => 1, 'info' => lang('czsb')];
    }

    /**
     * 更新或创建用户关系链
     *
     * @param int $uid 用户编号
     * @param bool $isUpdate 是否强制更新
     * @return bool
     * @throws \think\Exception
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     * @throws PDOException
     */
    public function update_user_invites($uid, $isUpdate = false)
    {
        $uInvites = SdUserRelation::where('uid', $uid)->find();
        if (empty($uInvites['uid']) || $isUpdate) {
            //么有就创建
            $uLevel = $this->re_user_invites_uids($uid);
            $uLevel[0] = trim($uLevel[0], ',');
            if (empty($uInvites['uid'])) {
                SdUserRelation::insert([
                    'uid' => $uid,
                    'level' => $uLevel[1],
                    'ids' => $uLevel[0]
                ]);
            } else {
                SdUserRelation::where('uid', $uid)->update([
                    'level' => $uLevel[1],
                    'ids' => $uLevel[0]
                ]);
            }
        }
        return true;
    }

    /**
     * 获取用户关系链 及 代数
     *
     * @param int $uid 用户编号
     * @param int $lv 代数
     * @param string $uids 关系链
     * @return array   [关系链，代数]
     */
    private function re_user_invites_uids($uid, $lv = 0, $uids = '')
    {
        $lv = $lv + 1;
        $uids = $uid . ',' . $uids;
        $parent_id = self::where('id', $uid)->value('parent_id');
        if ($parent_id > 0) {
            return $this->re_user_invites_uids($parent_id, $lv, $uids);
        }
        //如果信息不存在 那么就是 id和1代
        return [$uids, $lv];
    }

	public function edit_user_status($id, $status)
    {
        $status = intval($status);
        $id = intval($id);

        if (!in_array($status, [1, 2])) return ['code' => 1, 'info' => lang('cscw')];

        if ($status == 2) {
            //查看有无未完成的订单
            // if($num > 0)$this->error('该用户尚有未完成的支付订单！');
        }

        $res = self::where('id', $id)->update(['status' => $status]);
        if ($res !== false)
            return ['code' => 0, 'info' => lang('czcg')];
        else
            return ['code' => 1, 'info' => lang('czsb')];
    }

	/**
	 * 编辑用户
	 *
	 * @param int $id
	 * @param string $tel
	 * @param string $user_name
	 * @param string $pwd
	 * @param int $parent_id
	 * @param $balance
	 * @param $freeze_balance
	 * @param string $token
	 * @param string $pwd2
	 * @param int $agent_id
	 * @return array
	 * @throws PDOException|Exception
	 */
	public function edit_user($id, $tel, $user_name, $remark, $pwd, $parent_id, $balance, $freeze_balance, $token, $pwd2 = '',$agent_id)
	{
		$tmp = self::where(['tel' => $tel])->where('id', '<>', $id)->count();
		if ($tmp) {
			return ['code' => 1, 'info' => lang('sjhmyzc')];
		}
		$data = [
			'tel' => $tel,
			'freeze_balance' => $freeze_balance,
			'username' => $user_name,
			'remark' => $remark,
			'parent_id' => $parent_id,
			'__token__' => $token,
		];
		//当前是代理修改，且允许代理修改用户余额
		if($agent_id&&config("allow_agent_modify_balance")){
			$data['balance'] = $balance;
		}
		//管理员允许修改用户余额
		if(!$agent_id){
			$data['balance'] = $balance;
		}

		if ($pwd) {
			//不提交密码则不改密码
			$data['pwd'] = $pwd;
		} else {
			$this->rule['pwd'] = '';
		}
		if ($parent_id) {
			$parent_id = self::where('id', $parent_id)->value('id');
			if (!$parent_id) {
				return ['code' => 1, 'info' => lang('sjidbcz')];
			}
			$data['parent_id'] = $parent_id;
		}

		$validate = Validate::make($this,$this->rule, $this->info);//验证表单
		if (!$validate->check($data)) return ['code' => 1, 'info' => $validate->getError()];

		if ($pwd) {
			$salt = rand(0, 99999); //生成盐
			$data['pwd'] = sha1($pwd . $salt . config('pwd_str'));
			$data['salt'] = $salt;
		}
		if ($pwd2) {
			$salt2 = rand(0, 99999); //生成盐
			$data['pwd2'] = sha1($pwd2 . $salt2 . config('pwd_str'));
			$data['salt2'] = $salt2;
		}


		unset($data['__token__']);
		$res = self::where('id', $id)->update($data);
		if ($res)
			return ['code' => 0, 'info' => lang('czcg')];
		else
			return ['code' => 1, 'info' => lang('czsb')];
	}

	//生成邀请码
    public static function create_invite_code()
    {
        $str = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $rand_str = substr(str_shuffle($str), 0, 6);
        $num = self::where('invite_code', $rand_str)->count();
        if ($num)
            // return $this->create_invite_code();
            return self::create_invite_code();
        else
            return $rand_str;
    }


    /**
     * 重置密码
     */
    public function reset_pwd($tel, $pwd, $type = 1)
    {
        $data = [
            'tel' => $tel,
            'pwd' => $pwd,
        ];
        unset($this->rule['username']);
        $validate = Validate::make($this,$this->rule, $this->info);//验证表单
        if (!$validate->check($data)) return ['code' => 1, 'info' => $validate->getError()];

        $user_id = self::where(['tel' => $tel])->value('id');
        if (!$user_id) {
            return ['code' => 1, 'info' => lang('not_user')];
        }

        $salt = mt_rand(0, 99999);
        if ($type == 1) {
            $data = [
                'pwd' => sha1($pwd . $salt . config('pwd_str')),
                'salt' => $salt,
            ];
        } elseif ($type == 2) {
            $data = [
                'pwd2' => sha1($pwd . $salt . config('pwd_str')),
                'salt2' => $salt,
            ];
        }

        $res = self::where('id', $user_id)->update($data);

        if ($res)
            return ['code' => 0, 'info' => lang('czcg')];
        else
            return ['code' => 1, 'info' => lang('czsb')];

    }

    //获取上级会员
    public function parent_user($uid, $num = 1, $lv = 1)
    {
        $pid = self::where('id', $uid)->value('parent_id');
        $uinfo = self::where('id', $pid)->find();
        if ($uinfo) {
            if ($uinfo['parent_id'] && $num > 1) $data = self::parent_user($uinfo['id'], $num - 1, $lv + 1);
            $data[] = ['id' => $uinfo['id'], 'pid' => $uinfo['parent_id'], 'level' => $uinfo['level'], 'lv' => $lv, 'status' => $uinfo['status']];
            return $data;
        }
        return false;
    }


    //获取下级会员
    public function child_user($uid, $num = 1, $lv = 1)
    {

        $data = [];
        $where = [];
        if ($num == 1) {
            $data = self::where('parent_id', $uid)->field('id')->column('id');
        } else if ($num == 2) {
            //二代
            $ids1 = self::where('parent_id', $uid)->column('id');
            $ids1 ? $where[] = ['parent_id', 'in', $ids1] : $where[] = ['parent_id', 'in', [-1]];
            $data = self::where($where)->column('id');
            $data = $lv ? array_merge($ids1, $data) : $data;
        } else if ($num == 3) {
            //三代
            $ids1 = self::where('parent_id', $uid)->field('id')->column('id');
            $ids1 ? $wher[] = ['parent_id', 'in', $ids1] : $wher[] = ['parent_id', 'in', [-1]];
            $ids2 = self::where($wher)->field('id')->column('id');
            $ids2 ? $where[] = ['parent_id', 'in', $ids2] : $where[] = ['parent_id', 'in', [-1]];
            $data = self::where($where)->field('id')->column('id');
            $data = $lv ? array_merge($ids1, $ids2, $data) : $data;
        } else if ($num == 4) {
            //四带
            $ids1 = self::where('parent_id', $uid)->field('id')->column('id');
            $ids1 ? $wher[] = ['parent_id', 'in', $ids1] : $wher[] = ['parent_id', 'in', [-1]];
            $ids2 = self::where($wher)->field('id')->column('id');
            $ids2 ? $where2[] = ['parent_id', 'in', $ids2] : $where2[] = ['parent_id', 'in', [-1]];
            $ids3 = self::where($where2)->field('id')->column('id');
            $ids3 ? $where[] = ['parent_id', 'in', $ids3] : $where[] = ['parent_id', 'in', [-1]];
            $data = self::where($where)->field('id')->column('id');
            $data = $lv ? array_merge($ids1, $ids2, $ids3, $data) : $data;

        } else if ($num == 5) {
            //四带
            $ids1 = self::where('parent_id', $uid)->field('id')->column('id');
            $ids1 ? $wher[] = ['parent_id', 'in', $ids1] : $wher[] = ['parent_id', 'in', [-1]];
            $ids2 = self::where($wher)->field('id')->column('id');
            $ids2 ? $where2[] = ['parent_id', 'in', $ids2] : $where2[] = ['parent_id', 'in', [-1]];
            $ids3 = self::where($where2)->field('id')->column('id');
            $ids3 ? $where3[] = ['parent_id', 'in', $ids3] : $where3[] = ['parent_id', 'in', [-1]];
            $ids4 = self::where($where3)->field('id')->column('id');
            $ids4 ? $where[] = ['parent_id', 'in', $ids4] : $where[] = ['parent_id', 'in', [-1]];
            $data = self::where($where)->field('id')->column('id');

            $data = $lv ? array_merge($ids1, $ids2, $ids3, $ids4, $data) : $data;
        }

        return $data;
    }

    /**
     * 获取所有下级会员
     * @param int $uid 用户编号
     * @param int $level 获取到第几代
     * @param bool $isMerge
     * @return array|int
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    public function get_child_user($uid, $level = 1, $isMerge = true)
    {
        if ($level == 1) return $uid;
        $uInvites = SdUserRelation::where('uid', $uid)->find();
        $sumLevel = $uInvites['level'] + $level - 1;
        if ($isMerge) {
            $result = SdUserRelation::where('level', '<=', $sumLevel)
                ->where('ids', 'like', $uInvites['ids'] . ',%')
                ->column('uid');
        } else {
            $result = SdUserRelation::where('level', $sumLevel)
                ->where('ids', 'like', $uInvites['ids'] . ',%')
                ->column('uid');
        }
        $result ? $result[] = $uid : $result = [$uid];
        return $result;
    }

    /**
     * 获取会员所属 代理-客服
     * @param int $uid 用户编号
     * @return array|null   返回格式: system_user
     */
    public function get_user_service_id($uid)
    {
        //查找代理名下 客服的代码
        $mids = SdUserRelation::where('uid', $uid)->value('ids');
        if (!empty($mids)) {
            $service = SystemUser::where('user_id', 'in', $mids)
                ->order('id desc')
                ->limit(1)->find();
        }
        if (empty($service)) {
            //查找代理的 客服代码
            $agent_id = self::where('id', $uid)->value('agent_id');
            if (!empty($agent_id)) {
                $service = SystemUser::where('id', $agent_id)
                    ->limit(1)->find();
            } else $service = null;
        }
        return $service ?: null;
    }
    //获取用户所属代理的客服链接
    public function get_user_agent_kf($uid){
        //查找代理的 客服代码
        $agent_id = self::where('id', $uid)->value('agent_id');
        if (!empty($agent_id)) {
            $service = SystemUser::where('id', $agent_id)
                ->limit(1)->find();
        } else $service = null;
        return $service;
    }

    /**
     * 提现失败 --- 付款失败回掉
     * @param array $oinfo
     * @return bool
     */
    public function payout_rollback($oinfo)
    {
        //不是成功状态 不管
        if ($oinfo['status'] != 2) {
            return true;
        }
		$this->startTrans();
        $res1 = Db::name($this->table)->where('id', $oinfo['uid'])->setInc('balance', $oinfo['num']);
        $res2 = SdWithdraw::where('id', $oinfo['id'])->update([
            'status' => 4,
            'endtime' => time()
        ]);
        $res3 = SdBalanceLog::insert([
            'uid' => $oinfo['uid'],
            'oid' => $oinfo['id'],
            'num' => $oinfo['num'],
            'type' => 8,
            'status' => 1,
            'addtime' => time()
        ]);
        SdMessage::insert([
                'uid' => $oinfo['uid'],
                'type' => 2,
                'title' => lang('sys_msg'),
                'content' => sprintf("Your withdrawal order: %s has been rejected,", $oinfo['id']) . ' ' . (!empty($oinfo['payout_err_msg']) ? $oinfo['payout_err_msg'] : ''),
                'addtime' => time()
            ]);
        if ($res1 && $res2 && $res3) {
			$this->commit();
            return true;
        }
		$this->rollback();
        return false;
    }
}
