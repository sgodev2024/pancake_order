<?php
namespace App\Services;
use App\Models\ZaloConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
class ZaloBridge {
 public function probe(array $credentials): array {
  $url=$this->validateUrl($credentials['base_url']);
  if(!str_ends_with($url,'/api/v1/zalo-integration')){
   $accounts=$this->request('GET','accounts',[],$credentials);
   return ['account_count'=>count($accounts),'account_readable'=>true];
  }
  try {$response=Http::acceptJson()->withToken($credentials['api_key'])->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects'=>false])->get($url.'/accounts');}
  catch(ConnectionException $e){throw new HttpException(503,'Không liên lạc được dịch vụ Zalo. Kiểm tra URL hoặc trạng thái dịch vụ.');}
  // The onboarding-only key is valid, but cannot read account data yet.
  if($response->status()===403&&$response->json('code')==='INTEGRATION_SCOPE_DENIED')
   return ['account_count'=>0,'account_readable'=>false];
  if(!$response->successful())throw $this->failure($response);
  $accounts=$response->json();
  if(!is_array($accounts)||!array_is_list($accounts))throw new HttpException(502,'Dịch vụ Zalo trả về danh sách account không hợp lệ.');
  return ['account_count'=>count($accounts),'account_readable'=>true];
 }
 public function qr(string $id): string {
  $connection=ZaloConnection::find(1);
  if(!$connection||!$connection->enabled)throw new HttpException(409,'Chưa bật kết nối Zalo.');
  $url=$this->validateUrl($connection->base_url);
  if(!str_ends_with($url,'/api/v1/zalo-integration'))throw new HttpException(422,'QR từ hệ thống bên thứ ba chỉ dùng với Integration API.');
  try {$response=Http::withToken($connection->api_key)->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects'=>false])->get($url.'/connections/'.$id.'/qr');}
  catch(ConnectionException $e){throw new HttpException(503,'Không liên lạc được dịch vụ Zalo.');}
  if(!$response->successful())throw $this->failure($response);
  $bytes=$response->body();
  if(strlen($bytes)<8||strlen($bytes)>1024*1024||substr($bytes,0,8)!=="\x89PNG\r\n\x1a\n")throw new HttpException(502,'QR không phải ảnh PNG hợp lệ.');
  return $bytes;
 }
 private function failure($response): HttpException {
  $s=$response->status();
  $message=match(true){$s===401=>'API key Zalo không hợp lệ, hết hạn hoặc đã bị thu hồi.',$s===403=>'API key chưa có quyền thực hiện thao tác Zalo này.',$s===404=>'Không tìm thấy tài khoản, hội thoại hoặc QR chưa sẵn sàng.',$s===410=>'Phiên QR đã hết hạn.',$s===503&&$response->json('code')==='WORKSPACE_AGENT_NOT_CONFIGURED'=>'Workspace chưa được gán agent QR.',$s>=500=>'Dịch vụ Zalo đang lỗi. Vui lòng thử lại sau.',default=>$response->json('detail')?:'Dịch vụ Zalo từ chối yêu cầu.'};
  return new HttpException(in_array($s,[401,403])?424:($s>=500||($s>=300&&$s<400)?502:$s),$message);
 }
 public function validateUrl(string $url): string {
  $p=parse_url($url);
  if(!$p || !in_array($p['scheme']??'',['http','https']) || !in_array(strtolower($p['host']??''),config('zalo.allowed_hosts'),true) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || !in_array(rtrim($p['path']??'','/'),['','/api/v1','/api/v1/zalo-integration'],true))
   throw ValidationException::withMessages(['base_url'=>'URL phải thuộc host được phép và trỏ tới gốc dịch vụ, /api/v1 hoặc /api/v1/zalo-integration.']);
  return rtrim(preg_replace('#/api/v1/?$#','',$url),'/');
 }
 public function request(string $method,string $path,array $data=[],?array $credentials=null): mixed {
  if($credentials===null){$c=ZaloConnection::find(1);if(!$c||!$c->enabled)throw new HttpException(409,'Chưa bật kết nối Zalo. Admin cần cấu hình kết nối API.');$credentials=['base_url'=>$c->base_url,'api_key'=>$c->api_key];}
  $url=$this->validateUrl($credentials['base_url']);
  $integration=str_ends_with($url,'/api/v1/zalo-integration');
  $headers=[];
  if($integration){
   if($path==='conversations'){$account=$data['accountId'];$path="accounts/$account/conversations";unset($data['accountId']);}
   elseif($path==='monitoring'||$path==='accounts'&&$method==='POST'||str_starts_with($path,'bridge/')||str_contains($path,'/friends/'))throw new HttpException(422,'API tích hợp này chưa hỗ trợ giám sát, tạo tài khoản hoặc danh bạ. Quản lý các chức năng này tại Java Zalo.');
   elseif(str_ends_with($path,'/read'))throw new HttpException(422,'API tích hợp chưa hỗ trợ đánh dấu đã đọc.');
   if($method==='POST'&&(str_ends_with($path,'/messages')||$path==='connections'))$headers['Idempotency-Key']=(string) \Illuminate\Support\Str::uuid();
  }
  $endpoint=$integration?$url.'/'.$path:$url.'/api/v1/zalo-ops/'.$path;
  try {
   // Redirects and automatic retries are disabled: never forward secrets or duplicate a send.
   $response=Http::acceptJson()->withHeaders($headers)->withToken($credentials['api_key'])->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects'=>false])->send($method,$endpoint,$method==='GET'?['query'=>$data]:['json'=>$data]);
  }catch(ConnectionException $e){throw new HttpException(503,'Không liên lạc được dịch vụ Zalo. Kiểm tra URL hoặc trạng thái dịch vụ.');}
  if($integration&&$method==='GET'&&$path==='accounts'&&$response->status()===403&&$response->json('code')==='INTEGRATION_SCOPE_DENIED')return [];
  if(!$response->successful())throw $this->failure($response);
  $result=$response->status()===204?null:$response->json();
  if($integration&&is_array($result)&&isset($result['commandId']))$result['id']=$result['commandId'];
  return $result;
 }
}


