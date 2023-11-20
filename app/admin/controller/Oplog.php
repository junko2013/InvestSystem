<?php

namespace app\admin\controller;

use app\admin\controller\sd\BaseSdCtrl;
use Ip2Region;
use think\admin\helper\QueryHelper;
use think\admin\model\SystemOplog;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\exception\HttpResponseException;
use think\admin\Controller;

/**
 * 系统日志管理
 * @class Oplog
 * @package app\admin\controller
 */
class Oplog extends BaseSdCtrl
{
    /**
     * 系统日志管理
     * @auth true
     * @menu true
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function index()
    {
        SystemOplog::mQuery()->layTable(function () {
            $this->title = '系统日志管理';
            $columns = SystemOplog::mk()->column('action,username', 'id');
            $this->users = array_unique(array_column($columns, 'username'));
            $this->actions = array_unique(array_column($columns, 'action'));
        }, static function (QueryHelper $query) {
            $query->dateBetween('create_at')->equal('username,action')->like('content,geoip,node');
        });
    }

    /**
     * 列表数据处理
     * @param array $data
     * @throws \Exception
     */
    protected function _index_page_filter(array &$data)
    {
        $region = new Ip2Region();
        foreach ($data as &$vo) try {
            $vo['geoisp'] = $region->simple($vo['geoip']);
        } catch (\Exception $exception) {
            $vo['geoip'] = $exception->getMessage();
        }
    }

    /**
     * 清理系统日志
     * @auth true
     */
    public function clear()
    {
        try {
            SystemOplog::mQuery()->empty();
            sysoplog('系统运维管理', '成功清理所有日志');
            $this->success('日志清理成功！');
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            trace_file($exception);
            $this->error(lang("日志清理失败，%s", [$exception->getMessage()]));
        }
    }

    /**
     * 删除系统日志
     * @auth true
     */
    public function remove()
    {
        SystemOplog::mDelete();
    }
}
