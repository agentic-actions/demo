<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

/**
 * One hosted visitor's copy of the demo: the visitor, their sample teammates and the teams they share, all stamped
 * with this sandbox's id and deleted together once it expires.
 *
 * @property int $id
 * @property int|null $visitor_id
 * @property string|null $ip_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property-read User|null $visitor
 */
#[Fillable(['visitor_id', 'ip_hash', 'expires_at'])]
class DemoSandbox extends Model
{
    /**
     * The name of the "updated at" column: a sandbox is never updated.
     *
     * @var string|null
     */
    const UPDATED_AT = null;

    /**
     * The person who started the sandbox, signed in as its Acme owner.
     *
     * @return BelongsTo<User, $this>
     */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'visitor_id');
    }

    /**
     * Everyone in the sandbox: the visitor and the sample teammates.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The sandbox's teams, the ones its people add included.
     *
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * The signed link that signs the visitor back in, from any browser, until the sandbox ends.
     */
    public function resumeUrl(): string
    {
        return URL::temporarySignedRoute('demo.resume', $this->expires_at, ['sandbox' => $this->id]);
    }

    /**
     * Whether the sandbox's time is up. Its people are signed out from then on (EndExpiredSandbox), and the prune
     * deletes it a few minutes later.
     */
    public function hasExpired(): bool
    {
        return ! $this->expires_at->isFuture();
    }

    /**
     * How a visitor's address is kept: an HMAC keyed with the app key, which counts the sandboxes one address started
     * without storing the address.
     */
    public static function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /**
     * Sandboxes that have not expired yet.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
