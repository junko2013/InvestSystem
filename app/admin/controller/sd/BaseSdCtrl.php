<?php

namespace app\admin\controller\sd;

use think\admin\Controller;
use think\App;
use think\facade\Session;

class BaseSdCtrl extends Controller
{
	protected int $agent_id = 0;//代理id
	protected int $agent_uid = 0;//代理用户账号id
	protected int $adminId = 0;
	protected bool $is_admin;

	public function __construct(App $app)
	{
		parent::__construct($app);
		//初始化代理信息
		$this->agent_id = $this->get_admin_agent_id();
		$this->agent_uid = $this->get_admin_agent_uid();
		$uid = session('user.id');
		$uid = $uid ? intval($uid) : 0;
		$this->adminId = $uid;
		$this->is_admin = $this->agent_id == 0;
		if (!$this->adminId) {
			Session::clear();
			Session::destroy();
			$this->redirect('/');
		}
	}

	/**
	 * 获取当前管理员id
	 * @return int
	 */
	public function get_admin_agent_id(): int
	{
		$user = session('user');
		if (!empty($user) && $user['authorize'] == 2 && !empty($user['nodes'])) {
			return $user['id'];
		}
		return 0;
	}

	/**
	 * 获取当前管理员绑定的前台用户id
	 * @return int
	 */
	public function get_admin_agent_uid()
	{
		$user = session('user');
		if (!empty($user) && $user['authorize'] == 2 && !empty($user['nodes'])) {
			return $user['user_id'];
		}
		return 0;
	}
}