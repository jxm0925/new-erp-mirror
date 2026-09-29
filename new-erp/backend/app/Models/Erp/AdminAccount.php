<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Model;

/** 既有管理员/员工账号的只读关联模型，不建立独立人员体系。 */
class AdminAccount extends Model
{
    protected $table = 'erp_legacy_admin_users';
    protected $guarded = ['*'];
    protected $visible = ['legacy_id', 'username', 'nickname', 'status'];
}
