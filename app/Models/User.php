<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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

    /**
     * Get the businesses the user belongs to, with their role.
     *
     * Users deliberately have no customers(), products(), invoices() or expenses()
     * relationships: business data is owned by a business and must be queried
     * from it (see App\Support\CurrentBusiness).
     *
     * @return BelongsToMany<Business, $this, BusinessMembership>
     */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class)
            ->using(BusinessMembership::class)
            ->withPivot('id', 'role')
            ->withTimestamps();
    }
}
