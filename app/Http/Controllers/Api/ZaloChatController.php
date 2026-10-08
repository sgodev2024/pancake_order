<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ZaloConnection;
use App\Services\ZaloBridge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
class ZaloChatController extends Controller {
 public function __construct(private ZaloBridge $bridge){}
 private function result(mixed $data,int $status=200){return response()->json(['success'=>true,'data'=>$data],$status);}
 public function configuration(){
  $r=ZaloConnection::find(1);
  return $this->result(['base_url'=>$r?->base_url??'http://zalo-core:8080','enabled'=>$r?->enabled??false,'has_api_key'=>$r!==null,'updated_at'=>$r?->updated_at,'allowed_hosts'=>config('zalo.allowed_hosts')]);
 }
 private function credentials(Request $request):array {
  $input=$request->validate(['base_url'=>'required|string|max:255','api_key'=>'nullable|string|max:512|regex:#^cpa_[a-f0-9]{8}_[A-Za-z0-9+/]+$#']);
  $input['base_url']=$this->bridge->validateUrl($input['base_url']);
  $input['api_key']=$input['api_key']??ZaloConnection::find(1)?->api_key;
  abort_unless($input['api_key'],422,'Cần nhập API key khi cấu hình lần đầu.');return $input;
 }
 public function save(Request $request){
  $input=$this->credentials($request);$enabled=$request->validate(['enabled'=>'required|boolean'])['enabled'];
  if($enabled)$this->bridge->request('GET','accounts',[],$input);
  DB::transaction(function()use($input,$enabled){
   $old=ZaloConnection::find(1);
   if($old&&($old->base_url!==$input['base_url']||$old->api_key!==$input['api_key'])){DB::table('zalo_account_grants')->delete();DB::table('zalo_conversation_links')->delete();}
   ZaloConnection::updateOrCreate(['id'=>1],$input+['enabled'=>$enabled]);
  });
  Log::info('zalo.connection.updated',['actor_id'=>$request->user()->id,'enabled'=>$enabled]);return $this->configuration();
 }
 public function test(Request $r){$a=$this->bridge->request('GET','accounts',[],$this->credentials($r));return $this->result(['connected'=>true,'account_count'=>count($a),'checked_at'=>now()->toIso8601String()]);}
 public function monitoring(){return $this->result($this->bridge->request('GET','monitoring'));}
 public function accounts(Request $r){
  $rows=$this->bridge->request('GET','accounts');
  if(!$r->user()->isAdmin()){
   $grants=DB::table('zalo_account_grants')->where('user_id',$r->user()->id)->pluck('access_level','account_id');
   $rows=array_values(array_filter($rows,fn($row)=>isset($grants[$row['id']])));
   foreach($rows as &$row)$row['accessLevel']=$grants[$row['id']];
  }else{foreach($rows as &$row)$row['accessLevel']='CHAT';}
  return $this->result($rows);
 }
 public function createAccount(Request $r){$input=$r->validate(['displayName'=>'required|string|max:160','externalAccountRef'=>'nullable|string|max:160']);return $this->result($this->bridge->request('POST','accounts',$input),201);}
 private function accountAccess(Request $r,string $accountId,bool $chat=false):void {
  if($r->user()->isAdmin())return;
  $g=DB::table('zalo_account_grants')->where('user_id',$r->user()->id)->where('account_id',$accountId)->value('access_level');
  abort_unless($chat?$g==='CHAT':in_array($g,['VIEW','CHAT']),403,'Bạn chưa được cấp quyền trên tài khoản Zalo này.');
 }
 private function conversationAccess(Request $r,string $id,bool $chat=false):void {
  $a=DB::table('zalo_conversation_links')->where('id',$id)->value('account_id');abort_unless($a,404,'Hãy chọn hội thoại từ danh sách.');$this->accountAccess($r,$a,$chat);
 }
 public function conversations(Request $r){
  $input=$r->validate(['accountId'=>'required|uuid','query'=>'nullable|string|max:160']);$this->accountAccess($r,$input['accountId']);
  $rows=$this->bridge->request('GET','conversations',$input+['limit'=>100]);
  foreach($rows as $row){abort_unless($row['accountId']===$input['accountId'],502,'Dịch vụ trả về hội thoại sai phạm vi.');DB::table('zalo_conversation_links')->updateOrInsert(['id'=>$row['id']],['account_id'=>$row['accountId']]);}
  return $this->result($rows);
 }
 public function messages(Request $r,string $id){$this->conversationAccess($r,$id);$input=$r->validate(['before'=>'nullable|date']);return $this->result($this->bridge->request('GET',"conversations/$id/messages",$input+['limit'=>100]));}
 public function send(Request $r,string $id){
  $this->conversationAccess($r,$id,true);$input=$r->validate(['text'=>'required|string|max:2000']);abort_if(trim($input['text'])==='',422,'Tin nhắn không được để trống.');
  $c=$this->bridge->request('POST',"conversations/$id/messages",$input);
  Log::info('zalo.message.queued',['actor_id'=>$r->user()->id,'conversation_id'=>$id,'command_id'=>$c['id']]);return $this->result($c,201);
 }
 public function read(Request $r,string $id){$this->conversationAccess($r,$id);$this->bridge->request('POST',"conversations/$id/read");return $this->result(null);}
 public function friends(Request $r,string $accountId){$this->accountAccess($r,$accountId);$input=$r->validate(['offset'=>'nullable|integer|min:0']);return $this->result($this->bridge->request('GET',"bridge/accounts/$accountId/friends",$input+['limit'=>50]));}
 public function openFriend(Request $r,string $accountId){
  $this->accountAccess($r,$accountId,true);$input=$r->validate(['friendRef'=>'required|regex:/^[a-f0-9]{64}$/']);$result=$this->bridge->request('POST',"accounts/$accountId/friends/open-conversation",$input);
  DB::table('zalo_conversation_links')->updateOrInsert(['id'=>$result['conversationId']],['account_id'=>$accountId]);return $this->result($result);
 }
 public function grants(string $accountId){
  $rows=$this->bridge->request('GET','accounts');abort_unless(collect($rows)->contains('id',$accountId),404);
  return $this->result(['users'=>User::select('id','name','email')->orderBy('name')->get(),'grants'=>DB::table('zalo_account_grants')->where('account_id',$accountId)->get(['user_id','access_level'])]);
 }
 public function saveGrants(Request $r,string $accountId){
  $input=$r->validate(['grants'=>'present|array','grants.*.user_id'=>'required|integer|distinct|exists:users,id','grants.*.access_level'=>['required',Rule::in(['VIEW','CHAT'])]]);
  $rows=$this->bridge->request('GET','accounts');abort_unless(collect($rows)->contains('id',$accountId),404);
  DB::transaction(function()use($accountId,$input){DB::table('zalo_account_grants')->where('account_id',$accountId)->delete();foreach($input['grants'] as $g)DB::table('zalo_account_grants')->insert($g+['account_id'=>$accountId]);});
  Log::info('zalo.grants.updated',['actor_id'=>$r->user()->id,'account_id'=>$accountId]);return $this->grants($accountId);
 }
}


