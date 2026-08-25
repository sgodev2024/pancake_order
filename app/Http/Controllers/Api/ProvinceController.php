<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminOnlyMiddleware;
use App\Models\Province;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ProvinceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(AdminOnlyMiddleware::class, only: ['index']),
        ];
    }

    public function index()
    {
        return response()->json([
            "success" => true,
            "data"    => Province::all()
        ]);
    }

    public function report(Request $request)
    {
        try {
            $shop = NULL;
            $inputs = $request->only("date_from", "date_to", "province_id", "shop_id");
            $provinces = Province::query();
            if (isset($inputs["shop_id"])) {
                $shop = Shop::select("id", "name", "avatar_url")->whereId($inputs["shop_id"])->first();
            }
            if (isset($inputs["province_id"])) {
                $provinces->where("id", $inputs["province_id"]);
            }
            $provinces->withCount(["orders" => function ($q) use ($inputs) {
                if (isset($inputs["shop_id"])) {
                    $q->where("orders.shop_id", $inputs["shop_id"]);
                }
                if (isset($inputs["date_from"])) {
                    $q->where("created_at", ">=", $inputs["date_from"] . " 00:00:00");
                }
                if (isset($inputs["date_to"])) {
                    $q->where("created_at", "<=", $inputs["date_to"] . " 23:59:59");
                }
            }]);
            $provinces->withSum([
                "orders as total_cod" => function ($q) use ($inputs) {
                    if (isset($inputs["shop_id"])) {
                        $q->where("orders.shop_id", $inputs["shop_id"]);
                    }
                    if (!empty($inputs["date_from"])) {
                        $q->where("created_at", ">=", $inputs["date_from"] . " 00:00:00");
                    }
                    if (!empty($inputs["date_to"])) {
                        $q->where("created_at", "<=", $inputs["date_to"] . " 23:59:59");
                    }
                }
            ], "cod");

            return response()->json([
                "success" => true,
                "data"    => [
                    "provinces" => $provinces->get(),
                    "shop"      => $shop
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => true,
                "message" => $th->getMessage()
            ]);
        }
    }
}
