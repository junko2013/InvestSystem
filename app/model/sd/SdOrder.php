<?php

namespace app\model\sd;

use app\model\sd\SdInject as InjectDao;
use app\model\sd\SdLevel as LevelDao;
use app\model\sd\SdGroup as GroupDao;
use app\model\sd\SdUser as UsersDao;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\db\exception\PDOException;
use think\Exception;
use think\Model;

class SdOrder extends Model
{

	/**
	 * 创建订单
	 * @param int $uid 用户编号
	 * @param int $cid 商品组
	 * @return array
	 * @throws DataNotFoundException
	 * @throws ModelNotFoundException
	 * @throws DbException
	 */
    public function create_order(int $uid, int $cid = 1): array
    {
        $uinfo = UsersDao::find($uid);
        if ($uinfo['deal_status'] != 2) return ['code' => 1, 'info' => lang('qdyzz')];
        $level = $uinfo['level'] ? intval($uinfo['level']) : 0;
        $orderSetting = $this->get_user_order_setting($uid, $level);
        if ($uinfo['balance'] < $orderSetting['min_money']) {
            return [
                'code' => 1,
                'info' => sprintf(lang('zhyebz'), ($orderSetting['min_money'] - $uinfo['balance']) . ""),
                'url' => url('index/ctrl/recharge')
            ];
        }

        $min = $uinfo['balance'] * config('deal_min_num') / 100;
        $max = $uinfo['balance'] * config('deal_max_num') / 100;
        list($orderNum) = $this->get_user_group_rule($uinfo['id'], $uinfo['group_id']);
        $inject = $this->get_inject($uid, $orderNum);
        //打针
        if ($inject) {
            $min = $max = $uinfo['balance'] + $inject['scale'];
        }
        $goods = $this->rand_order($min, $max, $cid);
        $commission = $goods['num'] * $orderSetting['bili'];//交易佣金按照会员等级
        if($inject){


            if($inject['bili']==0 && $inject['bili']=='')
            {
                $commission = $inject['reward'];//打针佣金
            }
            else
            {
                $commission=$goods['num']*$inject['bili']*0.01;
            }

        }
        $id = getSn('UB');
        self::startTrans();
        $res = UsersDao::where('id', $uid)->update(['deal_status' => 3, 'deal_time' => strtotime(date('Y-m-d')), 'deal_count' => Db::raw('deal_count+1')]);//将账户状态改为交易中
        //插入佣金记录
        $c_data = [
            'id' => $id,
            'uid' => $uid,
            'level_id' => $uinfo['level'],
            'num' => $goods['num'],
            'addtime' => time(),
            'endtime' => time() + config('deal_timeout'),
            'add_id' => "",//$add_id,
            'goods_id' => $goods['id'],
            'goods_count' => $goods['count'],
            'commission' => $commission,
            'user_balance' => $uinfo['balance'],
            'user_freeze_balance' => $uinfo['freeze_balance'],
        ];
        //查出用户推荐人 发放推荐人佣金
        if ($uinfo['parent_id'] > 0) {
            $pLevel = UsersDao::where(['id' => $uinfo['parent_id']])->value('level');
            if ($pLevel) {
                $tj_bili = LevelDao::where('level', $pLevel)->value('tj_bili');
                if ($tj_bili) {
                    $c_data['parent_commission'] = $c_data['commission'] * floatval($tj_bili);
                    $c_data['parent_uid'] = $uinfo['parent_id'];
                }
            }
        }
        $res1 = self::insert($c_data);
        if ($inject) {
	        InjectDao::where('id', $inject['id'])
                ->update([
                    'in_time' => time(),
                    'in_amount' => $goods['num'],
                    'in_oid' => $id
                ]);
        }
        if ($res && $res1) {
            self::commit();
            return ['code' => 0, 'info' => lang('qd_ok'), 'oid' => $id, 'orderNum' => $orderNum];
        } else {
            self::rollback();
            return ['code' => 1, 'info' => lang('qd_sb')];
        }
    }

