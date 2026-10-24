<?php

namespace App\Admin\Controllers;

use App\Models\Network;
use App\Models\Currency;
use App\Models\NetworkType;
use Encore\Admin\Controllers\AdminController;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Show;

class NetworkController extends AdminController
{
    /**
     * Title for current resource.
     *
     * @var string
     */
    protected $title = 'Network';

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Network());

        $grid->column('id', __('Id'));
        $grid->currency()->code(__('Currency Code'));
        $grid->type()->code(__('Type Code'));
        $grid->column('address', __('Address'));
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
        $show = new Show(Network::findOrFail($id));

        $show->field('id', __('Id'));
        $show->field('currency_id', __('Currency id'));
        $show->field('type_id', __('Type id'));
        $show->field('address', __('Address'));
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
        $form = new Form(new Network());
        $form->select('currency_id', __('Currency id'))->options(function(){
            return Currency::all()->pluck('code', 'id');
        });
        $form->select('type_id', __('Type id'))->options(function(){
            return NetworkType::all()->pluck('code', 'id');
        });
        $form->text('address', __('Address'));
        $form->switch('status', __('Status'))->default(1);

        return $form;
    }
}
