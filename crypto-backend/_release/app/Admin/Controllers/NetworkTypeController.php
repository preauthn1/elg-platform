<?php

namespace App\Admin\Controllers;

use App\Models\NetworkType;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class NetworkTypeController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'NetworkType';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new NetworkType());

        $grid->column('id', __('Id'));
        $grid->column('code', __('Code'));
        $grid->is_smart_contract(__('Is smart contract'))->switch([
            'on' => ['value'=>1, 'text'=>'是', 'color'=>'success'],
            'off' => ['value'=>0, 'text'=>'否', 'color'=>'danger']
        ]);
        $grid->status(__('Status'))->switch([
            'on' => ['value'=>1, 'text'=>'启用', 'color'=>'success'],
            'off' => ['value'=>0, 'text'=>'停用', 'color'=>'danger']
        ]);
        $grid->column('created_at', __('Created at'));
        $grid->column('updated_at', __('Updated at'));

        return $grid;
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     * @return Show
     */
    protected function detail($id)
    {
        $show = new Show(NetworkType::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('code', __('Code'));
        $show->field('is_smart_contract', __('Is smart contract'));
        $show->field('status', __('Status'));
        $show->field('created_at', __('Created at'));
        $show->field('updated_at', __('Updated at'));

        return $show;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        $form = new Form(new NetworkType());

        $form->text('code', __('Code'));
        $form->switch('is_smart_contract', __('Is smart contract'))->default(1);
        $form->switch('status', __('Status'))->default(1);

        return $form;
    }
}
