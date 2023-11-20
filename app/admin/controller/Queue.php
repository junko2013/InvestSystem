<?php

namespace app\admin\controller;

use app\admin\controller\sd\BaseSdCtrl;
use think\admin\helper\QueryHelper;
use think\admin\model\SystemQueue;
use think\admin\service\AdminService;
use think\admin\service\ProcessService;
use think\admin\service\QueueService;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\exception\HttpResponseException;
use think\admin\Controller;

/**
 * 系统任务管理
 * @class Queue
 * @package app\admin\controller
 */
class Queue extends BaseSdCtrl
{
    /**
     * 系统任务管理
     * @auth true
     * @menu true
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function index()
    {
        SystemQueue::mQuery()->layTable(function () {
            $this->title = '系统任务管理';
            $this->iswin = ProcessService::iswin();
            if ($this->super = AdminService::isSuper()) {
                $this->command = ProcessService::think('xadmin:queue start');
                if (!$this->iswin && !empty($_SERVER['USER'])) {
                    $this->command = "sudo -u {$_SERVER['USER']} {$this->command}";
                }
            }
        }, static function (QueryHelper $query) {
            $query->equal('status')->like('code|title#title,command');
            $query->timeBetween('enter_time,exec_time')->dateBetween('create_at');
        });
    }

    /**
     * 分页数据回调处理
     * @param array $data
     * @param array $result
     * @return void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    protected function _index_page_filter(array $data, array &$result)
    {
        $result['extra'] = ['dos' => 0, 'pre' => 0, 'oks' => 0, 'ers' => 0];
        SystemQueue::mk()->field('status,count(1) count')->group('status')->select()->map(static function ($item) use (&$result) {
            if (intval($item['status']) === 1) $result['extra']['pre'] = $item['count'];
            if (intval($item['status']) === 2) $result['extra']['dos'] = $item['count'];
            if (intval($item['status']) === 3) $result['extra']['oks'] = $item['count'];
            if (intval($item['status']) === 4) $result['extra']['ers'] = $item['count'];
        });
    }

    /**
     * 重启系统任务
     * @auth true
     */
    public function redo()
    {
        try {
            $data = $this->_vali(['code.require' => '任务编号不能为空！']);
            $queue = QueueService::instance()->initialize($data['code'])->reset();
            $queue->progress(1, '>>> 任务重置成功 <<<', 0.00);
            $this->success('任务重置成功！', $queue->code);
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            trace_file($exception);
            $this->error($exception->getMessage());
        }
    }

    /**
     * 清理运行数据
     * @auth true
     */
    public function clean()
    {
        $this->_queue('定时清理系统运行数据', "xadmin:queue clean", 0, [], 0, 3600);
    }

    /**
     * 删除系统任务
     * @auth true
     */
    public function remove()
    {
        SystemQueue::mDelete();
    }
}
