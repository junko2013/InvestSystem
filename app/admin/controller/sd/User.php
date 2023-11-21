<?php

// +----------------------------------------------------------------------
// | ThinkAdmin
// +----------------------------------------------------------------------
// | www.xydai.cn 新源代网
// +----------------------------------------------------------------------

// +----------------------------------------------------------------------

// +----------------------------------------------------------------------
// |

// +----------------------------------------------------------------------

namespace app\admin\controller\sd;

use app\model\sd\SdBalanceLog;
use app\model\sd\SdBankInfo;
use app\model\sd\SdCs;
use app\model\sd\SdGroup;
use app\model\sd\SdLevel;
use app\model\sd\SdOrder;
use app\model\sd\SdRecharge;
use app\model\sd\SdUser;
use app\model\sd\SdUserRelation;
use app\model\sd\SdWithdraw;
use think\admin\extend\DataExtend;
use think\admin\model\SystemUser;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\db\exception\PDOException;
use think\Exception;
use think\facade\Db;

/**
 * 会员管理
 * Class User
 */
class User extends BaseSdCtrl
{
    /**
     * 会员列表
     * @auth true
     * @menu true
     * @throws Exception
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     * @throws PDOException
     */
    public function index()
    {
        $this->title = '会员列表';
        $query = $this->_query($this->table)->alias('u');
        $where = [];
        $isOnline = input('isOnline/s', '0');
        if ($isOnline==1){
            $where[] = ['u.last_access_time', '>', time()-5*60];
        }
        if (input('tel/s', '')) $where[] = ['u.tel', 'like', '%' . input('tel/s', '') . '%'];
        if (input('invite_code/s', '')) $where[] = ['u.invite_code', '=', input('invite_code/s')];
        if (input('username/s', '')) $where[] = ['u.username', 'like', '%' . input('username/s', '') . '%'];
        if (input('is_jia/s', '')) {
            $isjia = input('is_jia/s', '');
            $isjia == -1 ? $isjia = 0 : '';
            $where[] = ['u.is_jia', '=', $isjia];
        }
        if (input('addtime/s', '')) {
            $arr = explode(' - ', input('addtime/s', ''));
            $where[] = ['u.addtime', 'between', [strtotime($arr[0]), strtotime($arr[1] . ' 23:59:59')]];
        }
        $this->order = input('order/s', '');
        switch ($this->order) {
            case "recharge":
                $order = 'u.all_recharge_num desc';
                break;
            case "recharge_count":
                $order = 'u.all_recharge_count desc';
                break;
            case "deposit":
                $order = 'u.all_deposit_num desc';
                break;
            case "deposit_count":
                $order = 'u.all_deposit_count desc';
                break;
            default:
                $order = 'u.id desc';
                break;
        }
        $this->level = input('level', -1);
        $this->group_id = input('group_id', -1);
        if ($this->level != -1) $where[] = ['u.level', '=', $this->level];
        if ($this->group_id != -1) $where[] = ['u.group_id', '=', $this->group_id];
        $this->level_list = SdLevel::field('level,name')->select();
        $this->groupList = SdGroup::where('agent_id', 'in', [$this->agent_id, 0])
            ->field('id,title')
            ->column('title', 'id');
        $this->groupAllList = SdGroup::field('id,title')
            ->column('title', 'id');

        $this->agent_service_id = input('agent_service_id/d', 0);
        if ($this->agent_id) {
            $this->agent_uid = SdUser::get_agent_id();
            if ($this->agent_uid) {
                $where[] = ['u.agent_service_id', '=', $this->agent_id];
            } else {
                $where[] = ['u.agent_id', '=', $this->agent_id];
            }
            $this->agent_list = [];
            $this->agent_service_list = SystemUser::where('parent_id', $this->agent_id)
                ->where('authorize', "2")
                ->field('id,username')
                ->where('is_deleted', 0)
                ->column('username', 'id');
            if ($this->agent_service_list &&
                $this->agent_service_id &&
                !array_key_exists($this->agent_service_id, $this->agent_service_list)) {
                $this->agent_service_id = 0;
            }
        } else {
            $this->agent_list = SystemUser::where('user_id', 0)
                ->where('authorize', "2")
                ->field('id,username')
                ->where('is_deleted', 0)
                ->column('username', 'id');
            $this->agent_service_list = SystemUser::where('user_id', '>', 0)
                ->where('authorize', "2")
                ->field('id,username')
                ->where('is_deleted', 0)
                ->column('username', 'id');
            $this->agent_id = input('agent_id/d', 0);

            if ($this->agent_id) {
                $query->where('u.agent_id', $this->agent_id);
            }
        }
        if ($this->agent_service_id) {
            $query->where('u.agent_service_id', $this->agent_service_id);
        }
        $this->time=time();
        $query->field('u.id,u.level,u.agent_id,u.tel,u.username,u.remark,u.group_id,u.last_access_time,
        u.lixibao_balance,u.id_status,u.ip,u.is_jia,u.addtime,u.invite_code,
        u.all_recharge_num,u.all_deposit_num,u.all_recharge_count,u.all_deposit_count,
        u.freeze_balance,u.status,u.balance,u1.username as parent_name')
            ->leftJoin('xy_users u1', 'u.parent_id=u1.id')
            ->where($where)
            ->order($order)
            ->page();
    }

	/**
	 * 表单数据处理
	 * @param array $data
	 * @throws DataNotFoundException
	 * @throws ModelNotFoundException
	 * @throws DbException
	 * @throws DbException
	 */
    public function _index_page_filter(&$data)
    {
        $stuck = input('stuck/s', 'off');
        $this->stuck = $stuck=='on';
        $newData = [];
        $admins = SystemUser::field('id,username')->column('username', 'id');
        foreach ($data as &$vo) {
            $id = $vo['id'];
            $vo['agent'] = $vo['agent_id'] ? $admins[$vo['agent_id']] : '';
            $vo['service'] = '';
            $s = SdUser::get_user_service_id($id);
            if ($s) $vo['service'] = $s['username'];
            $vo['com'] = SdBalanceLog::where('uid', $id)
                ->where('type', 3)->where('status', 1)->sum('num');
            $vo['tj_com'] = SdBalanceLog::where('uid', $id)
                ->where('type', 6)->where('status', 1)->sum('num');

//            if ($vo['group_id'] > 0) {
//                // $vo['totalOrder'] = Db::name('xy_convey')
//                //     ->where('uid', $id)
//                //     ->where('group_id', $this->info['group_id'])
//                //     ->order('addtime desc')
//                //     ->limit(1)
//                //     ->value('group_rule_num');
//            } else {
                //总单数
                $vo['totalOrder'] = SdOrder::where('uid', $id)
                    ->where('level_id', $vo['level'])
                    ->where('status', 'in', [0, 1, 3, 5])
                    ->count('id');
                $orderSetting = SdOrder::get_user_order_setting($id, $vo['level']);
                $vo['order_num'] = $orderSetting['order_num']; //级别 订单数量
                //完成
                $vo['doneOrder'] = SdOrder::where('uid', $id)
                    ->where('level_id', $vo['level'])
                    ->where('status', 'in', [1, 3, 5])
                    ->count('id');
//            }
            //未完成
            $vo['stuckOrder'] = 0;
            $order = SdOrder::field('id,num,user_balance')
                ->where('uid', $id)->where('status', 'in', [0, 5])->limit(1)->order('addtime',"desc")->find();
            if($order&&$order['num']-$order['user_balance']>0){
                $vo['stuckOrder'] = $order['num']-$order['user_balance'];
            }
            if ($this->stuck&&$vo['stuckOrder']>0){
                $newData[] = $vo;
            }
        }
        $data = DataExtend::arr2table($this->stuck?$newData:$data);
    }


	/**
	 * 会员等级列表
	 * @auth true
	 * @menu true
	 * @throws DbException
	 */
    public function level()
    {
        $this->title = '用户等级';
        $this->_query('xy_level')->page();
    }


	/**
	 * 账变
	 * @auth true
	 * @throws DbException
	 */
    public function caiwu()
    {
        $uid = input('get.id/d', 1);
        $this->uid = $uid;
        $this->uinfo = SdUser::find($uid);
        if (isset($_REQUEST['iasjax'])) {
            $page = input('get.page/d', 1);
            $num = input('get.num/d', 10);
            $level = input('get.level/d', 1);
            $limit = ((($page - 1) * $num) . ',' . $num);
            $where = [];
            if ($level == 1) {
                $where[] = ['uid', '=', $uid];
            }
            $type = input('type', 0);
            if ($type != 0) {
                $where[] = ['type', '=', $type != -1 ? $type : 0];
            }
            if (input('addtime/s', '')) {
                $arr = explode(' - ', input('addtime/s', ''));
                $where[] = ['addtime', 'between', [strtotime($arr[0]), strtotime($arr[1])]];
            }
            $count = SdBalanceLog::where($where)->count('id');
            $data = SdBalanceLog::where($where)
                ->order('id desc')
                ->limit($limit)
                ->select();

	        foreach ($data as &$datum) {
	            $datum['tel'] = $this->uinfo['tel'];
	            $datum['addtime'] = date('Y/m/d H:i', $datum['addtime']);
		        switch ($datum['type']) {
	                case 0:
	                    $text = '<span class="layui-btn layui-btn-sm layui-btn-danger">系统</span>';
	                    break;
	                case 1:
	                    $text = '<span class="layui-btn layui-btn-sm layui-btn-warm">充值</span>';
	                    break;
	                case 2:
	                    $text = '<span class="layui-btn layui-btn-sm layui-btn-danger">交易</span>';
	                    break;
	                case 3:
	                    $text = '<span class="layui-btn layui-btn-sm layui-btn-normal">返佣</span>';
	                    break;
	                case 4:
	                    $text = '<span class="layui-btn layui-btn-sm ">强制交易</span>';
	                    break;
	                case 5:
	                    $text = '<span class="layui-btn layui-btn-sm layui-btn-danger">推广返佣</span>';
	                    break;
	                case 6:
	                    $text = '<span class="layui-btn layui-btn-sm layui-btn-normal">下级交易返佣</span>';
	                    break;
	                case 7:
	                    $text = '<span class="layui-btn layui-btn-sm layui-btn-danger">提现</span>';
	                    break;
	                case 8:
	                    $text = '<span class="layui-btn layui-btn-sm layui-btn-danger">提现驳回</span>';
	                    break;
	                case 21:
	                    $text = '<span class="layui-btn layui-btn-sm layui-btn-danger">利息宝入</span>';
	                    break;
	                case 22:
	                    $text = '<span class="layui-btn layui-btn-sm layui-btn-danger">利息宝出</span>';
	                    break;
	                case 23:
	                    $text = '<span class="layui-btn layui-btn-sm layui-btn-danger">利息宝返佣</span>';
	                    break;
	                default:
	                    $text = '其他';
	            }
	            $datum['type'] = $text;
	            if ($datum['status'] == 1) $datum['status'] = '收入';
	            elseif ($datum['status'] == 2) $datum['status'] = '支出';
	            else $datum['status'] = '未知';

	        }

	        if (!$data) json(['code' => 1, 'info' => '暂无数据']);
            return json(['code' => 0, 'count' => $count, 'info' => '请求成功', 'data' => $data, 'other' => $limit]);
        }


        $this->rechagreCount = SdRecharge::where('uid', $uid)
            ->where('status', 2)
            ->sum('num');
        $this->depositCount = SdWithdraw::where('uid', $uid)
            ->where('status', 2)
            ->sum('num');
        $this->fetch();
    }

    /**
     * 添加会员
     * @auth true
     * @menu true
     */
    public function add_user(): void
    {
        if (request()->isPost()) {
            $tel = input('post.tel/s', '');
            $user_name = input('post.user_name/s', '');
            $pwd = input('post.pwd/s', '');
            $parent_id = input('post.parent_id/d', 0);
            $token = input('__token__', 1);
            $agent_id = $this->agent_id;
            $res = SdUser::add_user($tel, $user_name, $pwd, $parent_id, $token, '', $agent_id);
            if ($res['code'] !== 0) {
				$this->error($res['info']);
	            return;
            }
            //如果是二级
            if ($this->agent_uid) {
                $sys = SystemUser::where('id', $this->agent_id)->find();
                SdUser::where('id', $res['id'])
                    ->update([
                        'agent_id' => $sys['parent_id'],
                        'agent_service_id' => $sys['id']
                    ]);
            }
            sysoplog('添加新用户', json_encode($_POST, JSON_UNESCAPED_UNICODE));
            $this->success($res['info']);
        }
        $this->agent_list = SystemUser::where('user_id', 0)
            ->where('authorize', "2")
            ->field('id,username')
            ->where('is_deleted', 0)
            ->select();
        $this->fetch();
    }

    /**
     * 编辑会员
     * @auth true
     * @menu true
     */
    public function edit_users(): void
    {
        $id = input('get.id', 0);
        if (request()->isPost()) {
            $c_agent_id = $this->agent_id;

            $id = input('post.id/d', 0);
            $tel = input('post.tel/s', '');
            $user_name = input('post.user_name/s', '');
            $remark = input('post.remark/s', '');
            $pwd = input('post.pwd/s', '');
            $pwd2 = input('post.pwd2/s', '');
            $parent_id = input('post.parent_id/d', 0);
            $level = input('post.level/d', 0);
            $group_id = input('post.group_id/d', 0);
            $agent_id = input('post.agent_id/d', 0);
            $agent_service_id = input('post.agent_service_id/d', 0);
            $freeze_balance = input('post.freeze_balance/f', 0);
            $balance = input('post.balance/f', 0);
            $deal_status = input('post.deal_status/d', 1);
            $token = input('__token__');
            $res = SdUser::edit_user($id, $tel, $user_name,$remark, $pwd, $parent_id, $balance, $freeze_balance, $token, $pwd2,$c_agent_id);
            $res2 = SdUser::where('id', $id)->update([
                'deal_status' => $deal_status,
                'level' => $level,
                'group_id' => $group_id,
                'agent_id' => $agent_id,
                'agent_service_id' => $agent_service_id,
            ]);
            sysoplog('编辑用户', json_encode($_POST, JSON_UNESCAPED_UNICODE));
            $this->success(lang('czcg',[],'en-ww'));
        }
        $this->agent_list = SystemUser::where('user_id', 0)
            ->where('authorize', "2")
            ->field('id,username')
            ->where('is_deleted', 0);
        $this->agent_list = $this->agent_list->select();
        if (!$id) $this->error('参数错误');
        $this->info = SdUser::find($id);
        $this->level = SdLevel::select();
        $this->groupList = SdGroup::where('agent_id', 'in', [$this->agent_id, 0])->select();
        $t = strtotime(date('Y-m-d'));
        if ($this->info['group_id'] > 0) {
            $this->converNumber = SdOrder::where('uid', $id)
                ->where('group_id', $this->info['group_id'])
                ->order('addtime desc')
                ->limit(1)
                ->value('group_rule_num');
        } else {
            $this->converNumber = SdOrder::where('uid', $id)
                ->where('level_id', $this->info['level'])
                ->where('addtime', 'between', [$t, $t + 86400])
                ->count('id');
        }
        $this->converNumber = $this->converNumber ? $this->converNumber : 0;
        $this->fetch();
    }

    /**
     * 更改用户等级
     * @auth true
     */
    public function edit_level()
    {
        $id = input('get.id', 0);
        if (request()->isPost()) {
            $id = input('post.id/d', 0);
            $level = input('post.level/d', 0);
            $group_id = input('post.group_id/d', 0);
            $token = input('__token__');
            $res2 = SdUser::where('id', $id)->update([
                'level' => $level,
                'group_id' => $group_id,
            ]);
            sysoplog('更改用户等级', json_encode($_POST, JSON_UNESCAPED_UNICODE));
			$this->success(lang('czcg',[],'en-ww'));
        }
        $this->agent_list = SystemUser::where('user_id', 0)
            ->where('authorize', "2")
            ->field('id,username')
            ->where('is_deleted', 0)
            ->select();
        if (!$id) $this->error('参数错误');
        $this->info = SdUser::find($id);
        $this->level = SdLevel::select();
        $this->groupList = SdGroup::where('agent_id', 'in', [$this->agent_id, 0])->select();
        $t = strtotime(date('Y-m-d'));
        if ($this->info['group_id'] > 0) {
            $this->converNumber = SdOrder::where('uid', $id)
                ->where('group_id', $this->info['group_id'])
                ->order('addtime desc')
                ->limit(1)
                ->value('group_rule_num');
        } else {
            $this->converNumber = SdOrder::where('uid', $id)
                ->where('level_id', $this->info['level'])
                ->where('addtime', 'between', [$t, $t + 86400])
                ->count('id');
        }
        $this->converNumber = $this->converNumber ?: 0;
		$this->fetch();
    }

    /**
     * 编辑彩金
     * @auth true
     */
    public function edit_money()
    {
        $id = input('get.id', 0);
        if (!$id) $this->error('参数错误');
        if (request()->isPost()) {
            $id = input('post.id/d', 0);
            $money = input('post.money/f', 0);
            if ($money > 0) {
                SdUser::where('id', $id)
                    ->inc('balance', $money)
                    ->update();
            } else {
                $money = floatval(substr($money, 1));
                SdUser::where('id', $id)
                    ->dec('balance', $money)
                    ->update();
            }
            sysoplog('编辑用户彩金', json_encode($_POST, JSON_UNESCAPED_UNICODE));
            $this->success(lang('czcg',[],'en-ww'));
        }

        $this->info = SdUser::find($id);
        $this->fetch();
    }

    /**
     * 删除会员
     * @auth true
     */
    public function delete_user()
    {
        $this->_applyFormToken();
        $id = input('post.id/d', 0);
        $res = SdUser::where('id', $id)->delete();
        if ($res) {
            SdUserRelation::where('uid', $id)->delete();
            sysoplog('删除用户', 'ID ' . $id);
            $this->success('删除成功!');
        } else $this->error('删除失败!');
    }

    /**
     * 编辑会员_暗扣
     * @auth true
     */
    public function edit_users_ankou()
    {
        $id = input('get.id', 0);
        if (request()->isPost()) {
            $id = input('post.id/d', 0);
            $kouchu_balance_uid = input('post.kouchu_balance_uid/d', 0); //扣除人
            $kouchu_balance = input('post.kouchu_balance/f', 0); //扣除金额
            $show_td = (isset($_REQUEST['show_td']) && $_REQUEST['show_td'] == 'on') ? 1 : 0;//显示我的团队
            $show_cz = (isset($_REQUEST['show_cz']) && $_REQUEST['show_cz'] == 'on') ? 1 : 0;//显示充值
            $show_tx = (isset($_REQUEST['show_tx']) && $_REQUEST['show_tx'] == 'on') ? 1 : 0;//显示提现
            $show_num = (isset($_REQUEST['show_num']) && $_REQUEST['show_num'] == 'on') ? 1 : 0;//显示推荐人数
            $show_tel = (isset($_REQUEST['show_tel']) && $_REQUEST['show_tel'] == 'on') ? 1 : 0;//显示电话
            $show_tel2 = (isset($_REQUEST['show_tel2']) && $_REQUEST['show_tel2'] == 'on') ? 1 : 0;//显示电话隐藏
            $token = input('__token__');
            $data = [
                '__token__' => $token,
                'show_td' => $show_td,
                'show_cz' => $show_cz,
                'show_tx' => $show_tx,
                'show_num' => $show_num,
                'show_tel' => $show_tel,
                'show_tel2' => $show_tel2,
                'kouchu_balance_uid' => $kouchu_balance_uid,
                'kouchu_balance' => $kouchu_balance,
            ];
            //var_dump($data,$_REQUEST);die;
            unset($data['__token__']);
            $res = SdUser::where('id', $id)->update($data);
            if (!$res) {
                $this->error('编辑失败!');
            }
            sysoplog('编辑会员暗扣', json_encode($data, JSON_UNESCAPED_UNICODE));
            $this->success('编辑成功!');
        }

        if (!$id) $this->error('参数错误');
        $this->info = SdUser::find($id);

        //
        $uid = $id;
        $data = SdUser::where('parent_id', $uid)
            ->field('id,username,headpic,addtime,childs,tel')
            ->order('addtime desc')
            ->select();

        foreach ($data as &$datum) {
            //充值
            $datum['chongzhi'] = SdRecharge::where('uid', $datum['id'])->where('status', 2)->sum('num');
            //提现
            $datum['tixian'] = SdWithdraw::where('uid', $datum['id'])->where('status', 1)->sum('num');
        }

        //var_dump($data,$uid);die;

        //$this->cate = db('xy_goods_cate')->order('addtime asc')->select();
        $this->assign('cate', $data);
        $this->fetch();
    }

    /**
     * 编辑会员登录状态
     * @auth true
     */
    public function edit_user_status()
    {
        $id = input('id/d', 0);
        $status = input('status/d', 0);
        if (!$id || !$status) $this->error('参数错误');
        $res = SdUser::edit_user_status($id, $status);
        if ($res['code'] !== 0) {
            $this->error($res['info']);
        }
        sysoplog('编辑会员登录状态', "ID {$id} status {$status}");
        $this->success($res['info']);
    }

    /**
     * 编辑会员登录状态
     * @auth true
     */
    public function edit_user_status2()
    {
        $id = input('id/d', 0);
        $status = input('status/d', 0);
        if (!$id || !$status) $this->error('参数错误');
        $status == -1 ? $status = 0 : '';
        $res = SdUser::where('id', $id)->update(['is_jia' => $status]);
        if (!$res) {
            sysoplog('编辑会员真假人', "ID {$id} status {$status}");
			$this->error('更新失败!');
        }
		$this->success('更新成功');
    }

    /**
     * 编辑会员二维码
     * @auth true
     */
    public function edit_user_ewm(): void
    {
        $id = input('id/d', 0);
        $invite_code = input('status/s', '');
        if (!$id || !$invite_code) $this->error('参数错误');

        $n = ($id % 20);

        $dir = './upload/qrcode/user/' . $n . '/' . $id . '.png';
        if (file_exists($dir)) {
            unlink($dir);
        }

        $res = SdUser::create_qrcode($invite_code, $id);
        if (0 && $res['code'] !== 0) {
			$this->error('失败');
        }
		$this->success('成功');
    }


	/**
	 * 查看团队
	 * @auth true
	 * @throws DbException
	 */
    public function team()
    {
        $uid = input('get.id/d', 1);
        if (isset($_REQUEST['iasjax'])) {
            $page = input('get.page/d', 1);
            $num = input('get.num/d', 10);
            $level = input('get.level/d', 1);
            $limit = ((($page - 1) * $num) . ',' . $num);
            $where = [];
            if ($level == -1) {
                $uids = SdUser::child_user($uid, 5);
                $uids ? $where[] = ['u.id', 'in', $uids] : $where[] = ['u.id', 'in', [-1]];
            } else if ($level == 1) {
                $uids1 = SdUser::child_user($uid, 1, 0);
                $uids1 ? $where[] = ['u.id', 'in', $uids1] : $where[] = ['u.id', 'in', [-1]];
            } else if ($level == 2) {
                $uids2 = SdUser::child_user($uid, 2, 0);
                $uids2 ? $where[] = ['u.id', 'in', $uids2] : $where[] = ['u.id', 'in', [-1]];
            } else if ($level == 3) {
                $uids3 = SdUser::child_user($uid, 3, 0);
                $uids3 ? $where[] = ['u.id', 'in', $uids3] : $where[] = ['u.id', 'in', [-1]];
            } else if ($level == 4) {
                $uids4 = SdUser::child_user($uid, 4, 0);
                $uids4 ? $where[] = ['u.id', 'in', $uids4] : $where[] = ['u.id', 'in', [-1]];
            } else if ($level == 5) {
                $uids5 = SdUser::child_user($uid, 5, 0);
                $uids5 ? $where[] = ['u.id', 'in', $uids5] : $where[] = ['u.id', 'in', [-1]];
            }

            if (input('tel/s', '')) $where[] = ['u.tel', 'like', '%' . input('tel/s', '') . '%'];
            if (input('username/s', '')) $where[] = ['u.username', 'like', '%' . input('username/s', '') . '%'];
            if (input('addtime/s', '')) {
                $arr = explode(' - ', input('addtime/s', ''));
                $where[] = ['u.addtime', 'between', [strtotime($arr[0]), strtotime($arr[1])]];
            }

            $count = $data = SdUser::alias('u')->where($where)->count('id');
            $query = Db::name('xy_users')->alias('u');
            $data = $query->field('u.id,u.tel,u.username,u.id_status,u.childs,u.ip,u.is_jia,u.addtime,u.invite_code,u.freeze_balance,u.status,u.balance,u1.username as parent_name')
                ->leftJoin('sd_user u1', 'u.parent_id=u1.id')
                ->where($where)
                ->order('u.id desc')
                ->limit($limit)
                ->select();

            if ($data) {
                //
                $uid1s = SdUser::child_user($uid, 1, 0);
                $uid2s = SdUser::child_user($uid, 2, 0);
                $uid3s = SdUser::child_user($uid, 3, 0);
                $uid4s = SdUser::child_user($uid, 4, 0);
                $uid5s = SdUser::child_user($uid, 5, 0);

                foreach ($data as &$datum) {
                    //佣金
                    $datum['yj'] = SdBalanceLog::where('status', 1)
                        ->where('type', 3)
                        ->where('uid', $datum['id'])
                        ->sum('num');
                    $datum['tj_yj'] = SdBalanceLog::where('status', 1)
                        ->where('type', 6)
                        ->where('uid', $datum['id'])
                        ->sum('num');
                    $datum['cz'] = SdRecharge::where('status', 2)->where('uid', $datum['id'])->sum('num');
                    $datum['tx'] = SdWithdraw::where('status', 2)->where('uid', $datum['id'])->sum('num');
                    $datum['addtime'] = date('Y/m/d H:i', $datum['addtime']);
	                $datum['jb'] = '三级';
                    $color = '#92c7ef';


                    if (in_array($datum['id'], $uid1s)) {
                        $datum['jb'] = '一级';
                        $color = '#1E9FFF';
                    }
                    if (in_array($datum['id'], $uid2s)) {
                        $datum['jb'] = '二级';
                        $color = '#2b9aec';
                    }
                    if (in_array($datum['id'], $uid3s)) {
                        $datum['jb'] = '三级';
                        $color = '#1E9FFF';
                    }
                    if (in_array($datum['id'], $uid4s)) {
                        $datum['jb'] = '四级';
                        $color = '#76c0f7';
                    }
                    if (in_array($datum['id'], $uid5s)) {
                        $datum['jb'] = '五级';
                        $color = '#92c7ef';
                    }

                    $datum['jb'] = '<span class="layui-btn layui-btn-xs layui-btn-danger" style="background: ' . $color . '">' . $datum['jb'] . '</span>';
                }
            }
            if (!$data) json(['code' => 1, 'info' => '暂无数据']);

            $tj_com = 0;
            switch ($level) {
                case -1:
                    $tj_com = SdBalanceLog::where('uid', $uid)
                        ->where('type', 6)->where('status', 1)->sum('num');
                    break;
                case 1:
                    $tj_com = Db::name('xy_balance_log')
                        ->where('uid', $uid)
                        ->where('sid', 'in', $uids1 ?: [-1])
                        ->where('type', 6)
                        ->where('status', 1)
                        ->sum('num');
                    break;
                case 2:
                    $tj_com = Db::name('xy_balance_log')
                        ->where('uid', $uid)
                        ->where('sid', 'in', $uids2 ?: [-1])
                        ->where('type', 6)
                        ->where('status', 1)
                        ->sum('num');
                    break;
                case 3:
                    $tj_com = Db::name('xy_balance_log')
                        ->where('uid', $uid)
                        ->where('sid', 'in', $uids3 ?: [-1])
                        ->where('type', 6)
                        ->where('status', 1)
                        ->sum('num');
                    break;
            }
            return json([
                'code' => 0,
                'count' => $count,
                'info' => '请求成功',
                'data' => $data,
                'other' => $limit,
                'tj_com' => $tj_com
            ]);
        } else {
            //
            $this->uid = $uid;
            $this->uinfo = SdUser::find($uid);

        }
        $this->fetch();
    }

    /**
     * 封禁/解封会员
     * @auth true
     */
    public function open()
    {
        $uid = input('post.id/d', 0);
        $status = input('post.status/d', 0);
        $type = input('post.type/d', 0);
        $info = [];
        if ($uid) {
            if (!$type) {
                $status2 = $status ? 0 : 1;
                $res = SdUser::where('id', $uid)->update(['status' => $status2]);
                return json(['code' => 1, 'info' => '请求成功', 'data' => $info]);
            } else {
                //

                $wher = [];
                $wher2 = [];


                $ids1 = SdUser::where('parent_id', $uid)->field('id')->column('id');
                $ids1 ? $wher[] = ['parent_id', 'in', $ids1] : '';

                $ids2 = SdUser::where($wher)->field('id')->column('id');
                $ids2 ? $wher2[] = ['parent_id', 'in', $ids2] : '';

                $ids3 = SdUser::where($wher2)->field('id')->column('id');

                $idsAll = array_merge([$uid], $ids1, $ids2, $ids3);  //所有ids
                $idsAll = array_filter($idsAll);

                $wherAll[] = ['id', 'in', $idsAll];
                $users = SdUser::where($wherAll)->field('id')->select();

                //var_dump($users);die;
                $status2 = $status ? 0 : 1;
                foreach ($users as $item) {
                    $res = SdUser::where('id', $item['id'])->update(['status' => $status2]);
                }

                return json(['code' => 1, 'info' => '请求成功', 'data' => $info]);
            }


        }

        return json(['code' => 1, 'info' => '暂无数据']);
    }


    //查看图片
    public function picinfo()
    {
        $this->pic = input('get.pic/s', '');
        if (!$this->pic) return;
        $this->fetch();
    }

    /**
     * 客服管理
     * @auth true
     * @menu true
     */
    public function cs_list()
    {
        $this->title = '客服列表';
        $where = [];
        if (input('tel/s', '')) $where[] = ['tel', 'like', '%' . input('tel/s', '') . '%'];
        if (input('username/s', '')) $where[] = ['username', 'like', '%' . input('username/s', '') . '%'];
        if (input('addtime/s', '')) {
            $arr = explode(' - ', input('addtime/s', ''));
            $where[] = ['addtime', 'between', [strtotime($arr[0]), strtotime($arr[1])]];
        }
        $this->_query('xy_cs')
            ->where($where)
            ->page();
    }

    /**
     * 添加客服
     * @auth true
     * @menu true
     */
    public function add_cs()
    {
        if (request()->isPost()) {
            $this->_applyFormToken();
            $username = input('post.username/s', '');
            $tel = input('post.tel/s', '');
            $pwd = input('post.pwd/s', '');
            $qq = input('post.qq/d', 0);
            $wechat = input('post.wechat/s', '');
            $qr_code = input('post.qr_code/s', '');
            $url = input('post.url/s', '');
            $time = input('post.time');
            $arr = explode('-', $time);
            $btime = substr($arr[0], 0, 5);
            $etime = substr($arr[1], 1, 5);
            $data = [
                'username' => $username,
                'tel' => $tel,
                'pwd' => $pwd,    //需求不明确，暂时以明文存储密码数据
                'qq' => $qq,
                'wechat' => $wechat,
                'qr_code' => $qr_code,
                'url' => $url,
                'btime' => $btime,
                'etime' => $etime,
                'addtime' => time(),
            ];
            $res = SdCs::insert($data);
            if ($res) {
                sysoplog('添加客服', json_encode($data, JSON_UNESCAPED_UNICODE));
				$this->success('添加成功');
            }
			$this->error('添加失败，请刷新再试');
        }
		$this->fetch();
    }

    /**
     * 客服登录状态
     * @auth true
     */
    public function edit_cs_status()
    {
        $this->_applyFormToken();
        sysoplog('编辑客服状态', json_encode($_POST, JSON_UNESCAPED_UNICODE));
        $this->_save('xy_cs', ['status' => input('post.status/d', 1)]);
    }

    /**
     * 编辑客服信息
     * @auth true
     * @menu true
     */
    public function edit_cs()
    {
        if (request()->isPost()) {
            $this->_applyFormToken();
            $id = input('post.id/d', 0);
            $username = input('post.username/s', '');
            $tel = input('post.tel/s', '');
            $pwd = input('post.pwd/s', '');
            $qq = input('post.qq/d', 0);
            $wechat = input('post.wechat/s', '');
            $url = input('post.url/s', '');
            $qr_code = input('post.qr_code/s', '');
            $time = input('post.time');
            $arr = explode('-', $time);
            $btime = substr($arr[0], 0, 5);
            $etime = substr($arr[1], 1, 5);
            $data = [
                'username' => $username,
                'tel' => $tel,
                'qq' => $qq,
                'wechat' => $wechat,
                'url' => $url,
                'qr_code' => $qr_code,
                'btime' => $btime,
                'etime' => $etime,
            ];
            if ($pwd) $data['pwd'] = $pwd;
            $res = SdCs::where('id', $id)->update($data);
            if ($res !== false) {
                sysoplog('编辑客服信息', json_encode($data, JSON_UNESCAPED_UNICODE));
				$this->success('编辑成功');
            }
			$this->error('编辑失败，请刷新再试');
        }
        $id = input('id/d', 0);
        $this->list = SdCs::find($id);
		$this->fetch();
    }


    /**
     * 编辑银行卡信息
     * @auth true
     */
    public function edit_users_bk()
    {
        if (request()->isPost()) {
            $this->_applyFormToken();
            $id = input('post.id/d', 0);
            $res = SdBankInfo::where('id', $id)->update([
                'tel' => input('post.tel/s', ''),
                'site' => input('post.site/s', ''),
                'cardnum' => input('post.cardnum/s', ''),
                'username' => input('post.username/s', ''),
                'bankname' => input('post.bankname/s', ''),
                'bank_code' => input('post.bank_code/s', ''),
                'bank_branch' => input('post.bank_branch/s', ''),
                'document_id' => input('post.document_id/s', ''),
                'account_digit' => input('post.account_digit/s', ''),
                'wallet_tel' => input('post.wallet_tel/s', ''),
                'wallet_document_id' => input('post.wallet_document_id/s', ''),
            ]);
            if ($res !== false) {
                sysoplog('编辑银行卡信息', json_encode($_POST, JSON_UNESCAPED_UNICODE));
				$this->success('操作成功');
            } else {
				$this->error('操作失败');
            }
        }
        $this->bk_info = Db::name('xy_bankinfo')->where('uid', input('id/d', 0))->select();
        if (!$this->bk_info) $this->error('没有数据');
		$this->fetch();
    }


    /**
     * 编辑会员等级
     * @auth true
     * @menu true
     */
    public function edit_user_level()
    {
        if (request()->isPost()) {
            $this->_applyFormToken();
            $id = input('post.id/d', 0);
            $name = input('post.name/s', '');
            $level = input('post.level/d', 0);
            $num = input('post.num/s', '');
            $order_num = input('post.order_num/s', '');
            $bili = input('post.bili/s', '');
            $tj_bili = input('post.tj_bili/s', '');
            $tixian_ci = input('post.tixian_ci/s', '');
            $tixian_min = input('post.tixian_min/s', '');
            $tixian_max = input('post.tixian_max/s', '');
            $auto_vip_xu_num = input('post.auto_vip_xu_num/s', '');
            $num_min = input('post.num_min/s', '');
            $tixian_nim_order = input('post.tixian_nim_order/d', 0);
            $tixian_shouxu = input('post.tixian_shouxu/f', 0);
            $is_invite = input('post.is_invite/d', 1);
            $cate = Db::name('xy_goods_cate')->select();
            $cids = [];
            foreach ($cate as $item) {
                $k = 'cids' . $item['id'];
                if (isset($_REQUEST[$k]) && $_REQUEST[$k] == 'on') {
                    $cids[] = $item['id'];
                }
            }
            $cidsstr = implode(',', $cids);
            //var_dump($cidsstr);die;
            $res = SdLevel::where('id', $id)->update(
                [
                    'name' => $name,
                    'level' => $level,
                    'num' => $num,
                    'order_num' => $order_num,
                    'bili' => $bili,
                    'tj_bili' => $tj_bili,
                    'tixian_ci' => $tixian_ci,
                    'tixian_min' => $tixian_min,
                    'tixian_max' => $tixian_max,
                    'num_min' => $num_min,
                    'cids' => $cidsstr,
                    'tixian_nim_order' => $tixian_nim_order,
                    'auto_vip_xu_num' => $auto_vip_xu_num,
                    'tixian_shouxu' => $tixian_shouxu,
                    'is_invite' => $is_invite
                ]);
            if ($res !== false) {
                sysoplog('编辑会员等级', json_encode($_POST, JSON_UNESCAPED_UNICODE));
				$this->success('操作成功');
            } else {
				$this->error('操作失败');
            }
        }
        $this->bk_info = Db::name('xy_level')->where('id', input('id/d', 0))->select();
        $this->cate = Db::name('xy_goods_cate')->select();
        if (!$this->bk_info) $this->error('没有数据');
		$this->fetch();
    }




}