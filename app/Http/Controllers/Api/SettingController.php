<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function show($code)
    {
        try {
            $setting = Setting::where("code", $code)->first();
            if ($setting) {
                if ($setting->code == "smtp" && !auth("api")->check()) {
                    return response()->json([
                        "success" => false,
                        "message" => "Bạn không có quyền"
                    ]);
                }

                return response()->json([
                    "success" => true,
                    "data"    => [
                        "setting" => $setting->data,
                        "image"   => asset("uploads/images/$setting->image")
                    ]
                ]);
            }
            
            return response()->json([
                "success" => true,
                "data"    => [
                    "setting" => NULL,
                    "image"   => NULL
                ]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'data'  => ['required'],
                'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ], [
                'data.required'  => 'Dữ liệu không được để trống.',
                'image.image'    => 'File phải là định dạng ảnh.',
                'image.mimes'    => 'Chỉ chấp nhận jpg, jpeg, png, webp.',
                'image.max'      => 'Ảnh không được vượt quá 5MB.',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    "success" => false,
                    "message" => "Dữ liệu không hợp lệ",
                    "errors"  => $validator->errors()
                ], 422);
            }
            $setting = Setting::where("code", $request->code)->first();
            if (!$setting) {
                $setting = Setting::create([
                    "code"  => $request->code,
                    "data"  => $request->data,
                    "image" => $this->storeImage($request->image)
                ]);
            } else {
                $oldImage = public_path("uploads/images/{$setting->image}");
                if (!empty($request->image) && file_exists($oldImage)) {
                    unlink($oldImage);
                }
                $setting->update([
                    "data" => $request->data,
                    "image" => !empty($request->image) ? $this->storeImage($request->image) : $setting->image
                ]);
            }

            return response()->json([
                "success" => true,
                "message" => "Cập nhật thành công"
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "success" => false,
                "message" => $th->getMessage()
            ]);
        }
    }

    public function storeImage($image)
    {
        if (!empty($image)) {
            $image->move(public_path("uploads/images"), $image->getClientOriginalName());

            return $image->getClientOriginalName();
        }

        return NULL;
    }
}