    /**
     * 创建杀猪组订单
     * @param int $uid 用户编号
     * @param int $cid 商品组
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws Exception
     * @throws ModelNotFoundException
     * @throws PDOException
     */
    public function create_order_group($uid, $cid = 1)
    {
//        $add_id = Db::name('xy_member_address')->where('uid', $uid)->value('id');//获取收款地址信息s
//        if (!$add_id) return ['code' => 1, 'info' => lang('wszshdz')];
        $uinfo = UsersDao::find($uid);
        if ($uinfo['deal_status'] != 2) return ['code' => 1, 'info' => lang('qdyzz')];
        $groupInfo = GroupDao::where('id', $uinfo['group_id'])->find();
        //是否符合级别最低金额
        if ($uinfo['balance'] < $groupInfo['money']) {
            return [
                'code' => 1,
                'info' => sprintf(lang('zhyebz'), ($groupInfo['money'] - $uinfo['balance']) . ""),
                'url' => url('index/ctrl/recharge')
            ];
        }
        list($orderNum, $groupRule) = $this->get_user_group_rule($uinfo['id'], $uinfo['group_id']);
        if (empty($groupRule)) {
            return ['code' => 1, 'info' => lang('qd_sb')];
        }
        $inject = $this->get_inject($uid, $orderNum);
        $time = time();
        $orderListData = [];
        //判断订单模式
        if ($groupRule['order_type'] == 1) {
            //叠加模式
            $oP = explode('|', $groupRule['order_price']);
            $ids = [];
            foreach ($oP as $bl) {
                $bl = floatval($bl);
                if ($bl < 0.01) {
                    return ['code' => 1, 'info' => lang('qd_sb')];
                }
                $min = $max = $uinfo['balance'] * $bl;
                //打针
                if ($inject) {
//                    $min = $max = $uinfo['balance'] * $bl * $inject['scale'];
                    $min = $max = $uinfo['balance'] * $bl + $inject['scale'];
                }
                $goods = $this->rand_order($min, $max, $cid);
                //计算佣金
                $commission = $this->get_commission($goods['num'], $groupRule);
                if($inject){
                    $commission = $inject["reward"];
                }
                $oid = getSn('UB');
                $ids[] = $oid;
                $orderListData[] = [
                    'id' => $oid,
                    'uid' => $uid,
                    'level_id' => $uinfo['level'],
                    'num' => $goods['num'],
                    'addtime' => $time,
                    'endtime' => $time + config('deal_timeout'),
                    'add_id' => "",//$add_id,
                    'goods_id' => $goods['id'],
                    'goods_count' => $goods['count'],
                    'commission' => $commission,
                    'group_id' => $uinfo['group_id'],
                    'group_rule_num' => $orderNum,
                    'user_balance' => $uinfo['balance'],
                    'user_freeze_balance' => $uinfo['freeze_balance'],
                ];
            }
            if (empty($orderListData)) {
                return ['code' => 1, 'info' => lang('qd_sb')];
            }
        } else {
            $min = $uinfo['balance'] * config('deal_min_num') / 100;
            $max = $uinfo['balance'] * config('deal_max_num') / 100;
            //打针
            if ($inject) {
//                $min = $max = $uinfo['balance'] * $inject['scale'];
                $min = $max = $uinfo['balance'] + $inject['scale'];
            }
            $goods = $this->rand_order($min, $max, $cid);
            //计算佣金
            $commission = $this->get_commission($goods['num'], $groupRule);
            if($inject){
                $commission = $inject["reward"];
            }
            $ids = [getSn('UB')];
            $c_data = [
                'id' => $ids[0],
                'uid' => $uid,
                'level_id' => $uinfo['level'],
                'num' => $goods['num'],
                'addtime' => $time,
                'endtime' => $time + config('deal_timeout'),
                'add_id' => "",//$add_id,
                'goods_id' => $goods['id'],
                'goods_count' => $goods['count'],
                'commission' => $commission,  //交易佣金按照会员等级
                'group_id' => $uinfo['group_id'],
                'group_rule_num' => $orderNum,
                'user_balance' => $uinfo['balance'],
                'user_freeze_balance' => $uinfo['freeze_balance'],
            ];
        }
        $other_data = [];
        //查出用户推荐人 发放推荐人佣金
        if ($uinfo['parent_id'] > 0) {
            $pLevel = Db::name('xy_users')->where(['id' => $uinfo['parent_id']])->value('level');
            if ($pLevel) {
                $tj_bili = Db::name('xy_level')->where('level', $pLevel)->value('tj_bili');
                if ($tj_bili) {
                    if (isset($c_data)) $c_data['parent_commission'] = floatval($c_data['commission']) * floatval($tj_bili);
                    $other_data['parent_uid'] = $uinfo['parent_id'];
                }
            }
        }
        //事务处理
        Db::startTrans();
        //将账户状态改为交易中
        $res = Db::name('xy_users')->where('id', $uid)
            ->update(['deal_status' => 3,
                'deal_time' => strtotime(date('Y-m-d')),
                'deal_count' => Db::raw('deal_count+1')
            ]);
        //插入订单记录
        if ($groupRule['order_type'] == 1) {
            $oRes = [];
            foreach ($orderListData as $data) {
                $oRes[] = Db::name($this->table)->insert(array_merge($data, $other_data));
            }
            //全部成功才行
            $res1 = true;
            foreach ($oRes as $v) {
                if (!$v) {
                    $res1 = false;
                    break;
                }
            }
        } else {
            $res1 = Db::name($this->table)->insert(array_merge($c_data, $other_data));
        }
        if ($inject) {
            Db::name('xy_inject')
                ->where('id', $inject['id'])
                ->update([
                    'in_time' => time(),
                    'in_amount' => $goods['num'],
                    'in_oid' => $ids[0]
                ]);
        }
        if ($res && $res1) {
            Db::commit();
            return ['code' => 0, 'info' => lang('qd_ok'), 'oid' => $ids, 'orderNum' => $orderNum];
        } else {
            Db::rollback();
            return ['code' => 1, 'info' => lang('qd_sb')];
        }
    }

