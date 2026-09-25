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

                $data = $setting->data;
                if (is_string($data)) {
                    $decodedData = json_decode($data, true);
                    if (is_array($decodedData)) {
                        $data = $decodedData;
                    }
                }

                return response()->json([
                    "success" => true,
                    "data"    => [
                        "setting" => $data,
                        "image"   => $setting->image ? asset("uploads/images/$setting->image") : null
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
                'code'  => ['required', 'string'],
                'data'  => ['required'],
                'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
                'remove_image' => ['nullable', 'boolean'],
            ], [
                'code.required' => 'Mã cài đặt không được để trống.',
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

            $rawData = $request->input('data');
            $data = is_string($rawData) ? json_decode($rawData, true) : $rawData;

            if (!is_array($data) || (is_string($rawData) && json_last_error() !== JSON_ERROR_NONE)) {
                return response()->json([
                    "success" => false,
                    "message" => "Dữ liệu cài đặt phải là JSON hợp lệ."
                ], 422);
            }

            $setting = Setting::where("code", $request->code)->first();
            if (!$setting) {
                $setting = Setting::create([
                    "code"  => $request->code,
                    "data"  => $data,
                    "image" => $this->storeImage($request->image)
                ]);
            } else {
                $oldImage = public_path("uploads/images/{$setting->image}");
                $shouldRemoveImage = $request->boolean('remove_image') && empty($request->image);
                if (($shouldRemoveImage || !empty($request->image)) && is_file($oldImage)) {
                    unlink($oldImage);
                }
                $setting->update([
                    "data" => $data,
                    "image" => !empty($request->image)
                        ? $this->storeImage($request->image)
                        : ($shouldRemoveImage ? null : $setting->image)
                ]);
            }

            $savedSetting = $setting->fresh();

            return response()->json([
                "success" => true,
                "message" => "Cập nhật thành công",
                "data" => [
                    "setting" => $savedSetting->data,
                    "image" => $savedSetting->image
                        ? asset("uploads/images/{$savedSetting->image}")
                        : null
                ]
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
