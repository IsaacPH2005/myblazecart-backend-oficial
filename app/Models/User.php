<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'estado',
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
    public function generalData()
    {
        return $this->hasOne(GeneralData::class);
    }
    /**
     * Relación 1:1 con Driver
     */
    public function driver()
    {
        return $this->hasOne(Driver::class);
    }
    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }


    // En el modelo User
    public function pagosPendientes()
    {
        return $this->hasMany(PendingPayment::class);
    }
    /**
     * Inversiones realizadas por el usuario
     */
    public function investments()
    {
        return $this->hasMany(Investment::class);
    }
}
