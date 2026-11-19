<?php
namespace App\Services;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class StaffDeletionGuard {
 public function check(User $user):void {
  $id=$user->getKey();
  $assignments=DB::table('customer_care_assignments')->where('assignee_user_id',$id);
  abort_if((clone $assignments)->where('status','active')->exists(),409,'Nhân viên còn phân công CSKH đang hoạt động. Vui lòng thu hồi hoặc chuyển giao trước; có thể ngừng hoạt động tài khoản để khóa đăng nhập.');
  abort_if($assignments->exists(),409,'Nhân viên đã có lịch sử phân công CSKH. Vui lòng ngừng hoạt động tài khoản để giữ lịch sử.');
  // Both local user IDs and legacy Pancake IDs occur in business records.
  $ids=array_values(array_unique(array_filter([$id,$user->pancake_user_id],fn($v)=>$v!==null)));
  $tables=['orders'=>['user_creator_id','user_care_id','user_assigning_seller_id'],
   'customer_cares'=>['user_creator_id','user_care_id','user_assigning_seller_id'],
   'activity_logs'=>['actor_user_id','target_user_id'],
   'imported_opportunities'=>['imported_by'],
   'customers'=>['assigned_user_id']];
  foreach($tables as $table=>$columns){
   if(!Schema::hasTable($table))continue;
   foreach($columns as $column)if(Schema::hasColumn($table,$column) && DB::table($table)->whereIn($column,$ids)->exists())
    abort(409,'Nhân viên đã có dữ liệu nghiệp vụ hoặc lịch sử. Vui lòng ngừng hoạt động tài khoản thay vì xóa vĩnh viễn.');
  }
 }
}
