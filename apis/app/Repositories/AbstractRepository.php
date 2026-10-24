<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractRepository
{
    /**
     * @var Model
     */
    protected $model;

    /**
     * 取得所有集合
     *
     * @return Collection|Model[]
     */
    public function all(array $columns = ['*'])
    {
        return $this->model->all($columns);
    }

    /**
     * @param  array  $columns  select 栏位
     * @return Model
     */
    public function first(array $columns = ['*'])
    {
        return $this->model->first($columns);
    }

    /**
     * @param  $column  string 栏位名称
     * @param  $value  string 值
     * @param  array  $columns  select 栏位
     * @return Model
     */
    public function firstBy($column, $value, array $columns = ['*'])
    {
        return $this->model->where($column, $value)->first($columns);
    }

    /**
     * @param  array  $columns  select 栏位
     * @return Collection
     */
    public function get(array $columns = ['*'])
    {
        return $this->model->get($columns);
    }

    /**
     * @param  int  $key_id
     * @return object
     */
    public function collectBy($key_id)
    {
        return $this->model->where('id', $key_id)->first();
    }
}