    /**
     * 获取用户可交易情况
     * @param $uid int 用户编号
     * @param $level_id int 级别编号
     * @return array [总订单量，佣金比例，最低金额，提现订单限制]
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    public function get_user_order_setting($uid, $level_id)
    {
        $setting = Db::name('xy_users_setting')
            ->where('uid', $uid)
            ->where('date', null)
            ->find();
        if ($setting) {
            return [
                'order_num' => $setting['order_num'],
                'bili' => $setting['bili'],
                'min_money' => $setting['min_money'],
                'min_deposit_order' => $setting['min_deposit_order'],
            ];
        }
        $level = Db::name('xy_level')->where('level', $level_id)->find();
        return [
            'order_num' => $level['order_num'],
            'bili' => $level['bili'],
            'min_money' => $level['num_min'],
            'min_deposit_order' => $level['tixian_nim_order'],
        ];
    }

    /**
     * 获取用户当前做单情况
     * @param $uid int 用户编号
     * @param $group_id int 叠加组
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function get_user_group_rule($uid, $group_id)
    {
        if (!$group_id) {
            //普通组
            $uinfo = Db::name('xy_users')->find($uid);
            $uinfo['level'] = $uinfo['level'] > 0 ? $uinfo['level'] : 0;
            $orderNum = Db::name('xy_convey')
                ->where([
                    ['uid', '=', $uid],
                    ['level_id', '=', $uinfo['level']],
                    // ['addtime', 'between', strtotime(date('Y-m-d')) . ',' . time()],
                ])
                ->where('status', 'in', [0, 1, 3, 5])
                ->count('id');
            $all_order_num = Db::name('xy_level')->where('level', $uinfo['level'])->value('order_num');
            return [$orderNum, 0, $all_order_num];
        }
        $groupInfo = Db::name('xy_group')->where('id', $group_id)->find();
        //总单数
        $all_order_num = intval($groupInfo['order_num']);
        //判断当前第几单
        $orderNum = 1;
        $lastOrder = Db::name('xy_convey')
            ->where('uid', $uid)
            ->where('group_is_active', 1)
            ->where('group_id', $group_id)
            ->order('oid desc')
            ->find();
        if (!empty($lastOrder)) {
            $orderNum = $lastOrder['group_rule_num'] + 1;
        }
        $groupRule = Db::name('xy_group_rule')
            ->where('group_id', $group_id)
            ->where('order_num', $orderNum)
            ->find();
        if (empty($groupRule)) {
            //如果没有 就从第一单开始
            $orderNum = 1;
            $groupRule = Db::name('xy_group_rule')
                ->where('group_id', $group_id)
                ->where('order_num', $orderNum)
                ->find();
        } else {
            //叠加 用户已经做了的单数
            if ($orderNum > 1) {
                $add_num = Db::name('xy_group_rule')
                    ->where('group_id', $group_id)
                    ->where('order_num', '<', $orderNum)
                    ->sum('add_orders');
                $all_order_num += intval($add_num);
            }
        }
        return [$orderNum, $groupRule, $all_order_num];
    }

    /**
     * 获取打针比例
     * @param $uid int 用户编号
     * @param $order_num int 当前第几单
     * @return array|null|\PDOStatement|string|Model
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    private function get_inject($uid, $order_num)
    {
        if ($order_num > 1) $order_num = $order_num + 1;
        //优先执行 指定单
        $in = Db::name('xy_inject')
            ->where('uid', $uid)
            ->where('order_num', $order_num)
//            ->where('date', date('Y-m-d'))
            ->where('date', null)
            ->where('in_time', 0)
            ->find();
        if (!$in) {
            //下一单
            $in = Db::name('xy_inject')
                ->where('uid', $uid)
                ->where('order_num', 0)
//                ->where('date', date('Y-m-d'))
                ->where('date', null)
                ->where('in_time', 0)
                ->find();
        }
        return $in;
    }

    /**
     * 计算佣金
     * */
    private function get_commission($price, $groupRule)
    {
        if ($groupRule['commission_type'] == 1) {
            //固定佣金
            $commission = $groupRule['commission_value'];
        } else {
            //百分比佣金
            $commission = $price * ($groupRule['commission_value'] / 100);
        }
        return $commission;
    }

