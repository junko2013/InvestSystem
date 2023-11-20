<?php

namespace app\admin\controller\sd;

use app\model\sd\SdUser;
use think\admin\Exception;
use think\admin\extend\DataExtend;
use think\admin\model\SystemUser;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\db\exception\PDOException;

/**
 * 代理管理
 * Class Agent
 * @package app\admin\controller
 */
class Agent extends BaseSdCtrl
{
	protected $table = 'system_user';
	/**
	 * 代理管理
	 * @auth true
	 * @menu true
	 * @return array|string
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 */
    public function index()
    {
        if ($this->agent_id > 0 && $this->agent_uid > 0) return '<h1>无权限</h1>';
        $this->title = '代理列表';
        $this->is_admin = $this->agent_id == 0;
        $query = $this->_query($this->table)->where('authorize', '2');
        if ($this->agent_id > 0) {
            $query->where('parent_id', $this->agent_id);
        } else {
            $parent_id = input('parent_id/d', 0);
            $query->where('parent_id', $parent_id);
            if ($parent_id > 0) {
                $aname = SystemUser::where('id', $parent_id)->value('username');
                $this->title =  $this->title ."({$aname})";
            }
        }
        $query->where('is_deleted', 0);
        return $query->like('username,phone')->order('id DESC')->page();
    }
    /**
     * 表单数据处理
     * @param array $data
     */
    public function _index_page_filter(&$data): void
    {
        foreach ($data as &$vo) {
            $vo['invite_code'] = '';
            if($vo['user_id']>0){
                $vo['invite_code'] = SdUser::where('id',$vo['user_id'])->value('invite_code');
            }
        }
        $data = DataExtend::arr2table($data);
    }

    /**
     * 添加代理
     * @menu true
     * @auth true
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException|Exception
     */
    public function add(): void
    {
	    $this->_applyFormToken();
        $this->_form($this->table, 'form');
    }

    /**
     * 编辑代理
     * @auth true
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     * @throws PDOException|Exception
     */
    public function edit(): void
    {
	    $this->_applyFormToken();
        $this->_form($this->table, 'form');
    }

    /**
     * 表单数据处理
     * @param array $data
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    public function _form_filter(&$data)
    {
        if ($this->request->isPost()) {
            if (isset($data['username'])) $data['username'] = strtolower($data['username']);
            // 用户账号重复检查
            if (isset($data['id'])) unset($data['username']);
            elseif (SystemUser::where(['username' => $data['username'], 'is_deleted' => '0'])->count() > 0) {
                $this->error("账号{$data['username']}已经存在，请使用其它账号！");
            }
            if ($this->agent_id == 0) {
                //$data['parent_id'] = 0;
            } else {
                $data['parent_id'] = $this->agent_id;
            }
            if (!isset($data['id']) && $data['parent_id'] > 0) {
                if (!$data['phone']) $this->error('手机号必填');
                if (SdUser::where(['tel' => $data['phone']])->count('id') > 0) {
                    $this->error("手机号 {$data['phone']} 已经存在，请使用其它手机号！");
                }
                if (SdUser::where(['username' => $data['username']])->count('id') > 0) {
                    $this->error("账号 {$data['username']} 已经存在，请使用其它账号！");
                }
            }
            //用户权限处理
            $data['authorize'] = 2;
            /*if (!empty($data['user_id'])) {
                $isAgentSon = Db::name('xy_users')->where('id', $data['user_id'])->value('agent_id');
                if (empty($isAgentSon)) {
                    $this->error("业务员ID {$data['user_id']} 未绑定代理！");
                }
            }*/
        } else {
            $data['user_id'] = !empty($data['user_id']) ? $data['user_id'] : 0;
            $this->agent_list = SystemUser::where('parent_id', 0)
                ->where('user_id', 0)
                ->where('authorize', "2")
                ->field('id,username')
                ->where('is_deleted', 0);
            if ($this->agent_id) $this->agent_list->where('id', $this->agent_id);
            $this->agent_list = $this->agent_list->select();

            $this->is_admin = $this->agent_id == 0;
        }
    }

    public function _form_result(&$result, &$data)
    {
        if ($this->request->isPost()) {
            if ($result !== false) {
                //开户
                if (!isset($data['id']) && $data['parent_id'] > 0) {
                    $data['id'] = $result;
                    //添加用户
                    $res = SdUser::add_users(
                        $data['phone'], $data['username'], '123456', 0,
                        '', '123456', $data['parent_id']);
                    if ($res['code'] == 0) {
                        //添加成功了
                        SdUser::where('id', $res['id'])
                            ->update(['agent_id' => $data['id']]);
                        SystemUser::where('id', $data['id'])
                            ->update(['user_id' => $res['id']]);
                    }
                    sysoplog('添加代理', '新代理ID ' . $data['id']);
                } else {
                    sysoplog('编辑代理', '新数据包 ' . json_encode($data, JSON_UNESCAPED_UNICODE));
                }
            }
        }
        return true;
    }
}