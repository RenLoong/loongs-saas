<?php

declare(strict_types=1);

namespace SaasTest;

use Loongs\Orm\Model\Attribute;
use Loongs\Orm\Model\Builder;
use Loongs\Orm\Model\Model;
use Loongs\Orm\Model\Relations\BelongsTo;
use Loongs\Orm\Model\Relations\BelongsToMany;
use Loongs\Orm\Model\Relations\HasMany;
use Loongs\Orm\Model\Relations\HasManyThrough;
use Loongs\Orm\Model\Relations\HasOne;
use Loongs\Orm\Model\Relations\HasOneThrough;
use Loongs\Orm\Model\Relations\MorphMany;
use Loongs\Orm\Model\Relations\MorphOne;
use Loongs\Orm\Model\Relations\MorphTo;
use Loongs\Orm\Model\Relations\MorphToMany;
use Loongs\Orm\Model\SoftDeletes;

enum Status: string
{
    case Active = 'active';
    case Banned = 'banned';
}

final class User extends Model
{
    use SoftDeletes;

    protected array $fillable = ['name', 'email', 'tenant', 'settings', 'is_admin', 'status', 'score'];

    protected array $hidden = ['email'];

    protected array $appends = ['label'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_admin' => 'bool', 'status' => Status::class, 'score' => 'decimal:2'];
    }

    /** accessor + mutator (Attribute style) */
    protected function name(): Attribute
    {
        return Attribute::make(get: fn (?string $v) => $v === null ? null : ucfirst($v), set: fn (string $v) => strtolower(trim($v)));
    }

    /** classic mutator */
    public function setEmailAttribute(string $v): void
    {
        $this->attributes['email'] = strtolower($v);
    }

    /** classic accessor for an appended attribute */
    public function getLabelAttribute(): string
    {
        return ($this->attributes['name'] ?? '?') . '@' . ($this->attributes['tenant'] ?? '?');
    }

    public function scopeAdmins(Builder $q): void
    {
        $q->where('is_admin', 1);
    }

    public function scopeTenantIs(Builder $q, string $t): void
    {
        $q->where('tenant', $t);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('level')->withTimestamps();
    }

    public function image(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }
}

final class Post extends Model
{
    protected array $fillable = ['user_id', 'title', 'draft'];

    protected function casts(): array
    {
        return ['draft' => 'bool'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('published', static fn (Builder $b) => $b->where('posts.draft', 0));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withPivot('weight');
    }
}

final class Profile extends Model
{
    protected array $guarded = [];

    public bool $timestamps = false;
}

final class Role extends Model
{
    protected array $fillable = ['name'];

    public bool $timestamps = false;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('level');
    }
}

/** Central-database model: declares its own connection; tenant scopes never apply to it. */
final class Plan extends Model
{
    protected string|array|null $connection = 'central';

    protected array $fillable = ['name', 'price'];

    public bool $timestamps = false;
}

final class Mark extends Model
{
    protected array $fillable = ['tenant', 'coro', 'n'];
}

// ---------------------------------------------------------------- polymorphic

final class Video extends Model
{
    protected array $fillable = ['title'];

    public bool $timestamps = false;

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withPivot('weight');
    }
}

final class Comment extends Model
{
    protected array $fillable = ['body'];

    public bool $timestamps = false;

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

final class Image extends Model
{
    protected array $fillable = ['url'];

    public bool $timestamps = false;

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }
}

final class Tag extends Model
{
    protected array $fillable = ['name'];

    public bool $timestamps = false;

    public function posts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable')->withPivot('weight');
    }

    public function videos(): MorphToMany
    {
        return $this->morphedByMany(Video::class, 'taggable');
    }
}

// ---------------------------------------------------------------- through

final class Country extends Model
{
    protected array $fillable = ['name'];

    public bool $timestamps = false;

    /** countries → authors (authors.country_id) → books (books.author_id) */
    public function books(): HasManyThrough
    {
        return $this->hasManyThrough(Book::class, Author::class);
    }
}

final class Author extends Model
{
    use SoftDeletes;

    protected array $fillable = ['country_id', 'name'];

    public bool $timestamps = false;

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}

final class Book extends Model
{
    protected array $fillable = ['author_id', 'title'];

    public bool $timestamps = false;

    /** books.author_id → authors.id → agents.author_ref (all four keys custom) */
    public function agent(): HasOneThrough
    {
        return $this->hasOneThrough(Agent::class, Author::class, 'id', 'author_ref', 'author_id', 'id');
    }
}

final class Agent extends Model
{
    protected array $fillable = ['author_ref', 'name'];

    public bool $timestamps = false;
}

// ---------------------------------------------------------------- events

/** Test log of fired events: [title, event]. Static on purpose (test harness only). */
final class EventLog
{
    /** @var list<array{string, string}> */
    public static array $log = [];

    public static function push(Model $m, string $event): void
    {
        self::$log[] = [(string) $m->getAttribute('title'), $event];
    }

    /** @return list<string> events for one title */
    public static function for(string $title): array
    {
        return array_values(array_map(static fn (array $e): string => $e[1], array_filter(self::$log, static fn (array $e): bool => $e[0] === $title)));
    }
}

final class ArticleObserver
{
    public function saved(Article $a): void
    {
        EventLog::push($a, 'observer:saved');
    }

    public function deleted(Article $a): void
    {
        EventLog::push($a, 'observer:deleted');
    }
}

final class Article extends Model
{
    use SoftDeletes;

    protected array $fillable = ['title', 'status', 'views'];

    protected static function booted(): void
    {
        foreach (self::MODEL_EVENTS as $event) {
            static::registerModelEvent($event, static fn (Article $a) => EventLog::push($a, $event));
        }
        // cancellation rules
        static::saving(static fn (Article $a) => $a->title === 'nosave' ? false : null);
        static::creating(static fn (Article $a) => $a->title === 'blocked' ? false : null);
        static::updating(static fn (Article $a) => $a->title === 'frozen' ? false : null);
        static::deleting(static fn (Article $a) => $a->status === 'locked' ? false : null);
        static::restoring(static fn (Article $a) => $a->status === 'norestore' ? false : null);
        static::forceDeleting(static fn (Article $a) => $a->status === 'keep' ? false : null);
        // a listener that queries: writes an audit row through the model → the model's own tenant
        static::created(static function (Article $a): void {
            $a->audits()->create(['event' => 'created', 'db' => $a->getConnection()->selectOne('select database() as d')['d']]);
        });
        static::observe(ArticleObserver::class);
    }

    public function audits(): MorphMany
    {
        return $this->morphMany(Audit::class, 'auditable');
    }
}

final class Audit extends Model
{
    protected array $fillable = ['event', 'db'];

    public bool $timestamps = false;
}
