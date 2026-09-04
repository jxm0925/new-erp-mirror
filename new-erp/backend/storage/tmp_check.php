<?php
$perms = App\Models\Permission::whereIn('name',['module_learning','menu_courses'])->get(['id','parent_id','name','display_name','type','path','component','sort_order']);
dump($perms->toArray());
$role = App\Models\Role::where('name','dingtalk_main_admin')->with('permissions:id,name,display_name')->first();
dump($role?->permissions->whereIn('name',['module_learning','menu_courses'])->pluck('name')->values()->toArray());