    /**
     * 随机生成订单商品
     */
    private function rand_order($min, $max, $cid)
    {
        $num = mt_rand($min, $max);//随机交易额
        $goods = Db::name('xy_goods_list')
            ->orderRaw('rand()')
            ->where('goods_price', 'between', [0, $num])
            ->where('cid', '=', $cid)
            ->find();
        if (!$goods) {
            echo json_encode(['code' => 1, 'info' => lang('qdsbkcbz') . '--' . $num]);
            die;
        }
        return ['count' => 1, 'id' => $goods['id'], 'num' => $num, 'cid' => $goods['cid']];
    }

    /**
     * 处理订单
     *
     * @param string $oid 订单号
     * @param int $status 操作      1会员确认付款 2会员取消订单 3后台强制付款 4后台强制取消
     * @param int $uid 用户ID    传参则进行用户判断
     * @param int $uid 收货地址
     * @return array
     */
    public function do_order($oid, $status, $uid = '', $add_id = '')
    {
        $info = Db::name('xy_convey')->find($oid);
        if (!$info) return ['code' => 1, 'info' => lang('order_sn_none')];
        if ($uid && $info['uid'] != $uid) return ['code' => 1, 'info' => lang('cscw')];
        if (!in_array($info['status'], [0, 5])) return ['code' => 1, 'info' => lang('ddycl')];
        $tmp = [
            //'endtime' => time() + config('deal_feedze'),
            'status' => in_array($status, [2, 4]) ? 2 : 5,
            'is_pay' => in_array($status, [2, 4]) ? 0 : 1,
            'pay_time' => time()
        ];
        $add_id ? $tmp['add_id'] = $add_id : '';
        Db::startTrans();
        $res = Db::name('xy_convey')->where('id', $oid)->update($tmp);
        if (in_array($status, [1, 3])) {
            //TODO 判断余额是否足够
            $user = Db::name('xy_users')->where('id', $info['uid'])->find();
            if ($user['balance'] < $info['num']) {
                Db::rollback();
                return [
                    'code' => 1,
                    'info' => sprintf(lang('zhyebz'), ($info['num'] - $user['balance']) . ""),
                    'url' => url('index/ctrl/recharge')
                ];
            }
            //是否为多单模式
            $isGroup = false;
            $isMultipleOrder = false;
            if ($info['group_id'] > 0) {
                $isGroup = true;
                $o_g_ids = Db::name('xy_convey')
                    ->where('uid', $info['uid'])
                    ->where('group_is_active', 1)
                    ->where('group_id', $info['group_id'])
                    ->where('group_rule_num', $info['group_rule_num'])
                    ->column('id');
                if (count($o_g_ids) > 1) {
                    $isMultipleOrder = true;
                }
            }
            //付款
            if (!$info['is_pay']) {
                try {
                    $res1 = Db::name('xy_users')
                        ->where('id', $info['uid'])
                        ->dec('balance', $info['num'])
                        ->inc('freeze_balance', $info['num'] + $info['commission']) //冻结商品金额 + 佣金
                        ->update([
                            'deal_status' => 1,
                            'status' => 1
                        ]);
                    //商品支出
                    $res2 = Db::name('xy_balance_log')->insert([
                        'uid' => $info['uid'],
                        'sid' => $info['uid'],
                        'oid' => $oid,
                        'num' => $info['num'],
                        'type' => 2,
                        'status' => 2,
                        'addtime' => time()
                    ]);
                    //交易佣金
                    $res8 = Db::name('xy_balance_log')->insert([
                        'uid' => $info['uid'],
                        'sid' => $info['uid'],
                        'oid' => $oid,
                        'num' => $info['commission'],
                        'type' => 3,
                        'status' => 1,
                        'addtime' => time()
                    ]);
                    //商品收入
                    $res2 = Db::name('xy_balance_log')->insert([
                        'uid' => $info['uid'],
                        'sid' => $info['uid'],
                        'oid' => $oid,
                        'num' => $info['num'],
                        'type' => 2,
                        'status' => 1,
                        'addtime' => time()
                    ]);
                    if ($res && $res1 && $res2) {

                    } else {
                        Db::rollback();
                        return ['code' => 1, 'info' => lang('czsb')];
                    }
                } catch (Exception $th) {
                    Db::rollback();
                    return ['code' => 1, 'info' => lang('czsb')];
                }
            }
            //系统通知
            $isAllOk = true;
            if ($status == 3) {
                Db::name('xy_message')->insert(['uid' => $info['uid'], 'type' => 2, 'title' => lang('sys_msg'), 'content' => $oid . ',' . lang('dd_pay_system'), 'addtime' => time()]);
            }
            //提交事物
            Db::commit();
            if (!$isMultipleOrder) {
                $c_status = Db::name('xy_convey')->where('id', $oid)->value('c_status');
                //判断是否已返还佣金
                if ($c_status === 0) $this->deal_reward($info['uid'], $oid, $info['num'], $info['commission']);
            } else {
                //多单模式
                //判断全部做完
                $oList = Db::name('xy_convey')
                    ->field('id,uid,num,commission,status,c_status')
                    ->where('id', 'in', $o_g_ids)
                    ->select();
                foreach ($oList as $val) {
                    if ($val['status'] != 5) {
                        $isAllOk = false;
                        break;
                    }
                }
                if ($isAllOk) {
                    foreach ($oList as $val) {
                        if ($val['c_status'] == 0) {
                            $this->deal_reward($val['uid'], $val['id'], $val['num'], $val['commission']);
                        }
                    }
                }
            }
            //杀猪组 做完一轮了更新状态
            if ($isGroup && $isAllOk) {
                list($orderNum, $groupRule) = $this->get_user_group_rule($user['id'], $user['group_id']);
                if ($orderNum == 1) {
                    Db::name('xy_convey')
                        ->where('uid', $user['id'])
                        ->where('group_id', $user['group_id'])
                        ->update([
                            'group_is_active' => 0
                        ]);
                }
            }
            return ['code' => 0, 'info' => lang('czcg')];
        } //
        elseif (in_array($status, [2, 4])) {
            $res1 = Db::name('xy_users')->where('id', $info['uid'])
                ->update([
                    'deal_status' => 1,
                ]);
            if ($status == 4) Db::name('xy_message')->insert(['uid' => $info['uid'], 'type' => 2, 'title' => lang('sys_msg'), 'content' => $oid . ',' . lang('dd_system_clean'), 'addtime' => time()]);
            //系统通知
            if ($res && $res1 !== false) {
                Db::commit();
                return ['code' => 0, 'info' => lang('czcg')];
            } else {
                Db::rollback();
                return ['code' => 1, 'info' => lang('czsb'), 'data' => $res1];
            }
        }
    }

