<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('orders',function(Blueprint $t){$t->index(['customer_id','shop_id','status','deleted_at','cod'],'orders_crm_purchase_fk_idx');$t->index(['pancake_customer_id','shop_id','customer_id','status','deleted_at','cod'],'orders_crm_purchase_legacy_idx');});}
 public function down():void {Schema::table('orders',function(Blueprint $t){$t->dropIndex('orders_crm_purchase_fk_idx');$t->dropIndex('orders_crm_purchase_legacy_idx');});}
};
