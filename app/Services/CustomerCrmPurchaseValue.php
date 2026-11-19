<?php
namespace App\Services;
class CustomerCrmPurchaseValue {
 public static function sql():string {
  // Local FK is authoritative. Legacy external identity is scoped to the same shop.
  return "(COALESCE((SELECT SUM(pv.cod) FROM orders pv WHERE pv.shop_id=customers.shop_id AND pv.customer_id=customers.id AND pv.status IN (3,16) AND pv.deleted_at IS NULL),0) + COALESCE((SELECT SUM(pl.cod) FROM orders pl WHERE pl.shop_id=customers.shop_id AND pl.customer_id IS NULL AND pl.pancake_customer_id=customers.pancake_customer_id AND pl.status IN (3,16) AND pl.deleted_at IS NULL),0))";
 }
}
