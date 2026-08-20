<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'role',
        'email',
        'password',
        'mobile_number',
        'position',
        'department',
        'address',
        'signature_path',
        'must_change_password',
    ];

    public const ROLE_ADMIN = 'admin';
    public const ROLE_APPROVER = 'approver';

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isApprover(): bool
    {
        return $this->role === self::ROLE_APPROVER;
    }

    /**
     * The employee record this account belongs to, matched by the
     * convention that `username` equals the employee's `employee_code`.
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class, 'employee_code', 'username');
    }

    /**
     * A root-relative URL (not an absolute one built from the static
     * `APP_URL` config) so it resolves correctly regardless of the actual
     * host/port the app is served from — matching how every other image in
     * this app is referenced (e.g. `/images/user/owner.png`).
     */
    public function signatureUrl(): ?string
    {
        return $this->signature_path ? '/storage/'.$this->signature_path : null;
    }

    /**
     * Finds the existing approver account for this employee, or creates one
     * using the established convention: username = password = employee_code.
     * Never promotes/downgrades an existing account's role. A freshly
     * created account is flagged `must_change_password` immediately (not
     * left to be detected only on their first login) since password ===
     * username is true by construction here.
     */
    public static function findOrCreateApprover(Employee $employee): self
    {
        return static::firstWhere('username', $employee->employee_code) ?? static::create([
            'name' => $employee->name,
            'username' => $employee->employee_code,
            'password' => $employee->employee_code,
            'role' => self::ROLE_APPROVER,
            'email' => $employee->email,
            'must_change_password' => true,
        ]);
    }

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
            'must_change_password' => 'boolean',
        ];
    }

}
