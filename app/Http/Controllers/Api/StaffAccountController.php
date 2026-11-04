<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffAccountController extends Controller
{
 public function update(Request $request, User $user) {
  abort_unless($request->user()?->isAdmin(),403,'Chỉ Admin được khóa hoặc mở khóa tài khoản.');
  $data=$request->validate(['disabled'=>'required|boolean']);
  abort_if($user->getKey()===$request->user()->getKey() || $user->isAdmin(),422,'Không được khóa tài khoản Admin hoặc chính mình.');
  DB::transaction(function()use($user,$data,$request){
   User::whereKey($user->id)->lockForUpdate()->firstOrFail();
   if($data['disabled']){
    DB::table('staff_account_states')->updateOrInsert(['user_id'=>$user->id],['disabled_at'=>now(),'changed_by'=>$request->user()->id]);
    DB::table('oauth_access_tokens')->where('user_id',$user->id)->update(['revoked'=>true]);
   }else{DB::table('staff_account_states')->where('user_id',$user->id)->delete();}
  });
  return response()->json(['success'=>true,'message'=>$data['disabled']?'Đã ngừng hoạt động tài khoản. Lịch sử và phân công được giữ nguyên; cần thu hồi/chuyển giao riêng.':'Đã mở khóa tài khoản. Người dùng có thể đăng nhập lại.']);
 }
}