    //计算代数佣金比例
    private function get_tj_bili($tj_bili, $lv)
    {
        $tj_bili = explode("/", $tj_bili);
        $tj_bili[0] = isset($tj_bili[0]) ? floatval($tj_bili[0]) : 0;
        $tj_bili[1] = isset($tj_bili[1]) ? floatval($tj_bili[1]) : 0;
        $tj_bili[2] = isset($tj_bili[2]) ? floatval($tj_bili[2]) : 0;
        return isset($tj_bili[$lv - 1]) ? $tj_bili[$lv - 1] : 0;
    }

    /**
     * 交易返佣
     *
     * @return void
     */
    public function deal_reward($uid, $oid, $num, $cnum)
    {
        Db::name('xy_users')->where('id', $uid)->setInc('balance', $num + $cnum);
        Db::name('xy_users')->where('id', $uid)->setDec('freeze_balance', $num + $cnum);
        //Db::name('xy_balance_log')->where('oid', $oid)->update(['status' => 1]);
        //将订单状态改为已返回佣金
        Db::name('xy_convey')
            ->where('id', $oid)
            ->update(['c_status' => 1, 'status' => 1]);
        Db::name('xy_reward_log')
            ->insert(['oid' => $oid, 'uid' => $uid, 'num' => $num, 'addtime' => time(), 'type' => 2, 'status' => 2]);
        //记录充值返佣订单
        /************* 发放交易奖励 *********/
        //之后下单人级别>0 才发放层级奖励
        $level = Db::name('xy_users')->where('id', $uid)->value('level');
        if ($level > 0) {
            $userList = model('admin/Users')->parent_user($uid, 3);
        } else $userList = [];

        //发放佣金
        if ($userList) {
            foreach ($userList as $v) {
                if ($v['level'] == 0) continue;
                $tj_bili = Db::name('xy_level')->where('level', $v['level'])->value('tj_bili');
                $price = $this->get_tj_bili($tj_bili, intval($v['lv'])) * $cnum;
                if ($v['status'] === 1) {
                    Db::name('xy_reward_log')
                        ->insert([
                            'uid' => $v['id'],
                            'sid' => $v['pid'],
                            'oid' => $oid,
                            'num' => $price,
                            'lv' => $v['lv'],
                            'type' => 2,
                            'status' => 2,
                            'addtime' => time(),
                        ]);
                    $res = SdUser::where('id', $v['id'])
                        ->where('status', 1)
                        ->setInc('balance', $price);
                    //下级佣金
                    $res2 = SdBalanceLog::insert([
                        'uid' => $v['id'],
                        'sid' => $uid,
                        'oid' => $oid,
                        'num' => $price,
                        'type' => 6,
                        'status' => 1,
                        'addtime' => time()
                    ]);
                }
            }
        }
        /************* 发放交易奖励 *********/
    }
}