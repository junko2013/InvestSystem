<?php

namespace app\admin\controller;

use app\admin\controller\sd\BaseSdCtrl;
use app\model\sd\SdUser;
use think\admin\helper\QueryHelper;
use think\admin\model\SystemAuth;
use think\admin\model\SystemBase;
use think\admin\model\SystemUser;
use think\admin\service\AdminService;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\db\Query;
use think\model\Relation;

/**
 * 系统用户管理
 * @class User
 * @package app\admin\controller
 */
class User extends BaseSdCtrl
{
    /**
     * 系统用户管理
     * @auth true
     * @menu true
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function index()
    {
        $this->type = $this->get['type'] ?? 'index';
        SystemUser::mQuery()->layTable(function () {
            $this->title = '系统用户管理';
            $this->bases = SystemBase::items('身份权限');
        }, function (QueryHelper $query) {

            // 加载对应数据列表
            $query->where(['is_deleted' => 0, 'status' => intval($this->type === 'index')]);

            // 关联用户身份资料
            /** @var Relation|Query $query */
            $query->with(['userinfo' => static function ($query) {
                $query->field('code,name,content');
            }]);

            // 数据列表搜索过滤
            $query->equal('status,usertype')->dateBetween('login_at,create_at');
            $query->like('username|nickname#username,contact_phone#phone,contact_mail#mail');
        });
    }

    /**
     * 添加系统用户
     * @auth true
     */
    public function add()
    {
        SystemUser::mForm('form');
    }

    /**
     * 编辑系统用户
     * @auth true
     */
    public function edit()
    {
        SystemUser::mForm('form');
    }

    /**
     * 修改用户密码
     * @auth true
     */
    public function pass()
    {
        $this->_applyFormToken();
        if ($this->request->isGet()) {
            $this->verify = false;
            SystemUser::mForm('pass');
        } else {
            $data = $this->_vali([
                'id.require'                  => '用户ID不能为空！',
                'password.require'            => '登录密码不能为空！',
                'repassword.require'          => '重复密码不能为空！',
                'repassword.confirm:password' => '两次输入的密码不一致！',
            ]);
            $user = SystemUser::mk()->findOrEmpty($data['id']);
            if ($user->isExists() && $user->save(['password' => md5($data['password'])])) {
                sysoplog('系统用户管理', "修改用户[{$data['id']}]密码成功");
                $this->success('密码修改成功，请使用新密码登录！', '');
            } else {
                $this->error('密码修改失败，请稍候再试！');
            }
        }
    }

    /**
     * 表单数据处理
     * @param array $data
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    protected function _form_filter(array &$data)
    {
        if ($this->request->isPost()) {
            // 账号权限绑定处理
            $data['authorize'] = arr2str($data['authorize'] ?? []);
            if (isset($data['id']) && $data['id'] > 0) {
                unset($data['username']);
            } else {
                // 检查账号是否重复
                if (empty($data['username'])) {
                    $this->error('登录账号不能为空！');
                }
                $map = ['username' => $data['username'], 'is_deleted' => 0];
                if (SystemUser::mk()->where($map)->count() > 0) {
                    $this->error("账号已经存在，请使用其它账号！");
                }
				//检查用户账号是否重复
	            if ($this->agent_id == 0) {
		            //$data['parent_id'] = 0;
	            } else {
		            $data['parent_id'] = $this->agent_id;
	            }
	            if (!isset($data['id']) && $data['parent_id'] > 0) {
		            if (!$data['contact_phone']) $this->error('手机号必填');
		            if (SdUser::where(['tel' => $data['contact_phone']])->count('id') > 0) {
			            $this->error("手机号 {$data['contact_phone']} 已经存在，请使用其它手机号！");
		            }
		            if (SdUser::where(['username' => $data['username']])->count('id') > 0) {
			            $this->error("账号 {$data['username']} 已经存在，请使用其它账号！");
		            }
	            }
                // 新添加的用户密码与账号相同
                $data['password'] = md5($data['username']);
            }
        } else {
            // 权限绑定处理
            $data['authorize'] = str2arr($data['authorize'] ?? '');
            // 用户身份数据
            $this->bases = SystemBase::items('身份权限');
            // 用户权限管理
            $this->superName = AdminService::getSuperName();
            $this->authorizes = SystemAuth::items();

	        $data['user_id'] = !empty($data['user_id']) ? $data['user_id'] : 0;
	        $this->agent_list = SystemUser::where('parent_id', 0)
		        ->where('user_id', 0)
		        ->where('usertype', "agent")
		        ->field('id,username')
		        ->where('is_deleted', 0);
	        if ($this->agent_id) $this->agent_list->where('id', $this->agent_id);
	        $this->agent_list = $this->agent_list->select();

	        $this->is_admin = $this->agent_id == 0;
        }
    }

    /**
     * 修改用户状态
     * @auth true
     */
    public function state()
    {
        $this->_checkInput();
        SystemUser::mSave($this->_vali([
            'status.in:0,1'  => '状态值范围异常！',
            'status.require' => '状态值不能为空！',
        ]));
    }

    /**
     * 删除系统用户
     * @auth true
     */
    public function remove()
    {
        $this->_checkInput();
        SystemUser::mDelete();
    }

    /**
     * 检查输入变量
     */
    private function _checkInput()
    {
        if (in_array('10000', str2arr(input('id', '')))) {
            $this->error('系统超级账号禁止删除！');
        }
    }
}
