<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class CareNotificationController extends Controller {
 private function query(Request $r){return DB::table('care_user_notifications')->where('user_id',$r->user()->id)->whereIn('shop_id',$r->user()->shops()->pluck('shops.id'));}
 public function index(Request $r){$q=$this->query($r);return response()->json(['success'=>true,'data'=>['items'=>(clone $q)->orderByDesc('id')->limit(50)->get(['id','type','title','body','path','task_count','read_at','created_at']),'unread'=>(clone $q)->whereNull('read_at')->count()]]);}
 public function read(Request $r,$id){$this->query($r)->where('id',$id)->whereNull('read_at')->update(['read_at'=>now()]);return response()->json(['success'=>true]);}
 public function readAll(Request $r){$this->query($r)->whereNull('read_at')->update(['read_at'=>now()]);return response()->json(['success'=>true]);}
 public function templates(Request $r){abort_unless($r->user()->isAdmin(),403);return response()->json(['success'=>true,'data'=>DB::table('care_notification_templates')->orderBy('type')->get()]);}
 public function save(Request $r,$type){abort_unless($r->user()->isAdmin(),403);abort_unless(in_array($type,['assigned','today','overdue'],true),404);$d=$r->validate(['email_enabled'=>'required|boolean','subject'=>'required|string|max:200|not_regex:/[\r\n]/','body'=>'required|string|max:5000']);preg_match_all('/\{\{(.*?)\}\}/',$d['subject'].$d['body'],$m);foreach($m[1] as $key)abort_unless(in_array($key,['employee_name','task_count','date','login_url','work_url'],true),422,'Biến template không hợp lệ.');DB::transaction(function()use($type,$d){$old=DB::table('care_notification_templates')->where('type',$type)->lockForUpdate()->first();$changes=$d+['updated_at'=>now()];if($d['email_enabled']&&!$old->email_enabled)$changes['email_enabled_at']=now();DB::table('care_notification_templates')->where('type',$type)->update($changes);});return $this->templates($r);}
}
