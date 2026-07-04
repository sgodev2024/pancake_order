<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'name',
        'email',
        'password',
        'pancake_user_id',
        'fb_id',
        'phone_number',
        'pancake_full_data',
        'avatar_url',
        'is_first_login'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'pancake_full_data'  => 'array'
    ];

    // Đảm bảo cứ tạo user từ hệ thống thì auto có pancake_user_id nếu pancake_user_id = NULL
    protected static function booted(): void
    {
        static::created(function (User $user) {
            if (empty($user->pancake_user_id)) {
                $user->updateQuietly(['pancake_user_id' => $user->id]);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function shops()
    {
        return $this->belongsToMany(Shop::class, ShopUser::class)->withPivot('is_manager');
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, "user_creator_id", "pancake_user_id");
    }

    public function isAdmin(): bool
    {
        return in_array($this->role?->slug, ['admin']);
    }

    public function isManagerSale(): bool
    {
        return in_array($this->role?->slug, ['manager-sale']);
    }

    public function isStaffSale(): bool
    {
        return in_array($this->role?->slug, ['staff-sale']);
    }

    public function isManagerCskh(): bool
    {
        return in_array($this->role?->slug, ['manager-cskh']);
    }

    public function isStaffCskh(): bool
    {
        return in_array($this->role?->slug, ['staff-cskh']);
    }

    public function customerCares()
    {
        return $this->belongsToMany(
            CustomerCare::class, // bảng liên kết
            "customer_assigneds", // bảng trung gian
            "pancake_user_id", // khóa ngoại của model hiện ở bảng trung gian
            "customer_care_id", // khóa ngoại của model liên kết ở bảng trung gian
            "pancake_user_id", // khóa chỉnh của model hiện tại
            "id" // khóa chính của model liên kết
        );
    }
}
