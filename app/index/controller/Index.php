<?php

namespace app\index\controller;

use think\admin\Controller;

class Index extends Controller
{
    public function index()
    {
        $this->redirect(sysuri('admin/login/index'));
    }
}
