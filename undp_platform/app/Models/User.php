<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable {
        HasRoles::hasRole as private hasRoleFromSpatie;
    }

    protected string $guard_name = 'api';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'country_code',
        'phone_e164',
        'password',
        'role',
        'status',
        'municipality_id',
        'preferred_locale',
        'fcm_token',
        'last_login_at',
        'disabled_at',
        'disabled_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'fcm_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function reportedSubmissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'reporter_id');
    }

    public function validatedSubmissions(): HasMany
    {
        return $this->hasMany(Submission::class, 'validated_by');
    }

    public function uploadedMediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'uploaded_by');
    }

    public function exportTasks(): HasMany
    {
        return $this->hasMany(ExportTask::class);
    }

    public function roleEnum(): UserRole
    {
        return UserRole::from($this->role);
    }

    public function statusEnum(): UserStatus
    {
        return UserStatus::from($this->status);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE->value;
    }

    public function hasRole(UserRole|string|array $role, ?string $guard = null): bool
    {
        $normalizedRole = $role;

        if (is_array($normalizedRole)) {
            $normalizedRole = array_map(static fn ($item) => $item instanceof UserRole ? $item->value : $item, $normalizedRole);
        } elseif ($normalizedRole instanceof UserRole) {
            $normalizedRole = $normalizedRole->value;
        }

        try {
            if ($this->hasRoleFromSpatie($normalizedRole, $guard)) {
                return true;
            }
        } catch (RoleDoesNotExist) {
            // Fall back to role column when role rows are not seeded yet.
        }

        if (is_array($normalizedRole)) {
            return in_array($this->role, $normalizedRole, true);
        }

        return $this->role === $normalizedRole;
    }

    public function permissionNames(): array
    {
        $fromSpatie = $this->getAllPermissions()->pluck('name')->values()->all();

        if (! empty($fromSpatie)) {
            return $fromSpatie;
        }

        return (array) data_get(config('rbac.roles'), $this->role.'.permissions', []);
    }

    public function hasPermission(string $permission): bool
    {
        try {
            if ($this->hasPermissionTo($permission, $this->guard_name)) {
                return true;
            }
        } catch (PermissionDoesNotExist) {
            // Fall back to config-based permissions during bootstrapping.
        }

        foreach ((array) data_get(config('rbac.roles'), $this->role.'.permissions', []) as $allowed) {
            if ($allowed === $permission || Str::is($allowed, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function syncRoleSafely(UserRole|string $role): void
    {
        $roleName = $role instanceof UserRole ? $role->value : $role;

        try {
            $this->syncRoles([$roleName]);
        } catch (RoleDoesNotExist) {
            Role::findOrCreate($roleName, $this->guard_name);
            $this->syncRoles([$roleName]);
        }
    }
}
