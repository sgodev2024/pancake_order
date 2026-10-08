<?php
namespace Tests\Feature;
use App\Models\Role;
use App\Models\User;
use App\Models\ZaloConnection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;
class ZaloChatBridgeTest extends TestCase {
 use DatabaseTransactions;
 private string $account='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
 private string $other='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
 private string $conversation='cccccccc-cccc-4ccc-8ccc-cccccccccccc';
 protected function setUp():void {
  parent::setUp();
  if(DB::connection()->getDatabaseName()!=='pancake_local')throw new \RuntimeException('Run only against pancake_local.');
  Http::preventStrayRequests();
  ZaloConnection::updateOrCreate(['id'=>1],['base_url'=>'http://zalo-core:8080','api_key'=>'cpa_deadbeef_localKey','enabled'=>true]);
 }
 private function actor(bool $admin=false):User {
  $role=Role::firstOrCreate(['slug'=>$admin?'admin':'zalo-test-staff'],['name'=>'Test']);
  $user=User::factory()->create(['role_id'=>$role->id]);Passport::actingAs($user,['full-access'],'api');return $user;
 }
 private function link():void {DB::table('zalo_conversation_links')->updateOrInsert(['id'=>$this->conversation],['account_id'=>$this->account]);}
 public function test_only_admin_can_read_or_change_connection_and_key_is_encrypted():void {
  $this->actor();$this->getJson('/api/v1/zalo-chat/connection')->assertForbidden();
  $this->actor(true);$this->getJson('/api/v1/zalo-chat/connection')->assertOk()->assertJsonPath('data.has_api_key',true)->assertJsonMissingPath('data.api_key');
  $this->assertNotSame('cpa_deadbeef_localKey',DB::table('zalo_connections')->where('id',1)->value('api_key'));
 }
 public function test_unlisted_destination_is_rejected_without_network_call():void {
  $this->actor(true);
  $this->postJson('/api/v1/zalo-chat/connection/test',['base_url'=>'http://169.254.169.254','api_key'=>'cpa_deadbeef_test'])->assertUnprocessable();Http::assertNothingSent();
 }
 public function test_staff_only_sees_granted_accounts_and_cannot_open_other_account():void {
  $u=$this->actor();DB::table('zalo_account_grants')->insert(['account_id'=>$this->account,'user_id'=>$u->id,'access_level'=>'VIEW']);
  Http::fake(['*'=>Http::response([['id'=>$this->account,'displayName'=>'Visible'],['id'=>$this->other,'displayName'=>'Hidden']])]);
  $this->getJson('/api/v1/zalo-chat/accounts')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$this->account);
  $this->getJson('/api/v1/zalo-chat/conversations?accountId='.$this->other)->assertForbidden();
 }
 public function test_view_grant_can_read_but_cannot_send_and_revocation_takes_effect():void {
  $u=$this->actor();$this->link();DB::table('zalo_account_grants')->insert(['account_id'=>$this->account,'user_id'=>$u->id,'access_level'=>'VIEW']);
  Http::fake(['*'=>Http::response([])]);
  $this->getJson('/api/v1/zalo-chat/conversations/'.$this->conversation.'/messages')->assertOk();
  Http::fake();$this->postJson('/api/v1/zalo-chat/conversations/'.$this->conversation.'/messages',['text'=>'Blocked'])->assertForbidden();Http::assertNothingSent();
  DB::table('zalo_account_grants')->where('user_id',$u->id)->delete();$this->getJson('/api/v1/zalo-chat/conversations/'.$this->conversation.'/messages')->assertForbidden();
 }
 public function test_chat_grant_queues_a_message_without_automatic_retry():void {
  $u=$this->actor();$this->link();DB::table('zalo_account_grants')->insert(['account_id'=>$this->account,'user_id'=>$u->id,'access_level'=>'CHAT']);
  Http::fake(['*'=>Http::response(['id'=>'command-1','status'=>'PENDING'],201)]);
  $this->postJson('/api/v1/zalo-chat/conversations/'.$this->conversation.'/messages',['text'=>'Hello local'])->assertCreated()->assertJsonPath('data.status','PENDING');Http::assertSentCount(1);
 }
 public function test_upstream_auth_failure_does_not_become_pancake_unauthorized_or_leak_key():void {
  $this->actor(true);Http::fake(['*'=>Http::response([],401)]);
  $this->getJson('/api/v1/zalo-chat/accounts')->assertStatus(424)->assertJsonMissingPath('debug');
 }
 public function test_upstream_cannot_insert_conversation_for_different_account():void {
  $this->actor(true);Http::fake(['*'=>Http::response([['id'=>$this->conversation,'accountId'=>$this->other]])]);
  $this->getJson('/api/v1/zalo-chat/conversations?accountId='.$this->account)->assertStatus(502);
  $this->assertFalse(DB::table('zalo_conversation_links')->where('id',$this->conversation)->exists());
 }
}
