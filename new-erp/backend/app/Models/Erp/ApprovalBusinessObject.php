<?php

namespace App\Models\Erp;

class ApprovalBusinessObject extends MasterModel
{
    protected $table = 'erp_approval_business_objects';
    protected $casts = ['display_fields' => 'array', 'search_fields' => 'array', 'enabled' => 'boolean'];
    public function fields() { return $this->hasMany(ApprovalBusinessObjectField::class, 'business_object_id')->orderBy('sort'); }
    public function events() { return $this->hasMany(ApprovalBusinessEvent::class, 'business_object_id'); }
    public function actions() { return $this->hasMany(ApprovalBusinessAction::class, 'business_object_id'); }
}
