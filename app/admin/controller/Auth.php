<?php

namespace app\admin\controller;

use think\admin\Controller;
use think\admin\helper\QueryHelper;
use think\admin\model\SystemAuth;
use think\admin\model\SystemNode;
use think\admin\Plugin;
use think\admin\service\AdminService;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;

/**
 * 系统权限管理
 * @class Auth
 * @package app\admin\controller
 */
class Auth extends Controller
{
    /**
     * 系统权限管理
     * @auth true
     * @menu true
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function index()
    {
        SystemAuth::mQuery()->layTable(function () {
            $this->title = '系统权限管理';
        }, static function (QueryHelper $query) {
            $query->like('title,desc')->equal('status,utype')->dateBetween('create_at');
        });
    }

    /**
     * 添加系统权限
     * @auth true
     */
    public function add()
    {
        SystemAuth::mForm('form');
    }

    /**
     * 编辑系统权限
     * @auth true
     */
    public function edit()
    {
        SystemAuth::mForm('form');
    }

    /**
     * 修改权限状态
     * @auth true
     */
    public function state()
    {
        SystemAuth::mSave($this->_vali([
            'status.in:0,1'  => '状态值范围异常！',
            'status.require' => '状态值不能为空！',
        ]));
    }

    /**
     * 删除系统权限
     * @auth true
     */
    public function remove()
    {
        SystemAuth::mDelete();
    }

    /**
     * 权限配置节点
     * @auth true
     * @throws \ReflectionException
     */
    public function apply()
    {
        $map = $this->_vali(['auth.require#id' => '权限ID不能为空！']);
        if (input('action') === 'get') {
            if ($this->app->isDebug()) AdminService::clear();
            $ztree = AdminService::getTree(SystemNode::mk()->where($map)->column('node'));
            usort($ztree, static function ($a, $b) {
                if (explode('-', $a['node'])[0] !== explode('-', $b['node'])[0]) {
                    if (stripos($a['node'], 'plugin-') === 0) return 1;
                }
                return $a['node'] === $b['node'] ? 0 : ($a['node'] > $b['node'] ? 1 : -1);
            });
            [$ps, $cs] = [Plugin::get(), (array)$this->app->config->get('app.app_names', [])];
            foreach ($ztree as &$n) $n['title'] = lang($cs[$n['node']] ?? (($ps[$n['node']] ?? [])['name'] ?? $n['title']));
            $this->success('获取权限节点成功！', $ztree);
        } elseif (input('action') === 'save') {
            [$post, $data] = [$this->request->post(), []];
            foreach ($post['nodes'] ?? [] as $node) {
                $data[] = ['auth' => $map['auth'], 'node' => $node];
            }
            SystemNode::mk()->where($map)->delete();
            SystemNode::mk()->insertAll($data);
            sysoplog('系统权限管理', "配置系统权限[{$map['auth']}]授权成功");
            $this->success('访问权限修改成功！', 'javascript:history.back()');
        } else {
            SystemAuth::mForm('apply');
        }
    }

    /**
     * 表单后置数据处理
     * @param array $data
     */
    protected function _apply_form_filter(array $data)
    {
        if ($this->request->isGet()) {
            $this->title = "编辑【{$data['title']}】授权";
        }
    }
}
