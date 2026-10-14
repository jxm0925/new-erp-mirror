<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder;

class ApprovalFlowTemplate extends MasterModel
{
    protected $table = 'erp_approval_flow_templates';

    public function versions() { return $this->hasMany(ApprovalFlowVersion::class, 'flow_template_id'); }
    public function currentVersion(): HasOne
    {
        // 预加载会单独查询版本表，父表必须在相关子查询内参与匹配。
        // 按每张流程自己的当前指针读取，不能改取可能尚未发布的最新草稿。
        return $this->hasOne(ApprovalFlowVersion::class, 'flow_template_id', 'id')
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('erp_approval_flow_templates as current_flow_template')
                    ->whereColumn('current_flow_template.id', 'erp_approval_flow_versions.flow_template_id')
                    ->whereColumn('current_flow_template.current_version', 'erp_approval_flow_versions.version_no');
            });
    }
}
